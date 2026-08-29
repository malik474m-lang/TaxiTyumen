<?php
// GET /api/service-settings.php — публичный бренд сервиса (название, город, центр)
// PUT /api/service-settings.php — изменение (только admin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Response::json(ServiceSettings::toDto(ServiceSettings::get($db)));
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $claims = Guard::claims();
    // Бренд сервиса меняет только супер-администратор
    Guard::superadmin($claims);
    $body = Response::requirePostJson();
    try {
        $updated = ServiceSettings::update($db, $body);
        Bus::publish('branding');
        Response::json(ServiceSettings::toDto($updated));
    } catch (Throwable $e) {
        Response::error($e->getMessage(), 422);
    }
}

Response::error('Метод не поддерживается', 405);
