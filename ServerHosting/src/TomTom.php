<?php
// ═══════════════════════════════════════════════════════════════════════════
// Единый шлюз к сервисам TomTom (кабинет MyTomTom / developer.tomtom.com).
//
// Каждый сервис включается и выключается отдельно в админке («TomTom»),
// состояние хранится в таблице tomtom_services. Ключ — общий, из раздела
// «API-ключи» (или константа TOMTOM_API_KEY). Выключенный сервис не
// вызывается вообще: система работает на прежних источниках (OSRM, DaData,
// Photon, Яндекс, OpenCage, тайлы OSM).
//
// Бесплатный тариф TomTom: 50 000 тайловых + 2 500 нетайловых запросов
// в сутки. Нетайловые вызовы считаются в tomtom_usage — расход виден
// в админке, и при исчерпании дневного лимита сервис сам уходит в паузу
// до полуночи UTC (как пул ключей OpenCage).
// ═══════════════════════════════════════════════════════════════════════════
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class TomTom
{
    /** Имя ключа в разделе «API-ключи» (общий для всех сервисов TomTom). */
    public const KEY_NAME = 'tomtom_traffic';

    public const BASE = 'https://api.tomtom.com';

    /** Дневной лимит бесплатного тарифа на нетайловые запросы. */
    public const FREE_DAILY_API = 2500;
    /** Дневной лимит на тайлы (расходуется в приложениях, считается примерно). */
    public const FREE_DAILY_TILES = 50000;

    /**
     * Реестр сервисов.
     *  kind: tile — растровый слой (URL отдаётся приложениям),
     *        api  — серверный REST-вызов.
     *  default: включён ли сервис сразу после появления ключа.
     */
    public const REGISTRY = [
        'traffic_flow' => [
            'label' => 'Traffic Flow — пробки на карте',
            'hint' => 'Слой загруженности дорог в приложении водителя: цвет линии показывает скорость потока.',
            'kind' => 'tile',
            'default' => true,
        ],
        'traffic_incidents' => [
            'label' => 'Traffic Incidents — ДТП и перекрытия',
            'hint' => 'Слой дорожных происшествий: аварии, ремонт, перекрытия улиц. Дополняет слой пробок.',
            'kind' => 'tile',
            'default' => true,
        ],
        'map_tiles' => [
            'label' => 'Map Display — базовая карта TomTom',
            'hint' => 'Альтернатива тайлам OpenStreetMap. Включайте, если нужна фирменная карта TomTom; '
                . 'офлайн-кеш города работает только с OSM.',
            'kind' => 'tile',
            'default' => false,
        ],
        'routing' => [
            'label' => 'Routing — маршрут с учётом пробок',
            'hint' => 'Маршрут и время в пути по реальной дорожной обстановке (точнее OSRM). '
                . 'Используется приложением водителя и расчётом цены; при сбое — автоматический откат на OSRM.',
            'kind' => 'api',
            'default' => true,
        ],
        'search' => [
            'label' => 'Search — подсказки адресов',
            'hint' => 'Поиск адресов и объектов. Подключается в цепочку геокодинга («Геокодинг» → провайдер TomTom).',
            'kind' => 'api',
            'default' => false,
        ],
        'reverse_geocode' => [
            'label' => 'Reverse Geocoding — адрес по координатам',
            'hint' => 'Определение адреса точки на карте. Работает в той же цепочке провайдеров геокодинга.',
            'kind' => 'api',
            'default' => false,
        ],
        'matrix' => [
            'label' => 'Matrix Routing — время подачи для водителей',
            'hint' => 'Матрица «водители × заказ»: реальное время подачи с учётом пробок вместо расстояния по прямой.',
            'kind' => 'api',
            'default' => false,
        ],
        'snap_to_roads' => [
            'label' => 'Snap to Roads — привязка GPS-трека к дорогам',
            'hint' => 'Сглаживание GPS-трека водителя: километраж считается по дорогам, а не по «скачущим» точкам.',
            'kind' => 'api',
            'default' => false,
        ],
        'reachable_range' => [
            'label' => 'Reachable Range — зоны доступности',
            'hint' => 'Изохроны: куда водитель доедет за N минут. Полезно для зон подачи и аналитики.',
            'kind' => 'api',
            'default' => false,
        ],
        'static_image' => [
            'label' => 'Static Image — картинка карты',
            'hint' => 'Статичное изображение карты для админки и отчётов (без интерактива и JS).',
            'kind' => 'tile',
            'default' => false,
        ],
    ];

    private static ?array $cache = null;

    /** Последняя ошибка вызова TomTom — показываем её в админке как есть. */
    private static string $lastError = '';

    // ── Хранилище состояния ──────────────────────────────────────────────

    public static function ensureTables(\PDO $db): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS tomtom_services (
                service_key VARCHAR(40) PRIMARY KEY,
                is_enabled  TINYINT(1) NOT NULL DEFAULT 0,
                updated_at  DATETIME NULL,
                updated_by  CHAR(36) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS tomtom_usage (
                usage_date  DATE NOT NULL,
                service_key VARCHAR(40) NOT NULL,
                kind        VARCHAR(10) NOT NULL DEFAULT 'api',
                counter     INT NOT NULL DEFAULT 0,
                PRIMARY KEY (usage_date, service_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $seed = $db->prepare(
            'INSERT IGNORE INTO tomtom_services (service_key, is_enabled) VALUES (?,?)'
        );
        foreach (self::REGISTRY as $key => $meta) {
            $seed->execute([$key, !empty($meta['default']) ? 1 : 0]);
        }
    }

    public static function apiKey(): string
    {
        $raw = function_exists('api_key') ? api_key(self::KEY_NAME) : '';
        // В админку ключ попадает ручной вставкой: срезаем кавычки, пробелы,
        // а также типовые «пространства» пасты — «key=XXX» с портала разработчика
        // или целиком вставленный URL тайла (и DaData лечилась так же).
        $key = trim($raw, " \t\n\r\0\x0B\"'");
        if (preg_match('/[?&]?key=([A-Za-z0-9_\-]{10,})/i', $key, $m)) {
            $key = $m[1];
        } elseif (preg_match('/^(?:api[\s_\-]?key|tomtom|consumer)[:\s=]+(.+)$/i', $key, $m)) {
            $key = trim($m[1]);
        }
        return preg_replace('/\s+/', '', $key) ?? $key;
    }

    public static function hasKey(): bool
    {
        return self::apiKey() !== '';
    }

    /** Состояние всех сервисов: реестр + флаги из БД + расход за сутки. */
    public static function all(\PDO $db): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::ensureTables($db);

        $rows = [];
        try {
            foreach ($db->query('SELECT * FROM tomtom_services')->fetchAll() as $r) {
                $rows[(string) $r['service_key']] = $r;
            }
        } catch (\Throwable) {
        }
        $usage = self::usageToday($db);
        $hasKey = self::hasKey();

        $out = [];
        foreach (self::REGISTRY as $key => $meta) {
            $enabled = isset($rows[$key])
                ? (int) $rows[$key]['is_enabled'] === 1
                : !empty($meta['default']);
            $out[$key] = $meta + [
                'key' => $key,
                'enabled' => $enabled,
                'hasKey' => $hasKey,
                'active' => $enabled && $hasKey,
                'usedToday' => (int) ($usage[$key] ?? 0),
                'updatedAt' => $rows[$key]['updated_at'] ?? null,
            ];
        }
        self::$cache = $out;
        return $out;
    }

    /** Сервис включён, ключ задан и дневной лимит не исчерпан. */
    public static function enabled(\PDO $db, string $service): bool
    {
        $all = self::all($db);
        if (empty($all[$service]['active'])) {
            return false;
        }
        if (($all[$service]['kind'] ?? 'api') === 'api'
            && self::apiUsedToday($db) >= self::FREE_DAILY_API) {
            return false;   // дневная квота исчерпана — молча уходим на резерв
        }
        return true;
    }

    public static function setEnabled(\PDO $db, string $service, bool $enabled, ?string $userId = null): void
    {
        if (!isset(self::REGISTRY[$service])) {
            throw new \RuntimeException('Неизвестный сервис TomTom: ' . $service);
        }
        self::ensureTables($db);
        $db->prepare(
            'INSERT INTO tomtom_services (service_key, is_enabled, updated_at, updated_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),
               updated_at=VALUES(updated_at), updated_by=VALUES(updated_by)'
        )->execute([$service, $enabled ? 1 : 0, Db::utcNow(), $userId]);
        self::$cache = null;
    }

    // ── Учёт расхода ─────────────────────────────────────────────────────

    public static function usageToday(\PDO $db): array
    {
        $out = [];
        try {
            self::ensureTables($db);
            $stmt = $db->prepare('SELECT service_key, counter FROM tomtom_usage WHERE usage_date = ?');
            $stmt->execute([gmdate('Y-m-d')]);
            foreach ($stmt->fetchAll() as $r) {
                $out[(string) $r['service_key']] = (int) $r['counter'];
            }
        } catch (\Throwable) {
        }
        return $out;
    }

    /** Сумма нетайловых вызовов за сутки (лимит FREE_DAILY_API). */
    public static function apiUsedToday(\PDO $db): int
    {
        $usage = self::usageToday($db);
        $sum = 0;
        foreach (self::REGISTRY as $key => $meta) {
            if (($meta['kind'] ?? 'api') === 'api') {
                $sum += (int) ($usage[$key] ?? 0);
            }
        }
        return $sum;
    }

    private static function countUsage(\PDO $db, string $service): void
    {
        try {
            $kind = self::REGISTRY[$service]['kind'] ?? 'api';
            $db->prepare(
                'INSERT INTO tomtom_usage (usage_date, service_key, kind, counter)
                 VALUES (?,?,?,1)
                 ON DUPLICATE KEY UPDATE counter = counter + 1'
            )->execute([gmdate('Y-m-d'), $service, $kind]);
        } catch (\Throwable) {
        }
    }

    // ── Тайловые слои (URL отдаётся приложениям через api/map-config.php) ─

    /** URL шаблона тайлов включённого слоя или null. */
    public static function tileUrl(\PDO $db, string $service): ?string
    {
        if (!self::enabled($db, $service)) {
            return null;
        }
        $key = rawurlencode(self::apiKey());
        return match ($service) {
            // Стиль relative0 (рекомендован TomTom): показывает загруженность
            // относительно свободного потока. Параметр thickness этот стиль НЕ
            // поддерживает (400 «thickness supported only for styles:
            // absolute, relative…») — поэтому не передаём его вовсе.
            'traffic_flow' => self::BASE
                . '/traffic/map/4/tile/flow/relative0/{z}/{x}/{y}.png?key=' . $key,
            'traffic_incidents' => self::BASE
                . '/traffic/map/4/tile/incidents/s3/{z}/{x}/{y}.png?key=' . $key,
            'map_tiles' => self::BASE
                . '/map/1/tile/basic/main/{z}/{x}/{y}.png?key=' . $key,
            default => null,
        };
    }

    /** Ссылка на статичное изображение карты (Static Image API). */
    public static function staticImageUrl(
        \PDO $db, float $lat, float $lng, int $zoom = 13, int $width = 640, int $height = 400
    ): ?string {
        if (!self::enabled($db, 'static_image')) {
            return null;
        }
        return self::BASE . '/map/1/staticimage?' . http_build_query([
            'key' => self::apiKey(),
            'center' => sprintf('%F,%F', $lng, $lat),
            'zoom' => max(0, min(22, $zoom)),
            'width' => max(1, min(8192, $width)),
            'height' => max(1, min(8192, $height)),
            'format' => 'png',
            'layer' => 'basic',
            'style' => 'main',
        ]);
    }

    // ── Маршрутизация с учётом пробок ────────────────────────────────────

    /**
     * Маршрут через точки с живой дорожной обстановкой.
     * Формат ответа совместим с Taxi::getRouteBundleThrough (geometry/steps),
     * чтобы приложение водителя и озвучка работали без изменений.
     *
     * @param array<int,array{0:float,1:float}> $points [[lat,lng], ...]
     * @return array{geometry:array,distanceKm:float,durationMinutes:int,trafficDelayMinutes:int,steps:array}|null
     */
    public static function route(\PDO $db, array $points, bool $withSteps = true): ?array
    {
        if (!self::enabled($db, 'routing') || count($points) < 2) {
            return null;
        }
        $locations = [];
        foreach ($points as $p) {
            $locations[] = sprintf('%F,%F', (float) $p[0], (float) $p[1]);
        }
        $query = [
            'key' => self::apiKey(),
            'traffic' => 'true',                 // главный смысл: живые пробки
            'travelMode' => 'car',
            'routeType' => 'fastest',
            'computeTravelTimeFor' => 'all',     // отдельно время без пробок
            'sectionType' => 'traffic',
        ];
        if ($withSteps) {
            $query['instructionsType'] = 'text';
            $query['language'] = 'ru-RU';
        }
        $url = self::BASE . '/routing/1/calculateRoute/'
            . implode(':', $locations) . '/json?' . http_build_query($query);

        [$code, $raw, $ms] = self::request($url);
        self::countUsage($db, 'routing');
        $json = json_decode($raw, true);
        $route = $json['routes'][0] ?? null;
        self::log($db, 'routing', count($points) . ' точек',
            $route ? 'success' : 'failed', $code, $raw, $ms);
        if (!$route) {
            self::$lastError = self::explainError($code, $raw);
            return null;
        }

        $geometry = [];
        foreach (($route['legs'] ?? []) as $leg) {
            foreach (($leg['points'] ?? []) as $pt) {
                if (isset($pt['latitude'], $pt['longitude'])) {
                    $geometry[] = [(float) $pt['latitude'], (float) $pt['longitude']];
                }
            }
        }
        if (count($geometry) < 2) {
            return null;
        }

        $summary = $route['summary'] ?? [];
        return [
            'geometry' => $geometry,
            'distanceKm' => round(((float) ($summary['lengthInMeters'] ?? 0)) / 1000, 1),
            'durationMinutes' => (int) ceil(((float) ($summary['travelTimeInSeconds'] ?? 0)) / 60),
            'trafficDelayMinutes' => (int) round(((float) ($summary['trafficDelayInSeconds'] ?? 0)) / 60),
            'steps' => $withSteps ? self::mapInstructions($route['guidance']['instructions'] ?? []) : [],
        ];
    }

    /** Инструкции TomTom → формат манёвров OSRM (его понимает озвучка). */
    private static function mapInstructions(array $instructions): array
    {
        $steps = [];
        foreach ($instructions as $ins) {
            $point = $ins['point'] ?? null;
            if (!isset($point['latitude'], $point['longitude'])) {
                continue;
            }
            [$type, $modifier] = self::mapManeuver(
                (string) ($ins['maneuver'] ?? ''),
                (string) ($ins['instructionType'] ?? '')
            );
            $steps[] = [
                'loc' => [(float) $point['latitude'], (float) $point['longitude']],
                'type' => $type,
                'modifier' => $modifier,
                'exit' => isset($ins['roundaboutExitNumber'])
                    ? (int) $ins['roundaboutExitNumber'] : null,
                'name' => (string) ($ins['street'] ?? ''),
                'distance' => (float) ($ins['routeOffsetInMeters'] ?? 0),
            ];
        }
        return $steps;
    }

    /** @return array{0:string,1:string} [type, modifier] в терминах OSRM */
    private static function mapManeuver(string $maneuver, string $instructionType): array
    {
        $m = strtoupper($maneuver);
        if ($instructionType === 'LOCATION_DEPARTURE' || $m === 'DEPART') return ['depart', ''];
        if ($instructionType === 'LOCATION_ARRIVAL' || str_starts_with($m, 'ARRIVE')) {
            return ['arrive', match (true) {
                str_contains($m, 'LEFT') => 'left',
                str_contains($m, 'RIGHT') => 'right',
                default => '',
            }];
        }
        if (str_contains($m, 'ROUNDABOUT')) return ['roundabout', ''];
        if (str_contains($m, 'UTURN')) return ['turn', 'uturn'];

        $modifier = match (true) {
            str_contains($m, 'SHARP_LEFT') => 'sharp left',
            str_contains($m, 'SHARP_RIGHT') => 'sharp right',
            str_contains($m, 'BEAR_LEFT'), str_contains($m, 'SLIGHT_LEFT') => 'slight left',
            str_contains($m, 'BEAR_RIGHT'), str_contains($m, 'SLIGHT_RIGHT') => 'slight right',
            str_contains($m, 'LEFT') => 'left',
            str_contains($m, 'RIGHT') => 'right',
            str_contains($m, 'STRAIGHT') => 'straight',
            default => '',
        };
        if (str_contains($m, 'KEEP')) return ['fork', $modifier];
        if (str_contains($m, 'MERGE')) return ['merge', $modifier];
        if (str_contains($m, 'MOTORWAY') || str_contains($m, 'FREEWAY')) {
            return [str_contains($m, 'EXIT') ? 'off ramp' : 'on ramp', $modifier];
        }
        return [$modifier === '' ? 'continue' : 'turn', $modifier];
    }

    // ── Матрица времени подачи ───────────────────────────────────────────

    /**
     * Время подачи от каждого водителя к точке заказа (секунды).
     * @param array<int,array{0:float,1:float}> $origins
     * @return array<int,int|null>|null null — сервис выключен или ошибка
     */
    public static function travelTimes(\PDO $db, array $origins, float $destLat, float $destLng): ?array
    {
        if (!self::enabled($db, 'matrix') || $origins === []) {
            return null;
        }
        // Бесплатный тариф: держим матрицу небольшой
        $origins = array_slice($origins, 0, 25);
        $payload = [
            'origins' => array_map(
                fn($p) => ['point' => ['latitude' => (float) $p[0], 'longitude' => (float) $p[1]]],
                $origins
            ),
            'destinations' => [
                ['point' => ['latitude' => $destLat, 'longitude' => $destLng]],
            ],
        ];
        $url = self::BASE . '/routing/matrix/2?' . http_build_query([
            'key' => self::apiKey(),
            'routeType' => 'fastest',
            'traffic' => 'live',
            'travelMode' => 'car',
        ]);
        [$code, $raw, $ms] = self::request($url, 'POST', json_encode($payload), [
            'Content-Type: application/json',
        ]);
        self::countUsage($db, 'matrix');
        $json = json_decode($raw, true);
        $data = $json['data'] ?? null;
        self::log($db, 'matrix', count($origins) . ' водителей',
            is_array($data) ? 'success' : 'failed', $code, $raw, $ms);
        if (!is_array($data)) {
            self::$lastError = self::explainError($code, $raw);
            return null;
        }

        $out = array_fill(0, count($origins), null);
        foreach ($data as $cell) {
            $i = (int) ($cell['originIndex'] ?? -1);
            $sec = $cell['routeSummary']['travelTimeInSeconds'] ?? null;
            if ($i >= 0 && $i < count($origins) && $sec !== null) {
                $out[$i] = (int) $sec;
            }
        }
        return $out;
    }

    // ── Привязка трека к дорогам ─────────────────────────────────────────

    /**
     * Сглаживание GPS-трека по дорожной сети.
     * @param array<int,array{0:float,1:float}> $points
     * @return array<int,array{0:float,1:float}>|null
     */
    public static function snapToRoads(\PDO $db, array $points): ?array
    {
        if (!self::enabled($db, 'snap_to_roads') || count($points) < 2) {
            return null;
        }
        // Формат v1 (по документации TomTom): тело — GeoJSON-поле «points»
        // («route» как в Route Monitoring здесь неизвестно → HTTP 400), а в
        // распарсенном ответе route — FeatureCollection с LineString-ами.
        $points = array_slice($points, 0, 100);
        $payload = ['points' => array_map(
            fn($p) => [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $p[1], (float) $p[0]],   // [lng, lat]
                ],
                'properties' => ['heading' => 0],
            ],
            $points
        )];
        $url = self::BASE . '/snapToRoads/1?' . http_build_query([
            'key' => self::apiKey(),
            'vehicleType' => 'PassengerCar',
            'fields' => '{route{type,geometry{type,coordinates}}}',
        ]);
        [$code, $raw, $ms] = self::request($url, 'POST', json_encode($payload), [
            'Content-Type: application/json',
        ]);
        self::countUsage($db, 'snap_to_roads');
        $json = json_decode($raw, true);

        // Ответ — GeoJSON. Вложенность у разных развёртываний отличается:
        // route.features[]  ИЛИ  route[] (плоский список Feature) — читаем оба.
        $features = $json['route']['features'] ?? $json['route'] ?? [];
        $snapped = [];
        foreach (is_array($features) ? $features : [] as $feature) {
            foreach (($feature['geometry']['coordinates'] ?? []) as $c) {
                if (is_array($c) && count($c) >= 2) {
                    $snapped[] = [(float) $c[1], (float) $c[0]];
                }
            }
        }
        self::log($db, 'snapToRoads', count($points) . ' точек',
            $snapped ? 'success' : 'failed', $code, $raw, $ms);
        if ($snapped === []) {
            self::$lastError = self::explainError($code, $raw);
            return null;
        }
        return $snapped;
    }

    // ── Изохроны ─────────────────────────────────────────────────────────

    /** Полигон досягаемости за N минут: [[lat,lng], ...] или null. */
    public static function reachableRange(\PDO $db, float $lat, float $lng, int $minutes): ?array
    {
        if (!self::enabled($db, 'reachable_range')) {
            return null;
        }
        $url = self::BASE . '/routing/1/calculateReachableRange/'
            . sprintf('%F,%F', $lat, $lng) . '/json?' . http_build_query([
                'key' => self::apiKey(),
                'timeBudgetInSec' => max(60, min(3600, $minutes * 60)),
                'traffic' => 'true',
                'travelMode' => 'car',
            ]);
        [$code, $raw, $ms] = self::request($url);
        self::countUsage($db, 'reachable_range');
        $json = json_decode($raw, true);
        $boundary = $json['reachableRange']['boundary'] ?? null;
        self::log($db, 'reachableRange', "$lat,$lng · {$minutes} мин",
            is_array($boundary) ? 'success' : 'failed', $code, $raw, $ms);
        if (!is_array($boundary)) {
            self::$lastError = self::explainError($code, $raw);
            return null;
        }
        $polygon = [];
        foreach ($boundary as $p) {
            if (isset($p['latitude'], $p['longitude'])) {
                $polygon[] = [(float) $p['latitude'], (float) $p['longitude']];
            }
        }
        return $polygon !== [] ? $polygon : null;
    }

    // ── Геокодинг (подключается в цепочку провайдеров) ───────────────────

    /** Подсказки адресов. Формат элементов — как у остальных провайдеров. */
    public static function search(\PDO $db, string $query, array $svc = []): array
    {
        if (!self::enabled($db, 'search')) {
            return [];
        }
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $params = [
            'key' => self::apiKey(),
            'language' => 'ru-RU',
            'limit' => 7,
            'countrySet' => 'RU',
            'typeahead' => 'true',
        ];
        if (!empty($svc['center_latitude']) && !empty($svc['center_longitude'])) {
            $params['lat'] = (float) $svc['center_latitude'];
            $params['lon'] = (float) $svc['center_longitude'];
            $params['radius'] = 60000;
        }
        $url = self::BASE . '/search/2/search/' . rawurlencode($query) . '.json?'
            . http_build_query($params);

        [$code, $raw, $ms] = self::request($url);
        self::countUsage($db, 'search');
        $items = self::parseSearch($raw);
        if ($items === []) {
            self::$lastError = $code === 200
                ? 'TomTom ответил 200 без совпадений (для такого запроса в регионе ничего нет)'
                : self::explainError($code, $raw);
        }
        self::log($db, 'search', $query, $items ? 'success' : 'failed', $code, $raw, $ms);
        return $items;
    }

    /** Адрес по координатам. */
    public static function reverse(\PDO $db, float $lat, float $lng): ?array
    {
        if (!self::enabled($db, 'reverse_geocode')) {
            return null;
        }
        $url = self::BASE . '/search/2/reverseGeocode/' . sprintf('%F,%F', $lat, $lng) . '.json?'
            . http_build_query([
                'key' => self::apiKey(),
                'language' => 'ru-RU',
                'radius' => 100,
            ]);
        [$code, $raw, $ms] = self::request($url);
        self::countUsage($db, 'reverse_geocode');
        $json = json_decode($raw, true);
        $addr = $json['addresses'][0] ?? null;
        self::log($db, 'reverseGeocode', "$lat,$lng",
            $addr ? 'success' : 'failed', $code, $raw, $ms);
        if (!$addr) {
            self::$lastError = self::explainError($code, $raw);
            return null;
        }
        $address = $addr['address'] ?? [];
        return [
            'displayName' => (string) ($address['freeformAddress'] ?? ''),
            'fullAddress' => (string) ($address['freeformAddress'] ?? ''),
            'latitude' => $lat,
            'longitude' => $lng,
            'source' => 'tomtom',
        ];
    }

    private static function parseSearch(string $raw): array
    {
        $json = json_decode($raw, true);
        $out = [];
        foreach (($json['results'] ?? []) as $r) {
            $pos = $r['position'] ?? null;
            if (!isset($pos['lat'], $pos['lon'])) {
                continue;
            }
            $address = $r['address'] ?? [];
            $name = (string) ($address['freeformAddress'] ?? '');
            $poi = (string) ($r['poi']['name'] ?? '');
            if ($poi !== '') {
                $name = $poi . ($name !== '' ? ', ' . $name : '');
            }
            if ($name === '') {
                continue;
            }
            $out[] = [
                'displayName' => $name,
                'fullAddress' => (string) ($address['freeformAddress'] ?? $name),
                'latitude' => (float) $pos['lat'],
                'longitude' => (float) $pos['lon'],
                'source' => 'tomtom',
            ];
        }
        return $out;
    }

    /**
     * По коду и телу ответа возвращает понятную причину отказа:
     * невалидный ключ / продукт не включён в кабинете / лимит / сеть.
     */
    private static function explainError(int $code, string $raw): string
    {
        $text = '';
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $text = (string) (
                $json['errorText'] ?? $json['detailedError']['message']
                ?? $json['error']['message'] ?? $json['message'] ?? ''
            );
        }
        if ($text === '' && $raw !== '') {
            $text = trim(mb_substr($raw, 0, 160));
        }
        $text = mb_substr($text, 0, 220);

        if ($code === 401 || $code === 403) {
            return 'HTTP ' . $code . ': ключ отклонён TomTom'
                . ($text !== '' ? ' — ' . $text : '')
                . '. Проверьте: 1) ключ из кабинета MyTomTom, без кавычек и пробелов; '
                . '2) для ключа включены продукты либо «All APIs»; '
                . '3) если ключ ограничен по Referer — добавьте домен сервиса ('
                . rtrim(self::referer(), '/') . ') в «Allowed referrers» MyTomTom '
                . 'или снимите ограничение: сервер передаёт его в заголовке Referer';
        }
        if ($code === 429) {
            return 'HTTP 429: превышена частота запросов (QPS) — сервис восстановится сам';
        }
        if ($code === 402) {
            return 'HTTP 402: исчерпана суточная квота TomTom — до полуночи UTC';
        }
        if ($code === 0) {
            return 'Нет соединения с api.tomtom.com (проверьте исходящий HTTPS на хостинге)';
        }
        return 'HTTP ' . $code . ($text !== '' ? ' — ' . $text : '');
    }

    // ── Диагностика для админки ──────────────────────────────────────────

    /** Живая проверка сервиса: ответ TomTom + точная причина отказа. */
    public static function diagnose(\PDO $db, string $service, array $svc = []): array
    {
        self::$lastError = '';
        $result = self::diagnoseService($db, $service, $svc);
        if (empty($result['ok']) && self::$lastError !== '') {
            $result['message'] = trim((string) $result['message'], ' —')
                . ' — ' . self::$lastError;
        }
        return $result;
    }

    /** Ядро проверки по каждому сервису. */
    private static function diagnoseService(\PDO $db, string $service, array $svc = []): array
    {
        if (!self::hasKey()) {
            return ['ok' => false, 'message' => 'Ключ TomTom не задан («API-ключи»)'];
        }
        $all = self::all($db);
        if (empty($all[$service]['enabled'])) {
            return ['ok' => false, 'message' => 'Сервис выключен'];
        }

        $lat = (float) ($svc['center_latitude'] ?? 57.1522);
        $lng = (float) ($svc['center_longitude'] ?? 65.5272);

        try {
            switch ($service) {
                case 'search':
                    $items = self::search($db, 'Республики 52', $svc);
                    return ['ok' => $items !== [],
                        'message' => $items ? ('Найдено: ' . $items[0]['displayName']) : 'Пустой ответ'];
                case 'reverse_geocode':
                    $item = self::reverse($db, $lat, $lng);
                    return ['ok' => $item !== null,
                        'message' => $item ? ('Адрес: ' . $item['displayName']) : 'Пустой ответ'];
                case 'routing':
                    $r = self::route($db, [[$lat, $lng], [$lat + 0.02, $lng + 0.02]], false);
                    return ['ok' => $r !== null, 'message' => $r
                        ? sprintf('Маршрут: %.1f км, %d мин (задержка %d мин)',
                            $r['distanceKm'], $r['durationMinutes'], $r['trafficDelayMinutes'])
                        : 'Маршрутизатор не ответил'];
                case 'matrix':
                    $t = self::travelTimes($db, [[$lat, $lng]], $lat + 0.02, $lng + 0.02);
                    return ['ok' => $t !== null, 'message' => $t
                        ? ('Время подачи: ' . (int) ceil(((int) ($t[0] ?? 0)) / 60) . ' мин')
                        : 'Матрица не ответила'];
                case 'snap_to_roads':
                    $s = self::snapToRoads($db, [[$lat, $lng], [$lat + 0.004, $lng + 0.004]]);
                    return ['ok' => $s !== null,
                        'message' => $s ? ('Точек после привязки: ' . count($s)) : 'Нет ответа'];
                case 'reachable_range':
                    $p = self::reachableRange($db, $lat, $lng, 10);
                    return ['ok' => $p !== null,
                        'message' => $p ? ('Полигон: ' . count($p) . ' точек') : 'Нет ответа'];
                default:
                    // Тайловые слои: проверяем доступность одного тайла центра города
                    $url = self::tileUrl($db, $service);
                    if ($url === null) {
                        return ['ok' => false, 'message' => 'Слой недоступен'];
                    }
                    [$z, $x, $y] = self::tileXY($lat, $lng, 12);
                    $probe = str_replace(['{z}', '{x}', '{y}'], [(string) $z, (string) $x, (string) $y], $url);
                    [$code, $raw, $ms] = self::request($probe);
                    $ok = $code === 200 && strlen($raw) > 100;
                    if (!$ok) {
                        self::$lastError = self::explainError($code, $raw);
                    }
                    self::log($db, $service, 'tile probe', $ok ? 'success' : 'failed',
                        $code, $ok ? '[png ' . strlen($raw) . ' байт]' : $raw, $ms);
                    return ['ok' => $ok,
                        'message' => $ok ? ('Тайл получен, ' . strlen($raw) . ' байт') : 'Слой недоступен'];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Номер тайла по координатам (схема Slippy Map). */
    private static function tileXY(float $lat, float $lng, int $zoom): array
    {
        $n = 2 ** $zoom;
        $x = (int) floor(($lng + 180) / 360 * $n);
        $latRad = deg2rad($lat);
        $y = (int) floor((1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n);
        return [$zoom, max(0, $x), max(0, $y)];
    }

    // ── HTTP и журнал ────────────────────────────────────────────────────

    /** @return array{0:int,1:string,2:int} [код, тело, миллисекунды] */
    private static function request(
        string $url, string $method = 'GET', ?string $body = null, array $headers = []
    ): array {
        $started = microtime(true);
        $headers[] = 'Accept: application/json';
        // Ключ TomTom может быть ограничен по Referer в кабинете MyTomTom —
        // без заголовка серверные вызовы получают "invalid Referer header" (403).
        // Отправляем публичный домен сервиса, он и должен быть в списке разрешённых.
        $headers[] = 'Referer: ' . self::referer();

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
                return [$code, (string) $raw, (int) round((microtime(true) - $started) * 1000)];
            }
            if (!ini_get('allow_url_fopen')) {
                return [0, 'cURL: ' . $error, (int) round((microtime(true) - $started) * 1000)];
            }
        }

        $ctx = stream_context_create(['http' => [
            'timeout' => 10,
            'method' => $method,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body ?? '',
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, $raw !== false ? (string) $raw : 'Ошибка соединения',
            (int) round((microtime(true) - $started) * 1000)];
    }

    /** Домен сервиса для Referer: настраивается константой PUBLIC_BASE_URL. */
    private static function referer(): string
    {
        $base = defined('PUBLIC_BASE_URL')
            ? (string) PUBLIC_BASE_URL
            : (getenv('PUBLIC_BASE_URL') ?: 'https://taxi.event72.ru');
        return rtrim($base, '/') . '/';
    }

    private static function log(
        \PDO $db, string $action, string $summary, string $status, int $code, string $raw, int $ms
    ): void {
        try {
            $db->prepare(
                'INSERT INTO service_call_logs
                 (service,action,request_summary,status,http_code,response_body,duration_ms)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                'tomtom', mb_substr($action, 0, 60), mb_substr($summary, 0, 500),
                $status, $code, mb_substr($raw, 0, 5000), $ms,
            ]);
        } catch (\Throwable) {
        }
    }
}
