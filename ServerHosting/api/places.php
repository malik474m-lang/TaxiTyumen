<?php
// GET api/places — популярные адреса Тюмени для подсказок
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

Response::json(Taxi::PLACES);
