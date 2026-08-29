<?php
// POST api/auth/login — порт AuthService.LoginAsync → { user, token }
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Response::requireMethod('POST');

$body = Response::requirePostJson();
$phone = Auth::normalizePhone((string) ($body['phone'] ?? ''));
$password = (string) ($body['password'] ?? '');

$stmt = $db->prepare('SELECT * FROM users WHERE phone = ? LIMIT 1');
$stmt->execute([$phone]);
$user = $stmt->fetch();

if (!$user) {
    Response::error('Пользователь не найден', 404);
}
if (!Auth::verifyPassword($password, $user['password_hash'])) {
    Response::error('Неверный пароль', 401);
}
if ($user['is_blocked']) {
    Response::error('Аккаунт заблокирован: ' . ($user['block_reason'] ?? ''), 403);
}
if (!$user['is_active']) {
    Response::error('Аккаунт деактивирован', 403);
}

$db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Db::utcNow(), $user['id']]);

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
