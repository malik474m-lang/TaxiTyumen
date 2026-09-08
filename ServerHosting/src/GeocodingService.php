<?php
// Серверный геокодинг для РФ: DaData + OpenCage (OSM) + HTTP Геокодер Яндекс Карт.
// Поиск и reverse geocode; все ключи хранятся только на сервере.
declare(strict_types=1);

final class GeocodingService
{
    private const DADATA_SUGGEST = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';
    private const DADATA_GEOLOCATE = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/geolocate/address';
    private const YANDEX_GEOCODER = 'https://geocode-maps.yandex.ru/1.x/';
    private const OPENCAGE_GEOCODE = 'https://api.opencagedata.com/geocode/v1/json';

    public static function search(\PDO $db, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];
        $svc = ServiceSettings::get($db);
        $city = (string) $svc['city_name'];
        $region = (string) $svc['region_name'];
        $results = [];

        // DaData — точные российские адреса и ФИАС
        if (api_key('dadata') !== '') {
            $body = json_encode([
                'query' => $query,
                'count' => 7,
                'locations' => [['region' => $region, 'city' => $city], ['region' => $region]],
                'restrict_value' => false,
            ], JSON_UNESCAPED_UNICODE);
            [$code, $raw, $ms] = self::request(self::DADATA_SUGGEST, 'POST', $body, [
                'Authorization: Token ' . api_key('dadata'),
                'Content-Type: application/json',
                'Accept: application/json',
            ]);
            $json = json_decode($raw, true);
            if ($code >= 200 && $code < 300 && is_array($json)) {
                foreach ($json['suggestions'] ?? [] as $s) {
                    $lat = (float) ($s['data']['geo_lat'] ?? 0);
                    $lng = (float) ($s['data']['geo_lon'] ?? 0);
                    if (!$lat || !$lng) continue;
                    $results[] = [
                        'displayName' => $s['value'] ?? '',
                        'fullAddress' => $s['unrestricted_value'] ?? $s['value'] ?? '',
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'source' => 'dadata',
                    ];
                }
            }
            self::log($db, 'dadata', 'suggest', $query,
                $code >= 200 && $code < 300 ? 'success' : 'failed', $code, $raw, $ms);
        }

        // OpenCage Data (OSM) — независимый резервный геокодер:
        // подхватывает адреса, которых нет в DaData/ФИАС
        $queryLower = mb_strtolower($query);
        if (count($results) < 3 && api_key('opencage') !== '') {
            $searchQuery = str_contains($queryLower, mb_strtolower($city))
                || str_contains($queryLower, mb_strtolower($region))
                ? $query
                : $query . ', ' . $city . ', ' . $region;
            $params = [
                'q' => $searchQuery,
                'countrycode' => 'ru',
                'language' => 'ru',
                'limit' => 7,
                'no_annotations' => 1,
                'proximity' => $svc['center_longitude'] . ',' . $svc['center_latitude'],
                'bounds' => ($svc['center_longitude'] - 0.9) . ',' . ($svc['center_latitude'] - 0.6) . ','
                    . ($svc['center_longitude'] + 0.9) . ',' . ($svc['center_latitude'] + 0.6),
            ];
            $items = self::openCageRequest($db, $params, 'search', $query);
            $results = self::mergeUnique($results, $items);
        }

        // Яндекс HTTP Геокодер — fallback и адреса, которых нет в DaData
        if (count($results) < 3 && api_key('yandex_maps') !== '') {
            $searchQuery = str_contains($queryLower, mb_strtolower($city))
                || str_contains($queryLower, mb_strtolower($region))
                ? $query
                : $query . ', ' . $city . ', ' . $region;

            $url = self::YANDEX_GEOCODER . '?' . http_build_query([
                'apikey' => api_key('yandex_maps'),
                'geocode' => $searchQuery,
                'format' => 'json',
                'lang' => 'ru_RU',
                'results' => 7,
                'll' => $svc['center_longitude'] . ',' . $svc['center_latitude'],
                'spn' => '2.2,1.8',
                'rspn' => 1,
            ]);
            [$code, $raw, $ms] = self::request($url, 'GET', null, [
                'User-Agent: TaxiService/1.0',
                'Accept: application/json',
            ]);
            $items = self::parseYandex($raw);
            $results = self::mergeUnique($results, $items);
            self::log($db, 'yandex-geocoder', 'search', $query,
                $code >= 200 && $code < 300 ? 'success' : 'failed', $code, $raw, $ms);
        }

        return array_slice($results, 0, 7);
    }

    public static function reverse(\PDO $db, float $lat, float $lng): array
    {
        // DaData geolocate — сначала
        if (api_key('dadata') !== '') {
            $body = json_encode(['lat' => $lat, 'lon' => $lng, 'radius_meters' => 100, 'count' => 1]);
            [$code, $raw, $ms] = self::request(self::DADATA_GEOLOCATE, 'POST', $body, [
                'Authorization: Token ' . api_key('dadata'),
                'Content-Type: application/json',
            ]);
            $json = json_decode($raw, true);
            $s = $json['suggestions'][0] ?? null;
            self::log($db, 'dadata', 'reverse', "$lat,$lng",
                $s ? 'success' : 'failed', $code, $raw, $ms);
            if ($s) {
                return [
                    'displayName' => $s['value'] ?? '',
                    'fullAddress' => $s['unrestricted_value'] ?? $s['value'] ?? '',
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'source' => 'dadata',
                ];
            }
        }

        // OpenCage — резервный reverse (OSM), пул ключей с автопереключением
        if (api_key('opencage') !== '') {
            $items = self::openCageRequest($db, [
                'q' => $lat . ',' . $lng,
                'language' => 'ru',
                'limit' => 1,
                'no_annotations' => 1,
            ], 'reverse', "$lat,$lng");
            if ($items) return $items[0];
        }

        // Яндекс — reverse fallback
        if (api_key('yandex_maps') !== '') {
            $url = self::YANDEX_GEOCODER . '?' . http_build_query([
                'apikey' => api_key('yandex_maps'),
                'geocode' => $lng . ',' . $lat,
                'format' => 'json',
                'lang' => 'ru_RU',
                'results' => 1,
                'kind' => 'house',
            ]);
            [$code, $raw, $ms] = self::request($url, 'GET', null, [
                'User-Agent: TaxiService/1.0',
                'Accept: application/json',
            ]);
            $items = self::parseYandex($raw);
            self::log($db, 'yandex-geocoder', 'reverse', "$lat,$lng",
                $items ? 'success' : 'failed', $code, $raw, $ms);
            if ($items) return $items[0];
        }

        return [
            'displayName' => sprintf('%.4f, %.4f', $lat, $lng),
            'fullAddress' => '',
            'latitude' => $lat,
            'longitude' => $lng,
            'source' => 'coordinates',
        ];
    }

    public static function check(\PDO $db): array
    {
        $svc = ServiceSettings::get($db);
        $items = self::search($db, (string) $svc['city_name']);
        return [
            'configured' => api_key('dadata') !== '' || api_key('opencage') !== '' || api_key('yandex_maps') !== '',
            'ok' => count($items) > 0,
            'results' => count($items),
            'sources' => array_values(array_unique(array_column($items, 'source'))),
            'message' => count($items) > 0
                ? 'Геокодинг РФ доступен'
                : 'Добавьте ключ DaData, OpenCage или Яндекс в админке → «API-ключи»',
        ];
    }

    /**
     * Запрос к OpenCage по пулу ключей: при исчерпании суточной квоты (402)
     * или превышении частоты (429) ключ временно исключается и запрос
     * автоматически повторяется следующим ключом аккаунта.
     */
    private static function openCageRequest(\PDO $db, array $params, string $action, string $summary): array
    {
        $keys = KeyPool::available($db, 'opencage', api_key('opencage'));
        foreach ($keys as $key) {
            $url = self::OPENCAGE_GEOCODE . '?' . http_build_query($params + ['key' => $key]);
            [$code, $raw, $ms] = self::request($url, 'GET', null, [
                'User-Agent: TaxiService/1.0',
                'Accept: application/json',
            ]);
            $tail = KeyPool::tail($key);

            // 402 — суточная квота исчерпана: OpenCage сбрасывает счётчик в полночь UTC
            if ($code === 402) {
                KeyPool::block($db, 'opencage', $key, KeyPool::secondsUntilUtcMidnight(), 'quota_exceeded');
                self::log($db, 'opencage', $action, $summary . ' · ключ ' . $tail . ': квота исчерпана, переключение',
                    'failed', $code, $raw, $ms);
                continue;
            }
            // 429 — слишком часто: короткая пауза для этого ключа
            if ($code === 429) {
                KeyPool::block($db, 'opencage', $key, 120, 'rate_limited');
                self::log($db, 'opencage', $action, $summary . ' · ключ ' . $tail . ': лимит частоты, переключение',
                    'failed', $code, $raw, $ms);
                continue;
            }
            // 401/403 — ключ неверен или заблокирован в кабинете: исключаем надолго
            if ($code === 401 || $code === 403) {
                KeyPool::block($db, 'opencage', $key, 3600, 'invalid_key');
                self::log($db, 'opencage', $action, $summary . ' · ключ ' . $tail . ': отклонён сервисом',
                    'failed', $code, $raw, $ms);
                continue;
            }

            $items = ($code >= 200 && $code < 300) ? self::parseOpenCage($raw) : [];
            if ($code >= 200 && $code < 300) {
                KeyPool::success($db, 'opencage', $key);
            }
            self::log($db, 'opencage', $action, $summary . ' · ключ ' . $tail,
                $code >= 200 && $code < 300 ? 'success' : 'failed', $code, $raw, $ms);
            return $items;
        }
        return [];
    }

    private static function parseOpenCage(string $raw): array
    {
        $json = json_decode($raw, true);
        $result = [];
        foreach ($json['results'] ?? [] as $r) {
            $lat = (float) ($r['geometry']['lat'] ?? 0);
            $lng = (float) ($r['geometry']['lng'] ?? 0);
            if (!$lat || !$lng) continue;
            $name = (string) ($r['formatted'] ?? '');
            $result[] = [
                'displayName' => $name,
                'fullAddress' => $name,
                'latitude' => $lat,
                'longitude' => $lng,
                'source' => 'opencage',
            ];
        }
        return $result;
    }

    /** Слияние результатов провайдеров без координатных дублей (~100 м). */
    private static function mergeUnique(array $results, array $items): array
    {
        foreach ($items as $item) {
            $duplicate = false;
            foreach ($results as $current) {
                if (abs($current['latitude'] - $item['latitude']) < 0.001
                    && abs($current['longitude'] - $item['longitude']) < 0.001) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) $results[] = $item;
        }
        return $results;
    }

    private static function parseYandex(string $raw): array
    {
        $json = json_decode($raw, true);
        $members = $json['response']['GeoObjectCollection']['featureMember'] ?? [];
        $result = [];
        foreach ($members as $member) {
            $geo = $member['GeoObject'] ?? null;
            $pos = trim((string) ($geo['Point']['pos'] ?? ''));
            $parts = preg_split('/\s+/', $pos);
            if (!$geo || count($parts) < 2) continue;
            $lng = (float) $parts[0];
            $lat = (float) $parts[1];
            if (!$lat || !$lng) continue;
            $meta = $geo['metaDataProperty']['GeocoderMetaData'] ?? [];
            $text = (string) ($meta['text'] ?? $geo['name'] ?? '');
            $result[] = [
                'displayName' => $text,
                'fullAddress' => $text,
                'latitude' => $lat,
                'longitude' => $lng,
                'source' => 'yandex',
            ];
        }
        return $result;
    }

    private static function request(string $url, string $method, ?string $body, array $headers): array
    {
        $started = microtime(true);
        $ctx = stream_context_create(['http' => [
            'timeout' => 8,
            'method' => $method,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body ?? '',
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $code = (int) $m[1];
        }
        return [$code, $raw !== false ? $raw : 'Ошибка соединения',
            (int) round((microtime(true) - $started) * 1000)];
    }

    private static function log(
        \PDO $db, string $service, string $action, string $summary,
        string $status, int $code, string $raw, int $ms
    ): void {
        try {
            $db->prepare(
                'INSERT INTO service_call_logs
                 (service,action,request_summary,status,http_code,response_body,duration_ms)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $service, $action, mb_substr($summary, 0, 500), $status,
                $code, mb_substr($raw, 0, 5000), $ms,
            ]);
        } catch (\Throwable) {
        }
    }
}
