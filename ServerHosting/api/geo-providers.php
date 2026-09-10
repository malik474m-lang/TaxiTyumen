<?php
// GET  /api/geo-providers.php — состав и порядок провайдеров геокодинга
// POST /api/geo-providers.php?action=toggle|primary|move — управление (admin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

GeoProviders::ensureTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    Response::json([
        'providers' => array_values(GeoProviders::all($db)),
        'active' => GeoProviders::active($db),
        'primary' => GeoProviders::primary($db),
    ]);
}

$claims = Guard::claims();
Guard::role($claims, 'admin');

Response::requireMethod('POST');
$body = Response::requirePostJson();
$action = (string) ($_GET['action'] ?? $body['action'] ?? '');
$provider = (string) ($body['provider'] ?? '');

if ($action === 'toggle') {
    GeoProviders::setEnabled($db, $provider, !empty($body['enabled']), (string) $claims['uid']);
    Response::json(['ok' => true, 'active' => GeoProviders::active($db)]);
}

if ($action === 'primary') {
    GeoProviders::setPrimary($db, $provider, (string) $claims['uid']);
    Response::json(['ok' => true, 'primary' => GeoProviders::primary($db)]);
}

if ($action === 'move') {
    GeoProviders::move($db, $provider, (int) ($body['direction'] ?? 1), (string) $claims['uid']);
    Response::json(['ok' => true, 'active' => GeoProviders::active($db)]);
}

Response::error('Неизвестный action');
