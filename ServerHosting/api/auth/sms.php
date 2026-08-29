<?php
// POST api/auth/sms — SendSmsCodeAsync / VerifySmsCodeAsync (action=send|verify)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Response::requireMethod('POST');

$body = Response::requirePostJson();
$action = (string) ($body['action'] ?? '');
$phone = Auth::normalizePhone((string) ($body['phone'] ?? ''));
if (strlen($phone) < 11) {
    Response::error('Укажите корректный телефон');
}

$stmt = $db->prepare('SELECT * FROM users WHERE phone = ? LIMIT 1');
$stmt->execute([$phone]);
$user = $stmt->fetch() ?: null;

if ($action === 'send') {
    $code = (string) random_int(1000, 9999);
    $expiry = gmdate('Y-m-d H:i:s', time() + 300);

    if ($user) {
        if ($user['is_blocked']) {
            Response::error('Аккаунт заблокирован: ' . ($user['block_reason'] ?? ''), 403);
        }
        $db->prepare('UPDATE users SET sms_code = ?, sms_code_expiry = ? WHERE id = ?')
            ->execute([$code, $expiry, $user['id']]);
    } else {
        // Авто-регистрация клиента по номеру тела
        $db->prepare(
            'INSERT INTO users (id, phone, first_name, last_name, password_hash, role, sms_code, sms_code_expiry)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            Db::uuid(), $phone, 'Клиент', substr($phone, -4),
            Auth::hashPassword(bin2hex(random_bytes(16))), 'client', $code, $expiry,
        ]);
    }

    // Реальная отправка через единый sms.ru сервис + журнал API
    $service = ServiceSettings::get($db);
    $sms = SmsService::send($db, $phone, "$code — ваш код " . $service['sms_sender_name']);
    $sent = ($sms['status'] ?? '') === 'sent';

    error_log("[SMS] Код для $phone: $code"); // как Console.WriteLine в оригинале
    Response::json([
        'ok' => true,
        'expiresIn' => 300,
        'smsProvider' => $sent ? 'sms.ru' : null,
        'deliveryStatus' => $sms['status'] ?? 'failed',
        // Демо-режим без провайдера — код возвращается (как вывод в консоль в .NET)
        'devCode' => $sent ? null : $code,
    ]);
}

if ($action === 'verify') {
    $code = trim((string) ($body['code'] ?? ''));
    if (!$user) {
        Response::error('Пользователь не найден', 404);
    }
    if (!$user['sms_code'] || $user['sms_code'] !== $code) {
        Response::error('Неверный код', 401);
    }
    if ($user['sms_code_expiry'] && strtotime($user['sms_code_expiry'] . ' UTC') < time()) {
        Response::error('Код истёк, запросите новый', 410);
    }
    if ($user['is_blocked']) {
        Response::error('Аккаунт заблокирован: ' . ($user['block_reason'] ?? ''), 403);
    }

    $db->prepare(
        'UPDATE users SET sms_code = NULL, sms_code_expiry = NULL, is_phone_verified = 1, last_login_at = ? WHERE id = ?'
    )->execute([Db::utcNow(), $user['id']]);

    $driverId = null;
    if ($user['role'] === 'driver') {
        $d = $db->prepare('SELECT id FROM drivers WHERE user_id = ? LIMIT 1');
        $d->execute([$user['id']]);
        $driverId = $d->fetchColumn() ?: null;
    }
    $token = Auth::signToken($user['id'], $user['role'], $driverId);
    if (!empty($GLOBALS['auth_compat_response'])) {
        Response::json(Serialize::auth($user, $driverId, $token));
    }
    Response::json([
        'user' => array_merge(Serialize::user($user, $driverId), ['token' => $token]),
    ]);
}

Response::error("Неизвестный action: $action");
