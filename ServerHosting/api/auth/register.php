<?php
// POST api/auth/register — порт AuthService.RegisterAsync (+ профиль водителя)
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

Response::requireMethod('POST');

$body = Response::requirePostJson();
$phone = Auth::normalizePhone((string) ($body['phone'] ?? ''));
$password = (string) ($body['password'] ?? '');
$firstName = trim((string) ($body['firstName'] ?? ''));
$lastName = trim((string) ($body['lastName'] ?? ''));
$role = strtolower((string) ($body['role'] ?? '')) === 'driver' ? 'driver' : 'client';

if (strlen($phone) < 11) {
    Response::error('Укажите корректный телефон');
}
if (strlen($password) < 6) {
    Response::error('Пароль — минимум 6 символов');
}
if ($firstName === '') {
    Response::error('Укажите имя');
}

$exists = $db->prepare('SELECT id FROM users WHERE phone = ?');
$exists->execute([$phone]);
if ($exists->fetch()) {
    Response::error('Пользователь с таким номером уже существует', 409);
}

$uid = Db::uuid();
$db->prepare(
    'INSERT INTO users (id, phone, first_name, last_name, email, password_hash, role)
     VALUES (?,?,?,?,?,?,?)'
)->execute([$uid, $phone, $firstName, $lastName !== '' ? $lastName : $firstName, $body['email'] ?? null, Auth::hashPassword($password), $role]);

$driverId = null;
if ($role === 'driver') {
    $driverId = Db::uuid();
    $service = ServiceSettings::get($db);
    $jx = (mt_rand(-1000, 1000) / 1000000) * 5; // ~±0.005 градуса
    $jx2 = (mt_rand(-1000, 1000) / 1000000) * 5;
    $db->prepare(
        'INSERT INTO drivers (id, user_id, car_brand, car_model, car_color, license_plate, car_year,
         latitude, longitude, balance, rejection_penalty)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $driverId, $uid,
        (string) ($body['carBrand'] ?? 'Авто'),
        (string) ($body['carModel'] ?? ''),
        (string) ($body['carColor'] ?? 'Белый'),
        (string) ($body['licensePlate'] ?? '—'),
        (int) ($body['carYear'] ?? 2020),
        $service['center_latitude'] + $jx2, $service['center_longitude'] + $jx,
        500, 50,
    ]);
}

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

$token = Auth::signToken($uid, $role, $driverId);
if (!empty($GLOBALS['auth_compat_response'])) {
    Response::json(Serialize::auth($user, $driverId, $token), 201);
}
Response::json([
    'user' => array_merge(Serialize::user($user, $driverId), ['token' => $token]),
], 201);
