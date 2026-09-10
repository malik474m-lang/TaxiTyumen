<?php
// GET  /api/tomtom.php — состояние всех сервисов TomTom (admin)
// PUT  /api/tomtom.php — включение/выключение сервиса (admin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $claims = Guard::claims();
    Guard::role($claims, 'admin');
    Response::json([
        'hasKey' => TomTom::hasKey(),
        'usedToday' => TomTom::apiUsedToday($db),
        'dailyLimit' => TomTom::FREE_DAILY_API,
        'services' => array_values(TomTom::all($db)),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $claims = Guard::claims();
    Guard::role($claims, 'admin');
    $body = Response::requirePostJson();
    $service = (string) ($body['service'] ?? '');
    try {
        TomTom::setEnabled($db, $service, !empty($body['enabled']), (string) ($claims['uid'] ?? ''));
        Bus::publish('branding');   // приложения перечитают конфигурацию карт
        Response::json(['services' => array_values(TomTom::all($db))]);
    } catch (Throwable $e) {
        Response::error($e->getMessage(), 422);
    }
}

Response::error('Метод не поддерживается', 405);
