<?php
// GET /api/ — информация о сервере и список эндпоинтов (health-check для браузера)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

Response::json([
    'service' => 'TaxiTyumen ServerHosting (PHP + MySQL)',
    'status' => 'online',
    'time' => gmdate('c'),
    'php' => PHP_VERSION,
    'endpoints' => [
        'POST /api/auth/login.php',
        'POST /api/auth/register.php',
        'POST /api/auth/sms.php (action=send|verify)',
        'POST /api/auth/password.php (Bearer)',
        'GET  /api/orders/?view=active|available|history|all|clientActive|driverCurrent|today',
        'POST /api/orders/ (создание, client)',
        'POST /api/orders/operator.php (operator/admin)',
        'GET  /api/orders/item.php?id=',
        'POST /api/orders/action.php',
        'GET  /api/drivers/?online=1',
        'POST /api/drivers/action.php',
        'GET  /api/tariffs/ · PUT /api/tariffs/ (admin)',
        'POST /api/pricing.php',
        'GET/POST /api/chat.php (чат + mark read)',
        'GET/POST /api/notifications.php (in-app/SMS уведомления)',
        'GET  /api/geocoding.php?q= или ?lat=&lng= (DaData/Nominatim)',
        'GET  /api/services.php (admin diagnostics)',
        'GET  /api/places.php',
        'GET  /api/branding.php?app= · PUT (admin)',
        'GET/POST /api/branding-logo.php?app= (логотип бренда)',
        'GET/POST /api/operators/shift.php (operator)',
        'GET/PUT /api/autocall.php (staff)',
        'GET  /api/stats.php (admin)',
        'GET  /api/export/orders.php (admin, CSV)',
        'GET  /api/events.php?since=',
    ],
]);
