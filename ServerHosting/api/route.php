<?php
// GET /api/route.php?points=lat,lng;lat,lng[;lat,lng...]
// Геометрия маршрута ПО ДОРОГАМ через OSRM для произвольного набора точек.
// Нужна приложению водителя: путь «моя позиция → подача → остановки → финиш»
// строится по улицам, а не по прямой (иначе линия шла через озёра и дворы).
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$raw = trim((string) ($_GET['points'] ?? ''));
if ($raw === '') {
    Response::error('Параметр points обязателен: lat,lng;lat,lng');
}

$points = [];
foreach (explode(';', $raw) as $pair) {
    $parts = explode(',', trim($pair));
    if (count($parts) !== 2) continue;
    $lat = (float) $parts[0];
    $lng = (float) $parts[1];
    if ($lat == 0.0 || $lng == 0.0) continue;
    $points[] = [$lat, $lng];
}

if (count($points) < 2) {
    Response::error('Нужны минимум две корректные точки');
}
if (count($points) > 12) {
    $points = array_slice($points, 0, 12);
}

$geometry = Taxi::getRouteGeometryThrough($points);
$route = Taxi::getRouteThrough($points);

// Признак того, что OSRM действительно вернул дорогу, а не наш фолбэк-отрезок
$byRoads = count($geometry) > count($points);

Response::json([
    'geometry' => $geometry,                       // [[lat,lng], ...]
    'distanceKm' => $route['distanceKm'] ?? null,
    'durationMinutes' => $route['durationMinutes'] ?? null,
    'byRoads' => $byRoads,
    'points' => count($points),
]);
