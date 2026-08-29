<?php
// GET /api/geocoding.php?q=... | ?lat=&lng= — серверный DaData + Яндекс Геокодер
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if(isset($_GET['lat'],$_GET['lng']))Response::json(GeocodingService::reverse($db,(float)$_GET['lat'],(float)$_GET['lng']));
$q=trim((string)($_GET['q']??''));
if($q==='')Response::error('Параметр q обязателен');
Response::json(GeocodingService::search($db,$q));
