<?php
// GET api/autocall — настройки (оператор/админ) | PUT — изменение (админ)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $claims = Guard::claims();
    Guard::role($claims, 'operator', 'admin');
    $s = AutoCall::getSettings($db);
    Response::json([
        'id' => $s['id'],
        'enabled' => (bool) $s['enabled'],
        'escalateAfterMinutes' => (int) $s['escalate_after_minutes'],
        'autoAssignEnabled' => (bool) $s['auto_assign_enabled'],
        'autoAssignRadiusKm' => (float) $s['auto_assign_radius_km'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $claims = Guard::claims();
    Guard::role($claims, 'admin');

    $body = Response::requirePostJson();
    $s = AutoCall::getSettings($db);

    $enabled = isset($body['enabled']) ? ((bool) $body['enabled'] ? 1 : 0) : $s['enabled'];
    $minutes = max(1, min(60, (int) ($body['escalateAfterMinutes'] ?? $s['escalate_after_minutes'])));
    $autoAssign = isset($body['autoAssignEnabled']) ? ((bool) $body['autoAssignEnabled'] ? 1 : 0) : $s['auto_assign_enabled'];
    $radius = max(1, min(30, (float) ($body['autoAssignRadiusKm'] ?? $s['auto_assign_radius_km'])));

    $db->prepare(
        'UPDATE auto_call_settings SET enabled = ?, escalate_after_minutes = ?, auto_assign_enabled = ?, auto_assign_radius_km = ? WHERE id = ?'
    )->execute([$enabled, $minutes, $autoAssign, $radius, $s['id']]);

    Bus::publish('autocall');
    Response::json([
        'enabled' => (bool) $enabled,
        'escalateAfterMinutes' => $minutes,
        'autoAssignEnabled' => (bool) $autoAssign,
        'autoAssignRadiusKm' => $radius,
    ]);
}

Response::error('Метод не поддерживается', 405);
