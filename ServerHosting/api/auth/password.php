<?php
// POST api/auth/password.php — смена своего пароля (все роли)
// Headers: Authorization: Bearer <token>
// Валидация ИЛИ верным старым паролем, ИЛИ свежим SMS-кодом:
//   {"oldPassword":"...","newPassword":"..."}  — по старому паролю
//   {"smsCode":"1234","newPassword":"..."}     — по SMS (для SMS-регистраций)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Response::requireMethod('POST');

$claims = Guard::claims();
$body = Response::requirePostJson();

$newPassword = (string) ($body['newPassword'] ?? '');
if (strlen($newPassword) < 6) {
    Response::error('Новый пароль — минимум 6 символов');
}

$stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$claims['uid']]);
$user = $stmt->fetch();
if (!$user) {
    Response::error('Пользователь не найден', 404);
}

$ok = false;
$oldPassword = (string) ($body['oldPassword'] ?? '');
$smsCode = trim((string) ($body['smsCode'] ?? ''));

if ($oldPassword !== '' && Auth::verifyPassword($oldPassword, $user['password_hash'])) {
    $ok = true;
} elseif (
    $smsCode !== ''
    && !empty($user['sms_code'])
    && hash_equals((string) $user['sms_code'], $smsCode)
    && (!empty($user['sms_code_expiry']) && strtotime($user['sms_code_expiry'] . ' UTC') >= time())
) {
    $ok = true;
    // Код — одноразовый
    $db->prepare('UPDATE users SET sms_code = NULL, sms_code_expiry = NULL WHERE id = ?')
        ->execute([$user['id']]);
}

if (!$ok) {
    Response::error('Подтвердите личность: верный старый пароль или SMS-код', 401);
}

$db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
    ->execute([Auth::hashPassword($newPassword), $user['id']]);

Response::json(['ok' => true]);
