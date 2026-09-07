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
        $q = mb_strtolower(trim($address));
        foreach (self::PLACES as $p) {
            if (str_contains($q, mb_strtolower(mb_substr($p['name'], 0, 6)))) {
                return ['lat' => $p['lat'], 'lng' => $p['lng']];
            }
        }
        $hash = crc32($q) & 0x7fffffff;
        $latJ = (($hash % 1000) / 1000 - 0.5) * 0.06;
        $lngJ = (((($hash >> 10) % 1000) / 1000) - 0.5) * 0.1;
        return ['lat' => $centerLat + $latJ, 'lng' => $centerLng + $lngJ];
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
        try {
            $url = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=false',
                $lng1, $lat1, $lng2, $lat2
            );
            $json = self::httpGet($url, 4);
            $route = $json['routes'][0] ?? null;
            if ($route) {
                return [
                    'distanceKm' => round($route['distance'] / 1000, 1),
                    'durationMinutes' => (int) ceil($route['duration'] / 60),
                ];
            }
        } catch (\Throwable) {
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

        try {
            $coords = [];
            foreach ($points as $p) {
                $coords[] = sprintf('%F,%F', (float) $p[1], (float) $p[0]);
            }
            $url = 'https://router.project-osrm.org/route/v1/driving/'
                . implode(';', $coords) . '?overview=false';
            $json = self::httpGet($url, 6);
            $route = $json['routes'][0] ?? null;
            if ($route) {
                return [
                    'distanceKm' => round(((float) $route['distance']) / 1000, 1),
                    'durationMinutes' => (int) ceil(((float) $route['duration']) / 60),
                ];
            }
        } catch (\Throwable) {
        }
        return $fallback();
    }

    /**
     * Геометрия маршрута через список точек в заданном порядке.
     *
     * @param array<int,array{0:float,1:float}> $points
     */
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
        try {
            $coords = [];
            foreach ($points as $p) {
                $coords[] = sprintf('%F,%F', (float) $p[1], (float) $p[0]);
            }
            $url = 'https://router.project-osrm.org/route/v1/driving/'
                . implode(';', $coords) . '?overview=full&geometries=geojson';
            $json = self::httpGet($url, 7);
            $line = $json['routes'][0]['geometry']['coordinates'] ?? null;
            if (is_array($line) && count($line) > 1) {
                return array_map(fn(array $c) => [$c[1], $c[0]], $line);
            }
        } catch (\Throwable) {
        }
        return $points;
    }

    public static function getRouteGeometry(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        try {
            $url = sprintf(
                'https://router.project-osrm.org/route/v1/driving/%F,%F;%F,%F?overview=full&geometries=geojson',
                $lng1, $lat1, $lng2, $lat2
            );
            $json = self::httpGet($url, 5);
            $coords = $json['routes'][0]['geometry']['coordinates'] ?? null;
            if (is_array($coords) && count($coords) > 1) {
                return array_map(fn(array $c) => [$c[1], $c[0]], $coords);
            }
        } catch (\Throwable) {
        }
        return [[$lat1, $lng1], [$lat2, $lng2]];
    }

    private static function httpGet(string $url, int $timeoutSec): array
    {
        $ctx = stream_context_create(['http' => ['timeout' => $timeoutSec, 'method' => 'GET']]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('OSRM недоступен');
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Плохой ответ OSRM');
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
