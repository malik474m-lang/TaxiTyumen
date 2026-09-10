<?php
// Порт TaxiService.Core: DistanceCalculator + PricingService + OrderNumberGenerator
declare(strict_types=1);

final class Taxi
{
    public const CITY_LAT = 57.1522;
    public const CITY_LNG = 65.5272;

    public const PLACES = [
        ['name' => 'Аэропорт Рощино',        'lat' => 57.1896, 'lng' => 65.3243],
        ['name' => 'Ж/д вокзал Тюмень',      'lat' => 57.1459, 'lng' => 65.5271],
        ['name' => 'Набережная реки Туры',   'lat' => 57.1588, 'lng' => 65.5260],
        ['name' => 'Цветной бульвар',        'lat' => 57.1486, 'lng' => 65.5349],
        ['name' => 'Мост влюблённых',        'lat' => 57.1555, 'lng' => 65.5280],
        ['name' => 'Площадь 400-летия Тюмени', 'lat' => 57.1580, 'lng' => 65.5345],
        ['name' => 'ТюмГУ, главный корпус',  'lat' => 57.1526, 'lng' => 65.5365],
        ['name' => 'Театр драмы',            'lat' => 57.1542, 'lng' => 65.5269],
        ['name' => 'ТРЦ «Гудвин»',           'lat' => 57.1378, 'lng' => 65.5825],
        ['name' => 'ТРЦ «Кристалл»',         'lat' => 57.1262, 'lng' => 65.5910],
        ['name' => 'ТРЦ «Олимп»',            'lat' => 57.1100, 'lng' => 65.5440],
        ['name' => 'ДК «Нефтяник»',          'lat' => 57.1241, 'lng' => 65.5922],
        ['name' => 'Гилёвская роща',         'lat' => 57.1576, 'lng' => 65.4766],
        ['name' => 'ЖК «Европейский»',       'lat' => 57.0954, 'lng' => 65.5699],
        ['name' => 'мкр. Patрушево',         'lat' => 57.1150, 'lng' => 65.5350],
        ['name' => 'ул. Республики, 1',      'lat' => 57.1534, 'lng' => 65.5214],
        ['name' => 'ул. 8 Марта, 2',         'lat' => 57.1609, 'lng' => 65.5197],
        ['name' => 'ул. Мельникайте, 103',   'lat' => 57.1654, 'lng' => 65.5412],
        ['name' => 'ул. Пермякова, 74',      'lat' => 57.1063, 'lng' => 65.5757],
        ['name' => 'ул. Широтная, 154',      'lat' => 57.1744, 'lng' => 65.5748],
    ];

    public static function geocodeAddress(string $address, ?float $centerLat = null, ?float $centerLng = null): array
    {
        $centerLat ??= self::CITY_LAT;
        $centerLng ??= self::CITY_LNG;
        $q = trim($address);
        if ($q === '') {
            return ['lat' => $centerLat, 'lng' => $centerLng];
        }

        // 1. Геокодинг по городу: DaData -> OpenCage -> Яндекс
        try {
            $db = Db::pdo();
            $results = GeocodingService::search($db, $q);
            if (empty($results)) {
                // 1b. Адрес за пределами города/зоны — повторяем без рамки города,
                // иначе координаты назначения подменялись центром Тюмени.
                $results = GeocodingService::searchWide($db, $q);
            }
            if (!empty($results[0]['latitude']) && !empty($results[0]['longitude'])) {
                return [
                    'lat' => (float) $results[0]['latitude'],
                    'lng' => (float) $results[0]['longitude'],
                ];
            }
        } catch (\Throwable) {
        }

        // 2. Предопределённые ориентиры города
        $qLower = mb_strtolower($q);
        foreach (self::PLACES as $p) {
            if (str_contains($qLower, mb_strtolower(mb_substr($p['name'], 0, 6)))) {
                return ['lat' => $p['lat'], 'lng' => $p['lng']];
            }
        }

        // 3. Fallback: центр города (без псевдослучайного шума)
        return ['lat' => $centerLat, 'lng' => $centerLng];
    }

    public static function getDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function getRealRoute(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $fallback = function () use ($lat1, $lng1, $lat2, $lng2) {
            $dist = self::getDistanceKm($lat1, $lng1, $lat2, $lng2) * 1.3;
            return [
                'distanceKm' => round($dist, 1),
                'durationMinutes' => (int) ceil($dist / 25 * 60),
            ];
        };
        $json = self::osrmRequest(sprintf(
            '/route/v1/driving/%F,%F;%F,%F?overview=false', $lng1, $lat1, $lng2, $lat2
        ), 5);
        $route = $json['routes'][0] ?? null;
        if ($route) {
            return [
                'distanceKm' => round($route['distance'] / 1000, 1),
                'durationMinutes' => (int) ceil($route['duration'] / 60),
            ];
        }
        return $fallback();
    }

    /**
     * Маршрут через список точек в строгом порядке следования:
     * подача → промежуточная 1 → промежуточная 2 → ... → назначение.
     * OSRM считает участки последовательно, порядок точек не оптимизируется.
     *
     * @param array<int,array{0:float,1:float}> $points [[lat,lng], ...]
     */
    public static function getRouteThrough(array $points): array
    {
        $points = array_values(array_filter(
            $points,
            fn($p) => is_array($p) && count($p) >= 2
                && (float) $p[0] != 0.0 && (float) $p[1] != 0.0
        ));
        if (count($points) < 2) {
            return ['distanceKm' => 0.0, 'durationMinutes' => 0];
        }

        // Резерв: сумма участков по прямой с коэффициентом городских дорог.
        $fallback = function () use ($points) {
            $dist = 0.0;
            for ($i = 1; $i < count($points); $i++) {
                $dist += self::getDistanceKm(
                    (float) $points[$i - 1][0], (float) $points[$i - 1][1],
                    (float) $points[$i][0], (float) $points[$i][1]
                ) * 1.3;
            }
            return [
                'distanceKm' => round($dist, 1),
                'durationMinutes' => (int) ceil($dist / 25 * 60),
            ];
        };

        $coords = [];
        foreach ($points as $p) {
            $coords[] = sprintf('%F,%F', (float) $p[1], (float) $p[0]);
        }
        $json = self::osrmRequest(
            '/route/v1/driving/' . implode(';', $coords) . '?overview=false', 7
        );
        $route = $json['routes'][0] ?? null;
        if ($route) {
            return [
                'distanceKm' => round(((float) $route['distance']) / 1000, 1),
                'durationMinutes' => (int) ceil(((float) $route['duration']) / 60),
            ];
        }
        return $fallback();
    }

    /**
     * Геометрия маршрута через список точек в заданном порядке.
     *
     * @param array<int,array{0:float,1:float}> $points
     */
    /** Публичные OSRM-серверы: пробуем по очереди, пока не ответит рабочий. */
    public const OSRM_HOSTS = [
        'https://router.project-osrm.org',
        'https://routing.openstreetmap.de/routed-car',
    ];

    /** Запрос к OSRM с перебором серверов. */
    private static function osrmRequest(string $path, int $timeoutSec = 6): ?array
    {
        foreach (self::OSRM_HOSTS as $host) {
            try {
                return self::httpGet($host . $path, $timeoutSec);
            } catch (\Throwable) {
                continue;   // пробуем следующий сервер
            }
        }
        return null;
    }

    /**
     * Полный маршрут одним запросом к OSRM: геометрия + дистанция + манёвры.
     * Манёвры (steps=true) нужны приложению водителя для голосовых подсказок.
     * Возвращает null, если маршрутизатор недоступен (вызывающий код решает,
     * чем заменить: у route.php есть прежний пошаговый путь с фолбэками).
     *
     * @param array<int,array{0:float,1:float}> $points [[lat,lng], ...]
     * @return array{geometry: array, distanceKm: float, durationMinutes: int, steps: array}|null
     */
    public static function getRouteBundleThrough(array $points): ?array
    {
        $points = array_values(array_filter(
            $points,
            fn($p) => is_array($p) && count($p) >= 2
                && (float) $p[0] != 0.0 && (float) $p[1] != 0.0
        ));
        if (count($points) < 2) {
            return null;
        }
        $coords = [];
        foreach ($points as $p) {
            $coords[] = sprintf('%F,%F', (float) $p[1], (float) $p[0]);
        }
        $json = self::osrmRequest(
            '/route/v1/driving/' . implode(';', $coords)
            . '?overview=full&geometries=geojson&steps=true', 9
        );
        $route = $json['routes'][0] ?? null;
        if (!$route) {
            return null;
        }

        $geometry = [];
        foreach (($route['geometry']['coordinates'] ?? []) as $c) {
            if (is_array($c) && count($c) >= 2) {
                $geometry[] = [(float) $c[1], (float) $c[0]];
            }
        }
        if (count($geometry) < 2) {
            return null;
        }

        // Манёвры всех участков пути в единую ленту (точка — начало манёвра)
        $steps = [];
        foreach (($route['legs'] ?? []) as $leg) {
            foreach (($leg['steps'] ?? []) as $st) {
                $m = $st['maneuver'] ?? [];
                $loc = $m['location'] ?? null;
                if (!is_array($loc) || count($loc) < 2) {
                    continue;
                }
                $steps[] = [
                    'loc' => [(float) $loc[1], (float) $loc[0]],
                    'type' => (string) ($m['type'] ?? ''),
                    'modifier' => (string) ($m['modifier'] ?? ''),
                    'exit' => isset($m['exit']) ? (int) $m['exit'] : null,
                    'name' => (string) ($st['name'] ?? ''),
                    'distance' => (float) ($st['distance'] ?? 0),
                ];
            }
        }

        return [
            'geometry' => $geometry,
            'distanceKm' => round(((float) ($route['distance'] ?? 0)) / 1000, 1),
            'durationMinutes' => (int) ceil(((float) ($route['duration'] ?? 0)) / 60),
            'steps' => $steps,
        ];
    }

    public static function getRouteGeometryThrough(array $points): array
    {
        $points = array_values(array_filter(
            $points,
            fn($p) => is_array($p) && count($p) >= 2
                && (float) $p[0] != 0.0 && (float) $p[1] != 0.0
        ));
        if (count($points) < 2) {
            return $points;
        }
        $coords = [];
        foreach ($points as $p) {
            $coords[] = sprintf('%F,%F', (float) $p[1], (float) $p[0]);
        }
        $json = self::osrmRequest(
            '/route/v1/driving/' . implode(';', $coords) . '?overview=full&geometries=geojson', 8
        );
        $line = $json['routes'][0]['geometry']['coordinates'] ?? null;
        if (is_array($line) && count($line) > 1) {
            return array_map(fn(array $c) => [$c[1], $c[0]], $line);
        }
        return $points;
    }

    public static function getRouteGeometry(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $json = self::osrmRequest(sprintf(
            '/route/v1/driving/%F,%F;%F,%F?overview=full&geometries=geojson',
            $lng1, $lat1, $lng2, $lat2
        ), 6);
        $coords = $json['routes'][0]['geometry']['coordinates'] ?? null;
        if (is_array($coords) && count($coords) > 1) {
            return array_map(fn(array $c) => [$c[1], $c[0]], $coords);
        }
        return [[$lat1, $lng1], [$lat2, $lng2]];
    }

    /**
     * GET с JSON-ответом. На shared-хостингах file_get_contents для внешних
     * URL обычно запрещён (allow_url_fopen=off) — тогда маршрут не строился
     * и карта рисовала прямую линию между точками. Сначала пробуем cURL.
     */
    private static function httpGet(string $url, int $timeoutSec): array
    {
        $raw = false;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeoutSec,
                CURLOPT_CONNECTTIMEOUT => max(2, (int) ($timeoutSec / 2)),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'TaxiTyumen/1.0 (+https://taxi.event72.ru)',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $result = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($result !== false && $code >= 200 && $code < 300) {
                $raw = $result;
            }
        }

        if ($raw === false && ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => [
                'timeout' => $timeoutSec,
                'method' => 'GET',
                'header' => "User-Agent: TaxiTyumen/1.0\r\nAccept: application/json\r\n",
            ]]);
            $raw = @file_get_contents($url, false, $ctx);
        }

        if ($raw === false) {
            throw new \RuntimeException('Маршрутизатор недоступен: нет исходящих HTTP-запросов');
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Плохой ответ маршрутизатора');
        }
        return $json;
    }

    // Цена по тарифу с множителями Тюмени (UTC+5) — порт computePrice из web-версии
    /**
     * Наценка за промежуточные адреса при зонном ценообразовании.
     *
     * Зона имеет приоритет: её фиксированная цена остаётся базой поездки,
     * а путь подачи → промежуточная точка 1 → точка 2 ... (А→Б) оплачивается
     * дополнительно по километражу тарифа, потому что это отклонение от маршрута зоны.
     *
     * @param array $tariff строка тарифа с полем price_per_km
     * @param array<int,array{0:float,1:float}> $points [[lat,lng], ...] — подача и промежуточные точки
     */
    /**
     * Наценка за промежуточные адреса при зонном ценообразовании.
     *
     * Поддерживает минимальную цену и два режима:
     *  - mode 'max':  surcharge = max(min_price, km × per_km) — берётся большее;
     *  - mode 'plus': surcharge = min_price + km × per_km — километраж
     *    добавляется к минимуму всегда.
     *
     * Когда stopMinPrice = 0, оба режима дают чистый расчёт по километражу.
     *
     * @param array $tariff строка тарифа с price_per_km
     * @param array<int,array{0:float,1:float}> $points [[lat,lng], ...] — подача и промежуточные точки
     * @param float $stopMinPrice минимальная цена за один промежуточный адрес
     * @param string $stopPriceMode 'max' или 'plus'
     */
    public static function stopsSurcharge(
        array $tariff,
        array $points,
        float $stopMinPrice = 0.0,
        string $stopPriceMode = 'max'
    ): array {
        $points = array_values(array_filter(
            $points,
            fn($p) => is_array($p) && count($p) >= 2
                && (float) $p[0] != 0.0 && (float) $p[1] != 0.0
        ));

        // Количество остановок = количество точек минус точка подачи.
        $stopCount = max(0, count($points) - 1);
        if ($stopCount < 1) {
            return ['distanceKm' => 0.0, 'surcharge' => 0, 'stopCount' => 0];
        }

        $route = self::getRouteThrough($points);
        $perKm = max(0.0, (float) ($tariff['price_per_km'] ?? 0));
        $kmPrice = (float) $route['distanceKm'] * $perKm;
        $minPrice = max(0.0, $stopMinPrice);

        if ($stopPriceMode === 'plus') {
            $surcharge = $minPrice * $stopCount + $kmPrice;
        } else {
            // 'max': минимум или километраж — что больше (минимум за каждый адрес).
            $surcharge = max($minPrice * $stopCount, $kmPrice);
        }

        return [
            'distanceKm' => (float) $route['distanceKm'],
            'surcharge' => (int) round($surcharge),
            'stopCount' => $stopCount,
        ];
    }

    public static function computePrice(array $tariff, float $distanceKm, ?int $utcOffset = null): array
    {
        $price = $tariff['base_fare'] + $distanceKm * $tariff['price_per_km'];
        $hour = ((int) gmdate('G') + ($utcOffset ?? CITY_UTC_OFFSET) + 24) % 24;
        $isNight = $hour >= 23 || $hour < 6;
        $isPeak = ($hour >= 7 && $hour < 9) || ($hour >= 17 && $hour < 19);
        $multiplier = 1.0;
        if ($isNight) {
            $multiplier = (float) $tariff['night_multiplier'];
            $price *= $multiplier;
        } elseif ($isPeak) {
            $multiplier = (float) $tariff['peak_multiplier'];
            $price *= $multiplier;
        }
        $price = max($price, (float) $tariff['minimum_fare']);
        return [
            'price' => round($price),
            'isNightRate' => $isNight,
            'isPeakRate' => $isPeak,
            'multiplier' => $multiplier,
        ];
    }

    public static function normalizeTariff(mixed $value): string
    {
        $map = [0 => 'economy', 1 => 'comfort', 2 => 'business', 3 => 'minivan'];
        if (is_numeric($value)) return $map[(int) $value] ?? 'economy';
        $v = strtolower(trim((string) $value));
        return in_array($v, $map, true) ? $v : 'economy';
    }

    public static function normalizePayment(mixed $value): string
    {
        $map = [0 => 'cash', 1 => 'card', 2 => 'bonus'];
        if (is_numeric($value)) return $map[(int) $value] ?? 'cash';
        $v = strtolower(trim((string) $value));
        if ($v === 'bonuspoints') $v = 'bonus';
        return in_array($v, $map, true) ? $v : 'cash';
    }

    public static function generateOrderNumber(): string
    {
        $now = new \DateTime('now');
        $rand = random_int(10000, 99999);
        $ms = str_pad((string) ((int) ($now->format('u') / 1000)), 3, '0', STR_PAD_LEFT);
        return 'TX-' . $now->format('Ymd-His') . $ms . '-' . $rand;
    }

    public const STATUS_TEXT = [
        'created' => 'Создан',
        'searching' => 'Поиск водителя',
        'driver_assigned' => 'Водитель назначен',
        'driver_en_route' => 'Водитель в пути',
        'driver_arrived' => 'Водитель на месте',
        'in_progress' => 'Поездка',
        'completed' => 'Завершён',
        'cancelled' => 'Отменён',
        'no_driver_found' => 'Водитель не найден',
    ];

    public const TARIFF_NAMES = [
        'economy' => 'Эконом',
        'comfort' => 'Комфорт',
        'business' => 'Бизнес',
        'minivan' => 'Минивэн',
    ];

    public const PAYMENT_NAMES = [
        'cash' => 'Наличные',
        'card' => 'Карта',
        'bonus' => 'Бонусы',
    ];

    public const DRIVER_STATUS_TEXT = [
        'offline' => 'Офлайн',
        'available' => 'На линии',
        'on_route' => 'Едет к клиенту',
        'in_trip' => 'В поездке',
        'busy' => 'Занят',
    ];

    public const ACTIVE_STATUSES = [
        'created', 'searching', 'driver_assigned', 'driver_en_route',
        'driver_arrived', 'in_progress', 'no_driver_found',
    ];
}
