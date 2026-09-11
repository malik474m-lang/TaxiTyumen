<?php
// API встроенного SMS-шлюза: сервер ↔ Android-телефон с SIM-картой.
//
//   GET  /api/sms-gateway.php?action=poll[&limit=5]   — забрать задания
//   POST /api/sms-gateway.php  {action:"ack", id, success, error}
//   POST /api/sms-gateway.php  {action:"heartbeat", battery, phone, device}
//   GET  /api/sms-gateway.php?action=status           — состояние (admin)
//
// Устройство авторизуется собственным токеном: заголовок
//   Authorization: Bearer <токен из админки>
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

SmsGateway::ensureTables($db);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$body = $method === 'POST' ? Response::requirePostJson() : [];
$action = strtolower((string) ($_GET['action'] ?? $body['action'] ?? ''));

/** Токен устройства из заголовка Authorization. */
$deviceToken = static function (): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($header === null && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) { $header = $value; break; }
        }
    }
    if (!is_string($header)) return null;
    $header = trim($header);
    return str_starts_with($header, 'Bearer ') ? substr($header, 7) : $header;
};

/** Доступ устройства: только по токену шлюза и только при включённом сервисе. */
$requireDevice = static function () use ($db, $deviceToken): array {
    $settings = SmsGateway::settings($db);
    if (!$settings['enabled']) {
        Response::error('SMS-шлюз выключен в админке', 403);
    }
    if (!SmsGateway::authenticate($db, $deviceToken())) {
        Response::error('Неверный токен устройства', 401);
    }
    return $settings;
};

// ── Устройство забирает задания ─────────────────────────────────────────────
if ($action === 'poll') {
    $settings = $requireDevice();
    SmsGateway::heartbeat($db, [
        'battery' => $_GET['battery'] ?? null,
        'device' => (string) ($_GET['device'] ?? ''),
        'phone' => (string) ($_GET['phone'] ?? ''),
    ]);

    $messages = SmsGateway::lease(
        $db,
        (int) ($_GET['limit'] ?? 5),
        (string) ($_GET['device'] ?? '')
    );

    Response::json([
        'ok' => true,
        'messages' => $messages,
        // Телефон подстраивает интервал опроса под настройку сервера
        'pollSeconds' => (int) ($settings['poll_seconds'] ?? 10),
        'serverTime' => gmdate('c'),
    ]);
}

// ── Устройство подтверждает результат отправки ──────────────────────────────
if ($action === 'ack') {
    Response::requireMethod('POST');
    $requireDevice();

    // Пакетное подтверждение: {results:[{id,success,error}, ...]}
    $results = is_array($body['results'] ?? null) ? $body['results'] : [$body];
    $done = 0;
    foreach ($results as $item) {
        $id = (string) ($item['id'] ?? '');
        if ($id === '') continue;
        $success = filter_var($item['success'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (SmsGateway::acknowledge($db, $id, $success, (string) ($item['error'] ?? ''))) {
            $done++;
        }
    }
    SmsGateway::heartbeat($db, ['device' => (string) ($body['device'] ?? '')]);
    Response::json(['ok' => true, 'accepted' => $done]);
}

// ── Телефон просто отмечается на связи ──────────────────────────────────────
if ($action === 'heartbeat') {
    Response::requireMethod('POST');
    $requireDevice();
    SmsGateway::heartbeat($db, [
        'battery' => $body['battery'] ?? null,
        'phone' => (string) ($body['phone'] ?? ''),
        'device' => (string) ($body['device'] ?? ''),
    ]);
    Response::json(['ok' => true, 'serverTime' => gmdate('c')]);
}

// ── Состояние шлюза: администратор ИЛИ само устройство ──────────────────────
if ($action === 'status') {
    $isDevice = SmsGateway::authenticate($db, $deviceToken());
    if (!$isDevice) {
        $claims = Guard::claims();
        Guard::role($claims, 'admin');
    }
    $settings = SmsGateway::settings($db);
    Response::json([
        'enabled' => $settings['enabled'],
        'online' => $settings['online'],
        'deviceName' => $settings['device_name'] ?? '',
        'devicePhone' => $settings['device_phone'] ?? null,
        'battery' => $settings['battery'] !== null ? (int) $settings['battery'] : null,
        'lastSeenAt' => $settings['last_seen_at'] ?? null,
        'pollSeconds' => (int) ($settings['poll_seconds'] ?? 10),
        'dailyLimit' => (int) ($settings['daily_limit'] ?? 0),
        'queue' => SmsGateway::stats($db),
    ]);
}

// ── Ручная постановка сообщения в очередь (администратор) ───────────────────
if ($action === 'send') {
    Response::requireMethod('POST');
    $claims = Guard::claims();
    Guard::role($claims, 'admin', 'operator');

    $phone = Auth::normalizePhone((string) ($body['phone'] ?? ''));
    $text = trim((string) ($body['message'] ?? ''));
    if (strlen($phone) < 11 || $text === '') {
        Response::error('Укажите телефон и текст сообщения');
    }
    if (!SmsGateway::isEnabled($db)) {
        Response::error('SMS-шлюз выключен в админке', 409);
    }
    $id = SmsGateway::enqueue($db, $phone, $text, 'manual');
    Response::json(['ok' => true, 'id' => $id, 'status' => 'queued'], 201);
}

Response::error('Неизвестное действие: ' . ($action ?: '—'));
