<?php
// Серверный геокодинг для РФ: DaData + HTTP Геокодер Яндекс Карт.
// Поиск и reverse geocode; все ключи хранятся только на сервере.
declare(strict_types=1);

final class GeocodingService
{
    private const DADATA_SUGGEST = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';
    private const DADATA_GEOLOCATE = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/geolocate/address';
    private const YANDEX_GEOCODER = 'https://geocode-maps.yandex.ru/1.x/';

    public static function search(\PDO $db, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];
        $svc = ServiceSettings::get($db);
        $city = (string) $svc['city_name'];
        $region = (string) $svc['region_name'];
        $results = [];

        // DaData — точные российские адреса и ФИАС
        if (DADATA_API_KEY !== '') {
            $body = json_encode([
                'query' => $query,
                'count' => 7,
                'locations' => [['region' => $region, 'city' => $city], ['region' => $region]],
                'restrict_value' => false,
            ], JSON_UNESCAPED_UNICODE);
            [$code, $raw, $ms] = self::request(self::DADATA_SUGGEST, 'POST', $body, [
                'Authorization: Token ' . DADATA_API_KEY,
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

        // Яндекс HTTP Геокодер — fallback и адреса, которых нет в DaData
        if (count($results) < 3 && YANDEX_MAPS_API_KEY !== '') {
            $queryLower = mb_strtolower($query);
            $searchQuery = str_contains($queryLower, mb_strtolower($city))
                || str_contains($queryLower, mb_strtolower($region))
                ? $query
                : $query . ', ' . $city . ', ' . $region;

            $url = self::YANDEX_GEOCODER . '?' . http_build_query([
                'apikey' => YANDEX_MAPS_API_KEY,
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
            self::log($db, 'yandex-geocoder', 'search', $query,
                $code >= 200 && $code < 300 ? 'success' : 'failed', $code, $raw, $ms);
        }

        return array_slice($results, 0, 7);
    }

    public static function reverse(\PDO $db, float $lat, float $lng): array
    {
        // DaData geolocate — сначала
        if (DADATA_API_KEY !== '') {
            $body = json_encode(['lat' => $lat, 'lon' => $lng, 'radius_meters' => 100, 'count' => 1]);
            [$code, $raw, $ms] = self::request(self::DADATA_GEOLOCATE, 'POST', $body, [
                'Authorization: Token ' . DADATA_API_KEY,
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

        // Яндекс — reverse fallback
        if (YANDEX_MAPS_API_KEY !== '') {
            $url = self::YANDEX_GEOCODER . '?' . http_build_query([
                'apikey' => YANDEX_MAPS_API_KEY,
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
            'configured' => DADATA_API_KEY !== '' || YANDEX_MAPS_API_KEY !== '',
            'ok' => count($items) > 0,
            'results' => count($items),
            'sources' => array_values(array_unique(array_column($items, 'source'))),
            'message' => count($items) > 0
                ? 'Геокодинг РФ доступен'
                : 'Настройте DADATA_API_KEY или YANDEX_MAPS_API_KEY',
        ];
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
