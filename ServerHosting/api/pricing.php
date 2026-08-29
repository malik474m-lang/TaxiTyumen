<?php
// POST api/pricing — CalculateAllTariffsAsync (оценка цены по всем тарифам + геометрия)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$body = Response::requirePostJson();
$fromLat = (float) ($body['fromLat'] ?? 0);
$fromLng = (float) ($body['fromLng'] ?? 0);
$toLat = (float) ($body['toLat'] ?? 0);
$toLng = (float) ($body['toLng'] ?? 0);

if (!empty($body['fromAddress']) && $fromLat == 0.0) {
    $g = Taxi::geocodeAddress((string) $body['fromAddress'], $service['center_latitude'], $service['center_longitude']);
    $fromLat = $g['lat'];
    $fromLng = $g['lng'];
}
if (!empty($body['toAddress']) && $toLat == 0.0) {
    $g = Taxi::geocodeAddress((string) $body['toAddress'], $service['center_latitude'], $service['center_longitude']);
    $toLat = $g['lat'];
    $toLng = $g['lng'];
}
if ($fromLat == 0.0 || $toLat == 0.0) {
    Response::error('Укажите адреса подачи и назначения');
}

$service = ServiceSettings::get($db);
$route = Taxi::getRealRoute($fromLat, $fromLng, $toLat, $toLng);
$geometry = Taxi::getRouteGeometry($fromLat, $fromLng, $toLat, $toLng);
$activeTariffs = $db->query('SELECT * FROM tariffs WHERE is_active = 1')->fetchAll();

$estimates = [];
foreach ($activeTariffs as $t) {
    $p = Taxi::computePrice($t, (float) $route['distanceKm'], (int) $service['utc_offset']);
    $zonePrice = Zones::fixedPrice($db, $fromLat, $fromLng, $toLat, $toLng, (string) $t['type']);
    $finalPrice = $p['price'];
    $isFixed = false;
    if ($zonePrice !== null) {
        $finalPrice = $zonePrice['applyMultipliers']
            ? round($zonePrice['price'] * (float) $p['multiplier'])
            : $zonePrice['price'];
        $isFixed = true;
    }
    $estimates[] = [
        'tariffType' => $t['type'],
        'tariffName' => $t['name'],
        'description' => $t['description'],
        'price' => $finalPrice,
        'isFixedPrice' => $isFixed,
        'pricingMode' => $isFixed ? 'zone' : 'tariff',
        'fromZone' => $zonePrice['fromZone']['name'] ?? null,
        'toZone' => $zonePrice['toZone']['name'] ?? null,
        'distanceKm' => $route['distanceKm'],
        'durationMinutes' => $route['durationMinutes'],
        'isNightRate' => $p['isNightRate'],
        'isPeakRate' => $p['isPeakRate'],
        'multiplier' => $p['multiplier'],
        'minimumFare' => (float) $t['minimum_fare'],
    ];
}
usort($estimates, fn($a, $b) => $a['price'] <=> $b['price']);

// Совместимость с исходным PricingController ASP.NET
if (($GLOBALS['pricing_compat_mode'] ?? '') === 'all') {
    Response::json($estimates);
}
if (($GLOBALS['pricing_compat_mode'] ?? '') === 'single') {
    $requested = $GLOBALS['pricing_compat_tariff'] ?? 0;
    $enumMap = [0 => 'economy', 1 => 'comfort', 2 => 'business', 3 => 'minivan'];
    $type = $enumMap[(int) $requested] ?? strtolower((string) $requested);
    foreach ($estimates as $estimate) {
        if ($estimate['tariffType'] === $type) Response::json($estimate);
    }
    Response::error('Тариф не найден', 404);
}

Response::json([
    'from' => ['lat' => $fromLat, 'lng' => $fromLng],
    'to' => ['lat' => $toLat, 'lng' => $toLng],
    'geometry' => $geometry,
    'estimates' => $estimates,
]);
