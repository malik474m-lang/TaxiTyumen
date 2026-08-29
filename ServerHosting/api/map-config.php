<?php
// GET /api/map-config.php — публичная конфигурация Яндекс Карт.
// JS API-ключ по определению передаётся браузеру; защитите его ограничением домена в ЛК Яндекса.
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$service = ServiceSettings::get($db);
Response::json([
    'provider' => 'yandex',
    'apiKey' => YANDEX_MAPS_API_KEY,
    'configured' => YANDEX_MAPS_API_KEY !== '',
    'lang' => 'ru_RU',
    'center' => [(float) $service['center_latitude'], (float) $service['center_longitude']],
    'city' => $service['city_name'],
]);
