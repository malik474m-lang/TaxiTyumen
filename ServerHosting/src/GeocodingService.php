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
    // Photon (OpenStreetMap) специально предназначен для автодополнения.
    // OpenCage прямо не поддерживает autosuggest и на короткие улицы возвращал
    // один нерелевантный населённый пункт («Перевалово») для любого запроса.
    private const PHOTON_SEARCH = 'https://photon.komoot.io/api/';

    public static function search(\PDO $db, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];
        $svc = ServiceSettings::get($db);
        $city = (string) $svc['city_name'];
        $region = (string) $svc['region_name'];
        $results = [];

        // Порядок и состав источников задаёт админка → «Геокодинг».
        // Первый активный провайдер является основным.
        foreach (GeoProviders::active($db) as $provider) {
            if (count($results) >= 7) break;

            $items = match ($provider) {
                'dadata'   => self::searchDaData($db, $query, $city, $region, $svc),
                'photon'   => self::searchPhoton($db, $query, $svc),
                'yandex'   => self::searchYandex($db, $query, $city, $region, $svc),
                'opencage' => self::searchOpenCage($db, $query, $city, $region, $svc),
                'tomtom'   => TomTom::search($db, $query, $svc),
                default    => [],
            };
            $results = self::mergeUnique($results, $items);
        }

        return array_slice($results, 0, 7);
    }

    /** DaData: официальный реестр адресов РФ. */
    private static function searchDaData(
        \PDO $db, string $query, string $city, string $region, array $svc
    ): array {
        $token = self::dadataToken();
        if ($token === '') return [];

        // locations жёстко обрезал выдачу и мог вернуть пусто при отличии
        // названия региона/города в настройках от справочника DaData.
        // locations_boost только поднимает Тюмень, но не скрывает дальние адреса.
        $body = json_encode([
            'query' => $query,
            'count' => 10,
            'locations_boost' => [['city' => $city], ['region' => $region]],
        ], JSON_UNESCAPED_UNICODE);
        [$code, $raw, $ms] = self::request(self::DADATA_SUGGEST, 'POST', $body, [
            'Authorization: Token ' . $token,
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ]);

        $json = json_decode($raw, true);
        $items = [];
        // Один набор Photon на весь запрос; используем только если у конкретной
        // подсказки DaData отсутствуют собственные координаты.
        $coordinateFallbacks = null;
        if ($code >= 200 && $code < 300 && is_array($json)) {
            foreach ($json['suggestions'] ?? [] as $s) {
                $value = trim((string) ($s['value'] ?? ''));
                if ($value === '') continue;

                $lat = (float) ($s['data']['geo_lat'] ?? 0);
                $lng = (float) ($s['data']['geo_lon'] ?? 0);

                // DaData документирует: координаты есть не у всех подсказок
                // (особенно улиц без номера). Адрес всё равно валиден и должен
                // показываться оператору, а координаты уточним через Photon.
                if (!$lat || !$lng) {
                    $coordinateFallbacks ??= self::searchPhotonRaw($query, $svc);
                    $fallback = self::bestCoordinateMatch($value, $coordinateFallbacks);
                    if ($fallback !== null) {
                        $lat = (float) $fallback['latitude'];
                        $lng = (float) $fallback['longitude'];
                    }
                }

                $items[] = [
                    'displayName' => $value,
                    'fullAddress' => $s['unrestricted_value'] ?? $value,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'source' => 'dadata',
                    'hasCoordinates' => $lat != 0.0 && $lng != 0.0,
                ];
            }
        }
        self::log($db, 'dadata', 'suggest', $query,
            $items ? 'success' : 'failed', $code, $raw, $ms);
        return $items;
    }

    /**
     * В админку часто вставляют «Token xxxxx» или целый заголовок
     * «Authorization: Token xxxxx». Убираем префикс, иначе получалось
     * Authorization: Token Token xxxxx и DaData отвечала 401.
     */
    private static function dadataToken(): string
    {
        $token = trim(api_key('dadata'), " \t\n\r\0\x0B\"'");
        $token = preg_replace(
            '/^(?:authorization\s*:\s*)?(?:token|bearer)\s+/i', '', $token
        ) ?? $token;
        return trim($token);
    }

    /** Photon без журналирования — для координат подсказки DaData. */
    private static function searchPhotonRaw(string $query, array $svc): array
    {
        $url = self::PHOTON_SEARCH . '?' . http_build_query([
            'q' => $query,
            'limit' => 5,
            'lat' => (float) $svc['center_latitude'],
            'lon' => (float) $svc['center_longitude'],
        ]);
        [$code, $raw] = self::request($url, 'GET', null, [
            'User-Agent: TaxiTyumen/1.0 (+https://taxi.event72.ru)',
            'Accept: application/json',
        ]);
        return $code >= 200 && $code < 300 ? self::parsePhoton($raw) : [];
    }

    /** Лучшее текстовое совпадение для уточнения координат. */
    private static function bestCoordinateMatch(string $address, array $items): ?array
    {
        if (!$items) return null;
        $normalize = static function (string $value): array {
            $value = mb_strtolower($value);
            $value = preg_replace('/[^а-яёa-z0-9]+/u', ' ', $value) ?? $value;
            $stop = ['г','ул','д','дом','обл','область','россия','рф'];
            return array_values(array_filter(
                preg_split('/\s+/u', trim($value)) ?: [],
                fn(string $word) => mb_strlen($word) >= 2 && !in_array($word, $stop, true)
            ));
        };

        $wanted = $normalize($address);
        $best = null;
        $bestScore = -1;
        foreach ($items as $item) {
            $words = $normalize((string) ($item['displayName'] ?? ''));
            $score = count(array_intersect($wanted, $words));
            if ($score > $bestScore) {
                $best = $item;
                $bestScore = $score;
            }
        }
        return $bestScore > 0 ? $best : ($items[0] ?? null);
    }

    /** Photon/OSM: автодополнение без ключа. */
    private static function searchPhoton(\PDO $db, string $query, array $svc): array
    {
        $url = self::PHOTON_SEARCH . '?' . http_build_query([
            'q' => $query,
            'limit' => 7,
            'lat' => (float) $svc['center_latitude'],
            'lon' => (float) $svc['center_longitude'],
        ]);
        [$code, $raw, $ms] = self::request($url, 'GET', null, [
            'User-Agent: TaxiTyumen/1.0 (+https://taxi.event72.ru)',
            'Accept: application/json',
        ]);
        $items = self::parsePhoton($raw);
        self::log($db, 'photon', 'suggest', $query,
            $items ? 'success' : 'failed', $code, $raw, $ms);
        return $items;
    }

    /** Яндекс HTTP Геокодер. */
    private static function searchYandex(
        \PDO $db, string $query, string $city, string $region, array $svc
    ): array {
        if (api_key('yandex_maps') === '') return [];
        $queryLower = mb_strtolower($query);
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
        self::log($db, 'yandex-geocoder', 'search', $query,
            $items ? 'success' : 'failed', $code, $raw, $ms);
        return $items;
    }

    /** OpenCage: геокодер, не предназначенный для автодополнения. */
    private static function searchOpenCage(
        \PDO $db, string $query, string $city, string $region, array $svc
    ): array {
        if (api_key('opencage') === '') return [];
        $queryLower = mb_strtolower($query);
        $searchQuery = str_contains($queryLower, mb_strtolower($city))
            || str_contains($queryLower, mb_strtolower($region))
            ? $query
            : $query . ', ' . $city . ', ' . $region;

        return self::openCageRequest($db, [
            'q' => $searchQuery,
            'countrycode' => 'ru',
            'language' => 'ru',
            'limit' => 7,
            'no_annotations' => 1,
            'proximity' => $svc['center_latitude'] . ',' . $svc['center_longitude'],
            'bounds' => ($svc['center_longitude'] - 0.9) . ',' . ($svc['center_latitude'] - 0.6) . ','
                . ($svc['center_longitude'] + 0.9) . ',' . ($svc['center_latitude'] + 0.6),
        ], 'search', $query);
    }

    /**
     * Поиск БЕЗ ограничения рамкой города: адреса в области, пригороде и
     * соседних населённых пунктах (заказ «дальше зоны») иначе не находятся,
     * и координаты ошибочно подменялись центром города.
     */
    public static function searchWide(\PDO $db, string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) return [];
        $results = [];

        if (GeoProviders::isActive($db, 'dadata')) {
            $body = json_encode(['query' => $query, 'count' => 5], JSON_UNESCAPED_UNICODE);
            [$code, $raw, $ms] = self::request(self::DADATA_SUGGEST, 'POST', $body, [
                'Authorization: Token ' . self::dadataToken(),
                'Content-Type: application/json',
                'Accept: application/json',
            ]);
            $json = json_decode($raw, true);
            foreach ($json['suggestions'] ?? [] as $s) {
                $lat = (float) ($s['data']['geo_lat'] ?? 0);
                $lng = (float) ($s['data']['geo_lon'] ?? 0);
                if (!$lat || !$lng) continue;
                $results[] = [
                    'displayName' => $s['value'] ?? '',
                    'fullAddress' => $s['unrestricted_value'] ?? $s['value'] ?? '',
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'source' => 'dadata-wide',
                ];
            }
            self::log($db, 'dadata', 'search-wide', $query,
                $results ? 'success' : 'failed', $code, $raw, $ms);
        }

        if (!$results && GeoProviders::isActive($db, 'photon')) {
            $url = self::PHOTON_SEARCH . '?' . http_build_query(['q' => $query, 'limit' => 5]);
            [$code, $raw, $ms] = self::request($url, 'GET', null, [
                'User-Agent: TaxiTyumen/1.0 (+https://taxi.event72.ru)',
                'Accept: application/json',
            ]);
            $results = self::mergeUnique($results, self::parsePhoton($raw));
            self::log($db, 'photon', 'search-wide', $query,
                $results ? 'success' : 'failed', $code, $raw, $ms);
        }

        if (!$results && GeoProviders::isActive($db, 'opencage')) {
            $url = self::OPENCAGE_GEOCODE . '?' . http_build_query([
                'key' => api_key('opencage'),
                'q' => $query,
                'countrycode' => 'ru',
                'language' => 'ru',
                'limit' => 5,
                'no_annotations' => 1,
            ]);
            [$code, $raw, $ms] = self::request($url, 'GET', null, [
                'User-Agent: TaxiService/1.0',
                'Accept: application/json',
            ]);
            $results = self::mergeUnique($results, self::parseOpenCage($raw));
            self::log($db, 'opencage', 'search-wide', $query,
                $results ? 'success' : 'failed', $code, $raw, $ms);
        }

        if (!$results && GeoProviders::isActive($db, 'yandex')) {
            // rspn=0: без обрезания результатов рамкой города
            $url = self::YANDEX_GEOCODER . '?' . http_build_query([
                'apikey' => api_key('yandex_maps'),
                'geocode' => $query,
                'format' => 'json',
                'lang' => 'ru_RU',
                'results' => 5,
            ]);
            [$code, $raw, $ms] = self::request($url, 'GET', null, [
                'User-Agent: TaxiService/1.0',
                'Accept: application/json',
            ]);
            $results = self::mergeUnique($results, self::parseYandex($raw));
            self::log($db, 'yandex-geocoder', 'search-wide', $query,
                $results ? 'success' : 'failed', $code, $raw, $ms);
        }

        return $results;
    }

    public static function reverse(\PDO $db, float $lat, float $lng): array
    {
        // Порядок обратного геокодинга тоже подчиняется настройкам админки
        foreach (GeoProviders::active($db) as $provider) {
            $item = match ($provider) {
                'dadata'   => self::reverseDaData($db, $lat, $lng),
                'photon'   => self::reversePhoton($db, $lat, $lng),
                'yandex'   => self::reverseYandex($db, $lat, $lng),
                'opencage' => self::reverseOpenCage($db, $lat, $lng),
                'tomtom'   => TomTom::reverse($db, $lat, $lng),
                default    => null,
            };
            if ($item !== null) return $item;
        }

        return [
            'displayName' => sprintf('%.4f, %.4f', $lat, $lng),
            'fullAddress' => '',
            'latitude' => $lat,
            'longitude' => $lng,
            'source' => 'coordinates',
        ];
    }

    private static function reverseDaData(\PDO $db, float $lat, float $lng): ?array
    {
        if (api_key('dadata') === '') return null;
        $body = json_encode(['lat' => $lat, 'lon' => $lng, 'radius_meters' => 100, 'count' => 1]);
        [$code, $raw, $ms] = self::request(self::DADATA_GEOLOCATE, 'POST', $body, [
            'Authorization: Token ' . self::dadataToken(),
            'Content-Type: application/json',
        ]);
        $json = json_decode($raw, true);
        $s = $json['suggestions'][0] ?? null;
        self::log($db, 'dadata', 'reverse', "$lat,$lng",
            $s ? 'success' : 'failed', $code, $raw, $ms);
        if (!$s) return null;
        return [
            'displayName' => $s['value'] ?? '',
            'fullAddress' => $s['unrestricted_value'] ?? $s['value'] ?? '',
            'latitude' => $lat,
            'longitude' => $lng,
            'source' => 'dadata',
        ];
    }

    private static function reversePhoton(\PDO $db, float $lat, float $lng): ?array
    {
        $url = 'https://photon.komoot.io/reverse?' . http_build_query([
            'lat' => $lat, 'lon' => $lng, 'limit' => 1,
        ]);
        [$code, $raw, $ms] = self::request($url, 'GET', null, [
            'User-Agent: TaxiTyumen/1.0 (+https://taxi.event72.ru)',
            'Accept: application/json',
        ]);
        $items = self::parsePhoton($raw);
        self::log($db, 'photon', 'reverse', "$lat,$lng",
            $items ? 'success' : 'failed', $code, $raw, $ms);
        return $items[0] ?? null;
    }

    private static function reverseYandex(\PDO $db, float $lat, float $lng): ?array
    {
        if (api_key('yandex_maps') === '') return null;
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
        return $items[0] ?? null;
    }

    private static function reverseOpenCage(\PDO $db, float $lat, float $lng): ?array
    {
        if (api_key('opencage') === '') return null;
        $items = self::openCageRequest($db, [
            'q' => $lat . ',' . $lng,
            'language' => 'ru',
            'limit' => 1,
            'no_annotations' => 1,
        ], 'reverse', "$lat,$lng");
        return $items[0] ?? null;
    }

    /**
     * Диагностика одного провайдера для админки: HTTP-код, количество
     * подсказок и текст ошибки. Особенно важно для DaData — 403 означает
     * неверный ключ, неподтверждённую почту, отключённую функцию или лимит.
     */
    public static function diagnoseProvider(\PDO $db, string $provider, string $query): array
    {
        $query = trim($query) ?: 'Республики 52';
        $svc = ServiceSettings::get($db);
        $started = microtime(true);

        if ($provider === 'dadata') {
            $token = self::dadataToken();
            if ($token === '') {
                return ['ok' => false, 'code' => 0, 'count' => 0,
                    'withCoordinates' => 0, 'message' => 'Ключ DaData не задан'];
            }
            $body = json_encode([
                'query' => $query,
                'count' => 10,
                'locations_boost' => [
                    ['city' => (string) $svc['city_name']],
                    ['region' => (string) $svc['region_name']],
                ],
            ], JSON_UNESCAPED_UNICODE);
            [$code, $raw, $ms] = self::request(self::DADATA_SUGGEST, 'POST', $body, [
                'Authorization: Token ' . $token,
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ]);
            $json = json_decode($raw, true);
            $suggestions = is_array($json) ? ($json['suggestions'] ?? []) : [];
            $withCoordinates = 0;
            foreach ($suggestions as $s) {
                if (!empty($s['data']['geo_lat']) && !empty($s['data']['geo_lon'])) {
                    $withCoordinates++;
                }
            }

            $message = match ($code) {
                200 => $suggestions
                    ? 'DaData ответила; подсказки получены'
                    : 'DaData ответила 200, но не нашла адрес по этому запросу',
                401 => 'DaData: API-ключ отсутствует или передан неверно',
                403 => 'DaData: ключ отклонён. Проверьте подтверждение почты, '
                    . 'доступ к SUGGESTIONS и суточный лимит в кабинете',
                429 => 'DaData: превышена частота запросов; подождите минуту',
                0 => 'Нет соединения с DaData: проверьте cURL/исходящий HTTPS',
                default => 'DaData вернула HTTP ' . $code,
            };
            if (is_array($json) && !empty($json['message'])) {
                $message .= ' · ' . (string) $json['message'];
            } elseif ($code !== 200 && trim($raw) !== '') {
                $message .= ' · ' . mb_substr(strip_tags($raw), 0, 250);
            }

            return [
                'ok' => $code === 200 && count($suggestions) > 0,
                'code' => $code,
                'count' => count($suggestions),
                'withCoordinates' => $withCoordinates,
                'message' => $message,
                'durationMs' => $ms,
                'sample' => array_values(array_filter(array_map(
                    fn(array $s) => (string) ($s['value'] ?? ''),
                    array_slice($suggestions, 0, 3)
                ))),
            ];
        }

        $items = match ($provider) {
            'photon' => self::searchPhoton($db, $query, $svc),
            'yandex' => self::searchYandex(
                $db, $query, (string) $svc['city_name'], (string) $svc['region_name'], $svc
            ),
            'opencage' => self::searchOpenCage(
                $db, $query, (string) $svc['city_name'], (string) $svc['region_name'], $svc
            ),
            default => [],
        };
        return [
            'ok' => count($items) > 0,
            'code' => null,
            'count' => count($items),
            'withCoordinates' => count(array_filter(
                $items, fn(array $i) => !empty($i['latitude']) && !empty($i['longitude'])
            )),
            'message' => $items ? 'Провайдер работает' : 'Провайдер не вернул подсказки',
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
            'sample' => array_column(array_slice($items, 0, 3), 'displayName'),
        ];
    }

    public static function check(\PDO $db): array
    {
        $svc = ServiceSettings::get($db);
        $items = self::search($db, (string) $svc['city_name']);
        return [
            'configured' => count(GeoProviders::active($db)) > 0,
            'ok' => count($items) > 0,
            'results' => count($items),
            'sources' => array_values(array_unique(array_column($items, 'source'))),
            'providers' => GeoProviders::active($db),
            'primary' => GeoProviders::primary($db),
            'message' => count($items) > 0
                ? 'Геокодинг доступен · основной: ' . (GeoProviders::primary($db) ?? '—')
                : 'Ни один провайдер не ответил — проверьте раздел «Геокодинг»',
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

    private static function parsePhoton(string $raw): array
    {
        $json = json_decode($raw, true);
        $result = [];
        foreach ($json['features'] ?? [] as $feature) {
            $coords = $feature['geometry']['coordinates'] ?? [];
            if (count($coords) < 2) continue;
            $lng = (float) $coords[0];
            $lat = (float) $coords[1];
            if (!$lat || !$lng) continue;

            $p = $feature['properties'] ?? [];
            $parts = [];
            $street = trim((string) ($p['street'] ?? ''));
            $house = trim((string) ($p['housenumber'] ?? ''));
            $name = trim((string) ($p['name'] ?? ''));
            if ($street !== '') {
                $parts[] = $street . ($house !== '' ? ', ' . $house : '');
            } elseif ($name !== '') {
                $parts[] = $name;
            }
            foreach (['city', 'district', 'county', 'state'] as $key) {
                $value = trim((string) ($p[$key] ?? ''));
                if ($value !== '' && !in_array($value, $parts, true)) $parts[] = $value;
            }
            if (!$parts) continue;
            $display = implode(', ', $parts);
            $result[] = [
                'displayName' => $display,
                'fullAddress' => $display,
                'latitude' => $lat,
                'longitude' => $lng,
                'source' => 'photon',
            ];
        }
        return $result;
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

    /**
     * HTTP-запрос. Приоритет — cURL: на shared-хостингах file_get_contents
     * для внешних URL обычно запрещён (allow_url_fopen=off), из-за чего
     * геокодинг молча не работал и адреса не находились.
     */
    private static function request(string $url, string $method, ?string $body, array $headers): array
    {
        $started = microtime(true);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_USERAGENT => 'TaxiTyumen/1.0 (+https://taxi.event72.ru)',
            ];
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $body ?? '';
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($raw !== false) {
                return [$code, $raw, (int) round((microtime(true) - $started) * 1000)];
            }
            if (!ini_get('allow_url_fopen')) {
                return [0, 'cURL: ' . $error, (int) round((microtime(true) - $started) * 1000)];
            }
        }

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
