<?php
// GET /api/map-config.php — публичная конфигурация Яндекс Карт.
// JS API-ключ по определению передаётся браузеру; защитите его ограничением домена в ЛК Яндекса.
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$service = ServiceSettings::get($db);

// Яндекс Карты используются, только если провайдер ВКЛЮЧЁН в админке
// («Геокодинг» → провайдеры) и для него задан ключ. Раньше проверялось
// лишь наличие ключа, поэтому выключенный Яндекс всё равно рисовался
// в приложении клиента.
$yandexKey = api_key('yandex_maps');
$yandexEnabled = GeoProviders::isActive($db, 'yandex') && $yandexKey !== '';

// Слои TomTom для MapLibre в приложении водителя. Состав определяет админка
// («TomTom»): выключенный сервис не отдаётся вовсе. Ключ намеренно попадает
// в приложение — как публичный JS-ключ Яндекс Карт.
$trafficTileUrl = TomTom::tileUrl($db, 'traffic_flow');
$incidentsTileUrl = TomTom::tileUrl($db, 'traffic_incidents');
$baseTileUrl = TomTom::tileUrl($db, 'map_tiles');

Response::json([
    // Выключен Яндекс — приложения переходят на карту OpenStreetMap
    'provider' => $yandexEnabled ? 'yandex' : 'osm',
    'apiKey' => $yandexEnabled ? $yandexKey : '',
    'configured' => $yandexEnabled,
    'yandexEnabled' => $yandexEnabled,
    'lang' => 'ru_RU',
    'center' => [(float) $service['center_latitude'], (float) $service['center_longitude']],
    'city' => $service['city_name'],
    'traffic' => [
        'provider' => 'tomtom',
        'configured' => $trafficTileUrl !== null,
        'tileUrl' => $trafficTileUrl,
    ],
    'incidents' => [
        'provider' => 'tomtom',
        'configured' => $incidentsTileUrl !== null,
        'tileUrl' => $incidentsTileUrl,
    ],
    'baseMap' => [
        'provider' => 'tomtom',
        'configured' => $baseTileUrl !== null,
        'tileUrl' => $baseTileUrl,
    ],
]);
