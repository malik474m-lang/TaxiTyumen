<?php
// GET api/places — популярные адреса города для подсказок
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$service = ServiceSettings::get($db);

// Встроенный справочник содержит только тюменские адреса: для другого города
// список пуст — подсказки берутся из DaData/Nominatim через geocoding.php
if (mb_strtolower((string) $service['city_name']) !== 'тюмень') {
    Response::json([]);
}
Response::json(Taxi::PLACES);
