<?php
// GET  api/fleet-chat.php[?after=<ms>] — общий чат водителей автопарка (последние 150)
// POST api/fleet-chat.php {text}      — отправка; только водитель, от своего имени (токен)
// DELETE api/fleet-chat.php?id=       — модерация: удаление (operator/admin/superadmin)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $claims = Guard::claims();
    if (!FleetChat::canRead((string) ($claims['role'] ?? ''))) {
        Response::error('Чат автопарка доступен водителям, диспетчерам и администраторам', 403);
    }
    $after = (int) ($_GET['after'] ?? 0);
    $rows = FleetChat::history($db, $after);
    Response::json(array_map([FleetChat::class, 'dto'], $rows));
}

if ($method === 'POST') {
    $claims = Guard::claims();
    if (($claims['role'] ?? '') !== 'driver' || empty($claims['driverId'])) {
        Response::error('Писать в чат автопарка могут только водители', 403);
    }
    FleetChat::ensureTables($db);

    // Анти-спам по базе: не чаще 1 сообщения в 1.5 секунды
    $nowMs = (int) round(microtime(true) * 1000);
    $lastMs = FleetChat::lastSentMs($db, (string) $claims['uid']);
    if ($lastMs > 0 && $nowMs - $lastMs < FleetChat::MIN_INTERVAL_MS) {
        Response::error('Слишком часто — подождите секунду', 429);
    }

    $body = Response::requirePostJson();
    $text = trim((string) ($body['text'] ?? ''));
    if (mb_strlen($text) > FleetChat::MAX_TEXT) {
        $text = mb_substr($text, 0, FleetChat::MAX_TEXT);
    }
    if ($text === '') Response::error('Пустое сообщение');

    $d = $db->prepare('SELECT * FROM drivers WHERE id = ? LIMIT 1');
    $d->execute([(string) $claims['driverId']]);
    $driver = $d->fetch();
    $u = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $u->execute([(string) $claims['uid']]);
    $sender = $u->fetch();
    if (!$driver || !$sender) Response::error('Профиль водителя не найден', 404);
    if ((int) ($sender['is_blocked'] ?? 0) === 1) Response::error('Аккаунт заблокирован', 403);

    $senderName = trim((string) $sender['first_name'] . ' ' . (string) $sender['last_name']);
    $carInfo = trim(
        (string) $driver['car_brand'] . ' ' . (string) $driver['car_model']
        . ' · ' . (string) $driver['license_plate']
    );

    $message = FleetChat::post($db, (string) $sender['id'], $senderName, $carInfo, $text);
    Bus::publish('fleet');
    Response::json(FleetChat::dto($message), 201);
}

if ($method === 'DELETE') {
    $claims = Guard::claims();
    if (!in_array($claims['role'] ?? '', ['operator', 'admin', 'superadmin'], true)) {
        Response::error('Удалять сообщения могут только диспетчер и администратор', 403);
    }
    $id = (string) ($_GET['id'] ?? '');
    if ($id === '') Response::error('id обязателен');
    if (!FleetChat::remove($db, $id)) Response::error('Сообщение не найдено', 404);
    Bus::publish('fleet');
    Response::json(['ok' => true]);
}

Response::error('Метод не поддерживается', 405);
