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

    public static function geocodeAddress(string $address): array
    {
        $q = mb_strtolower(trim($address));
        foreach (self::PLACES as $p) {
            if (str_contains($q, mb_strtolower(mb_substr($p['name'], 0, 6)))) {
                return ['lat' => $p['lat'], 'lng' => $p['lng']];
            }
        }
        $hash = crc32($q) & 0x7fffffff;
        $latJ = (($hash % 1000) / 1000 - 0.5) * 0.06;
        $lngJ = (((($hash >> 10) % 1000) / 1000) - 0.5) * 0.1;
        return ['lat' => self::CITY_LAT + $latJ, 'lng' => self::CITY_LNG + $lngJ];
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
    public static function computePrice(array $tariff, float $distanceKm): array
    {
        $price = $tariff['base_fare'] + $distanceKm * $tariff['price_per_km'];
        $hour = (int) (new \DateTime('now', new \DateTimeZone('Asia/Yekaterinburg')))->format('G');
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
