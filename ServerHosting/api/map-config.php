<?php
// GET /api/map-config.php — публичная конфигурация Яндекс Карт.
// JS API-ключ по определению передаётся браузеру; защитите его ограничением домена в ЛК Яндекса.
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$service = ServiceSettings::get($db);

// Слой пробок (TomTom Traffic Flow Tiles) для MapLibre в приложении водителя.
// Ключ намеренно отдаётся приложению — как публичный JS-ключ Яндекс Карт.
$tomtom = api_key('tomtom_traffic');
$trafficTileUrl = $tomtom !== ''
    ? 'https://api.tomtom.com/traffic/map/4/tile/flow/relative0/{z}/{x}/{y}.png?key='
      . rawurlencode($tomtom) . '&thickness=10'
    : null;

Response::json([
    'provider' => 'yandex',
    'apiKey' => api_key('yandex_maps'),
    'configured' => api_key('yandex_maps') !== '',
    'lang' => 'ru_RU',
    'center' => [(float) $service['center_latitude'], (float) $service['center_longitude']],
    'city' => $service['city_name'],
    'traffic' => [
        'provider' => 'tomtom',
        'configured' => $tomtom !== '',
        'tileUrl' => $trafficTileUrl,
    ],
]);
