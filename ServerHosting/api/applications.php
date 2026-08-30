<?php
// POST api/applications.php — ПУБЛИЧНЫЙ приём анкеты соискателя (multipart/form-data)
//      поля: firstName,lastName,phone,carBrand,carModel,licensePlate,hasChildSeat,...
//      файлы: photo_license, photo_selfie, photo_car_front/back/left/right
// GET  api/applications.php            — список для персонала (?status=new)
// GET  api/applications.php?photo=ID&field=photo_selfie — отдать фото (только персонал)
// POST {action:"status"|"approve", id, ...} — модерация (только админ)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

Applicants::ensureTables($db);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// ── Выдача фото анкеты (персонал) ───────────────────────────────────────────
if ($method === 'GET' && isset($_GET['photo'])) {
    $claims = Guard::claims();
    if (!in_array($claims['role'] ?? '', ['operator', 'admin', 'superadmin'], true)) {
        Response::error('Доступ только для персонала', 403);
    }
    $field = (string) ($_GET['field'] ?? '');
    if (!isset(Applicants::PHOTOS[$field])) Response::error('Неизвестный тип фото');

    $stmt = $db->prepare("SELECT `$field` AS f FROM driver_applications WHERE id = ? LIMIT 1");
    $stmt->execute([(string) $_GET['photo']]);
    $name = (string) ($stmt->fetchColumn() ?: '');
    $path = Applicants::storageDir() . '/' . $name;
    if ($name === '' || !is_file($path)) Response::error('Фото не найдено', 404);

    header('Content-Type: ' . (new \finfo(FILEINFO_MIME_TYPE))->file($path));
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// ── Список анкет (персонал) ─────────────────────────────────────────────────
if ($method === 'GET') {
    $claims = Guard::claims();
    if (!in_array($claims['role'] ?? '', ['operator', 'admin', 'superadmin'], true)) {
        Response::error('Доступ только для персонала', 403);
    }
    $status = (string) ($_GET['status'] ?? '');
    if ($status !== '') {
        $stmt = $db->prepare('SELECT * FROM driver_applications WHERE status = ? ORDER BY created_at DESC LIMIT 300');
        $stmt->execute([$status]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $db->query('SELECT * FROM driver_applications ORDER BY created_at DESC LIMIT 300')->fetchAll();
    }
    Response::json(array_map([Applicants::class, 'dto'], $rows));
}

// ── Модерация (JSON, только персонал) ───────────────────────────────────────
$isJson = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
if ($isJson) {
    $claims = Guard::claims();
    $body = Response::requirePostJson();
    $action = (string) ($body['action'] ?? '');
    $id = (string) ($body['id'] ?? '');
    if ($id === '') Response::error('id обязателен');

    $stmt = $db->prepare('SELECT * FROM driver_applications WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $app = $stmt->fetch();
    if (!$app) Response::error('Анкета не найдена', 404);

    if ($action === 'status') {
        if (!in_array($claims['role'] ?? '', ['operator', 'admin', 'superadmin'], true)) {
            Response::error('Недостаточно прав', 403);
        }
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, ['new', 'in_review', 'contacted', 'rejected'], true)) {
            Response::error('Недопустимый статус');
        }
        $db->prepare('UPDATE driver_applications SET status=?, review_note=?, reviewed_by=?, reviewed_at=? WHERE id=?')
            ->execute([$status, mb_substr((string) ($body['note'] ?? ''), 0, 500) ?: null,
                $claims['uid'], Db::utcNow(), $id]);
        Bus::publish('applications');
        Response::json(['ok' => true, 'status' => $status]);
    }

    if ($action === 'approve') {
        if (!Access::isAdminRole((string) ($claims['role'] ?? ''))) {
            Response::error('Создавать учётную запись может только администратор', 403);
        }
        try {
            $result = Applicants::approve($db, $app, (string) $claims['uid']);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 409);
        }
        Response::json(['ok' => true] + $result, 201);
    }

    Response::error("Неизвестное действие: $action");
}

// ── ПУБЛИЧНЫЙ приём анкеты с сайта-лендинга (multipart/form-data) ───────────
$field = static fn(string $k, int $max = 120): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);

$firstName = $field('firstName', 60);
$lastName = $field('lastName', 60);
$phoneRaw = $field('phone', 25);
$carBrand = $field('carBrand', 40);
$carModel = $field('carModel', 40);
$plate = mb_strtoupper($field('licensePlate', 15));

$errors = [];
if (mb_strlen($firstName) < 2) $errors[] = 'Укажите имя';
if (mb_strlen($lastName) < 2) $errors[] = 'Укажите фамилию';
$phone = Auth::normalizePhone($phoneRaw);
if (mb_strlen($phone) < 11) $errors[] = 'Укажите корректный телефон';
if ($carBrand === '' || $carModel === '') $errors[] = 'Укажите марку и модель автомобиля';
if (mb_strlen($plate) < 5) $errors[] = 'Укажите госномер автомобиля';
foreach (Applicants::PHOTOS as $key => $label) {
    if (($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = "Загрузите: $label";
}
if ($errors) Response::json(['error' => implode('. ', $errors), 'errors' => $errors], 422);

// Антиспам: не больше 3 анкет с одного телефона в сутки
$recent = $db->prepare(
    'SELECT COUNT(*) FROM driver_applications WHERE phone = ? AND created_at > ?'
);
$recent->execute([$phone, gmdate('Y-m-d H:i:s', time() - 86400)]);
if ((int) $recent->fetchColumn() >= 3) {
    Response::error('Заявка уже отправлена. Мы свяжемся с вами по указанному телефону', 429);
}

$id = Db::uuid();
$photos = [];
try {
    foreach (array_keys(Applicants::PHOTOS) as $key) {
        $photos[$key] = Applicants::storePhoto($id, $key, $_FILES[$key]);
    }
} catch (\Throwable $e) {
    foreach ($photos as $name) @unlink(Applicants::storageDir() . '/' . $name);
    Response::error($e->getMessage(), 422);
}

$db->prepare(
    'INSERT INTO driver_applications
     (id, first_name, last_name, middle_name, phone, birth_date, city, license_number, license_expiry,
      experience_years, car_brand, car_model, car_color, car_year, license_plate, has_child_seat,
      child_seat_note, comment, photo_license, photo_selfie, photo_car_front, photo_car_back,
      photo_car_left, photo_car_right, source_ip, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
)->execute([
    $id, $firstName, $lastName, $field('middleName', 60) ?: null, $phone,
    $field('birthDate', 10) ?: null, $field('city', 80) ?: null,
    $field('licenseNumber', 40) ?: null, $field('licenseExpiry', 10) ?: null,
    (int) ($_POST['experienceYears'] ?? 0),
    $carBrand, $carModel, $field('carColor', 30), (int) ($_POST['carYear'] ?? 2015), $plate,
    !empty($_POST['hasChildSeat']) && $_POST['hasChildSeat'] !== '0' ? 1 : 0,
    $field('childSeatNote', 120) ?: null,
    mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 500) ?: null,
    $photos['photo_license'], $photos['photo_selfie'], $photos['photo_car_front'],
    $photos['photo_car_back'], $photos['photo_car_left'], $photos['photo_car_right'],
    mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), Db::utcNow(),
]);

// Уведомляем диспетчерскую и админов о новой анкете
try {
    foreach (['operator', 'admin'] as $role) {
        NotificationService::sendToRole(
            $db, $role, 'NewDriverApplication', 'Новая анкета водителя',
            sprintf('%s %s · %s · %s %s (%s)', $lastName, $firstName, $phone, $carBrand, $carModel, $plate),
            null, ['applicationId' => $id]
        );
    }
} catch (\Throwable) {
}

Bus::publish('applications');
Response::json([
    'ok' => true,
    'id' => $id,
    'message' => 'Анкета принята. Мы свяжемся с вами по телефону ' . $phone,
], 201);
