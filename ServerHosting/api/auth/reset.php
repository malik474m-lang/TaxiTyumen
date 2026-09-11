<?php
// POST api/auth/reset — восстановление пароля по SMS-коду (action=send|confirm).
// Отличие от входа по SMS: здесь пользователь уже зарегистрирован, токен не
// выдаётся — только подтверждение владения номером и установка нового пароля.
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

/** Проверки состояния аккаунта (общие для обоих действий). */
$assertAccountUsable = function () use ($user): void {
    if (!$user) {
        Response::error('Пользователь с таким номером не найден', 404);
    }
    if ($user['is_blocked']) {
        Response::error('Аккаунт заблокирован: ' . ($user['block_reason'] ?? ''), 403);
    }
    if (!empty($user['is_archived'])) {
        Response::error('Аккаунт перенесён в архив. Обратитесь к администратору.', 403);
    }
    // Супер-администратор восстанавливается только через SUPERADMIN_RECOVERY
    if ($user['role'] === 'superadmin') {
        Response::error('Сброс пароля супер-администратора невозможен через SMS', 403);
    }
};

if ($action === 'send') {
    $assertAccountUsable();

    // Запрос кода — тот же механизм, что и вход по SMS (sms_code + expiry 5 минут)
    $code = (string) random_int(1000, 9999);
    $expiry = gmdate('Y-m-d H:i:s', time() + 300);
    $db->prepare('UPDATE users SET sms_code = ?, sms_code_expiry = ? WHERE id = ?')
        ->execute([$code, $expiry, $user['id']]);

    $service = ServiceSettings::get($db);
    $sms = SmsService::send($db, $phone, "$code — код восстановления пароля " . $service['sms_sender_name'], 'password_reset');
    $sent = ($sms['status'] ?? '') === 'sent';

    error_log("[SMS] Код восстановления пароля для $phone: $code");
    Response::json([
        'ok' => true,
        'expiresIn' => 300,
        'smsProvider' => $sent ? 'sms.ru' : null,
        'deliveryStatus' => $sms['status'] ?? 'failed',
        // Демо-режим без провайдера — код возвращается (как вывод в консоль в .NET)
        'devCode' => $sent ? null : $code,
    ]);
}

if ($action === 'confirm') {
    $assertAccountUsable();

    $code = trim((string) ($body['code'] ?? ''));
    $newPassword = (string) ($body['newPassword'] ?? $body['password'] ?? '');

    if (!$user['sms_code'] || $user['sms_code'] !== $code) {
        Response::error('Неверный код', 401);
    }
    if ($user['sms_code_expiry'] && strtotime($user['sms_code_expiry'] . ' UTC') < time()) {
        Response::error('Код истёк, запросите новый', 410);
    }
    if (mb_strlen($newPassword) < 6) {
        Response::error('Пароль должен быть не короче 6 символов');
    }

    // Пароль меняем, одноразовый код гасим — повторное использование исключено
    $db->prepare(
        'UPDATE users SET password_hash = ?, sms_code = NULL, sms_code_expiry = NULL, is_phone_verified = 1 WHERE id = ?'
    )->execute([Auth::hashPassword($newPassword), $user['id']]);

    Response::json(['ok' => true]);
}

Response::error("Неизвестный action: $action");
