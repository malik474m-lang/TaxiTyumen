<?php
// POST api/sos.php {latitude,longitude,orderId?,comment?} — поднять тревогу (только водитель)
// GET  api/sos.php[?history=1]                            — активные тревоги (водители/операторы/админ)
// POST api/sos.php {action:"resolve", id}                 — снять тревогу (автор, оператор, админ)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$claims = Guard::claims();
$role = (string) ($claims['role'] ?? '');
$canSee = in_array($role, ['driver', 'operator', 'admin', 'superadmin'], true);

if ($method === 'GET') {
    if (!$canSee) Response::error('Тревоги доступны водителям и диспетчерской', 403);
    $rows = !empty($_GET['history']) && in_array($role, ['operator', 'admin', 'superadmin'], true)
        ? Sos::history($db, (int) ($_GET['limit'] ?? 100))
        : Sos::active($db);
    Response::json(array_map([Sos::class, 'dto'], $rows));
}

$body = Response::requirePostJson();
$action = (string) ($body['action'] ?? 'raise');

if ($action === 'resolve') {
    $id = (string) ($body['id'] ?? '');
    if ($id === '') Response::error('id обязателен');

    Sos::ensureTables($db);
    $stmt = $db->prepare('SELECT * FROM sos_alerts WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $alert = $stmt->fetch();
    if (!$alert) Response::error('Тревога не найдена', 404);

    $isAuthor = ($alert['user_id'] ?? '') === ($claims['uid'] ?? '');
    $isStaff = in_array($role, ['operator', 'admin', 'superadmin'], true);
    if (!$isAuthor && !$isStaff) {
        Response::error('Снять тревогу может автор или диспетчерская', 403);
    }

    Sos::resolve($db, $id, (string) $claims['uid']);
    Response::json(['ok' => true, 'id' => $id, 'status' => 'resolved']);
}

// raise — только водитель, от своего имени
if ($role !== 'driver' || empty($claims['driverId'])) {
    Response::error('Тревожную кнопку может нажать только водитель', 403);
}

$d = $db->prepare('SELECT * FROM drivers WHERE id=? LIMIT 1');
$d->execute([(string) $claims['driverId']]);
$driver = $d->fetch();
$u = $db->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
$u->execute([(string) $claims['uid']]);
$user = $u->fetch();
if (!$driver || !$user) Response::error('Профиль водителя не найден', 404);

// Координаты: из запроса, иначе последняя известная позиция водителя
$lat = isset($body['latitude']) ? (float) $body['latitude'] : (float) $driver['latitude'];
$lng = isset($body['longitude']) ? (float) $body['longitude'] : (float) $driver['longitude'];
if ($lat == 0.0 && $lng == 0.0) {
    $lat = (float) $driver['latitude'];
    $lng = (float) $driver['longitude'];
}

$orderId = !empty($body['orderId']) ? (string) $body['orderId'] : ($driver['current_order_id'] ?: null);
$comment = isset($body['comment']) ? mb_substr(trim((string) $body['comment']), 0, 300) : null;

$alert = Sos::raise($db, $driver, $user, $lat, $lng, $orderId, $comment ?: null);

// Синхронизируем позицию водителя — диспетчер видит точку сразу
try {
    $db->prepare('UPDATE drivers SET latitude=?, longitude=?, last_location_update=? WHERE id=?')
        ->execute([$lat, $lng, Db::utcNow(), $driver['id']]);
} catch (\Throwable) {
}

Response::json(Sos::dto($alert), 201);
