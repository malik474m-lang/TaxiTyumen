<?php
// GET  /api/drivers/photo.php?driverId=&kind=driver|license|car — выдача фото
// POST /api/drivers/photo.php — загрузка/удаление (admin, multipart/form-data)
// Документы приватны: доступ только персоналу и самому водителю.
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

$driverId = (string) ($_GET['driverId'] ?? $_POST['driverId'] ?? '');
$kind = (string) ($_GET['kind'] ?? $_POST['kind'] ?? '');
if ($driverId === '' || !isset(DriverPhotos::KINDS[$kind])) {
    Response::error('Укажите driverId и kind (driver|license|car)', 400);
}

$claims = Guard::claims();
$role = (string) ($claims['role'] ?? '');
$isStaff = in_array($role, ['operator', 'admin', 'superadmin'], true);
$isOwner = $role === 'driver' && ($claims['driverId'] ?? '') === $driverId;
if (!$isStaff && !$isOwner) {
    Response::error('Доступ к документам водителя ограничен', 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $path = DriverPhotos::absolutePath(DriverPhotos::currentFile($db, $driverId, $kind));
    if (!$path) {
        Response::error('Фото не загружено', 404);
    }
    $etag = '"' . sha1_file($path) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . DriverPhotos::mimeFor($path));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=600, must-revalidate');
    header('ETag: ' . $etag);
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Guard::role($claims, 'admin');
    try {
        if (($_POST['action'] ?? '') === 'remove') {
            DriverPhotos::remove($db, $driverId, $kind);
            Bus::publish('drivers');
            Response::json(['ok' => true, 'url' => null]);
        }
        $name = DriverPhotos::store($db, $driverId, $kind, $_FILES['photo'] ?? []);
        Bus::publish('drivers');
        Response::json(['ok' => true, 'url' => DriverPhotos::url($driverId, $kind, $name)]);
    } catch (Throwable $e) {
        Response::error($e->getMessage(), 422);
    }
}

Response::error('Метод не поддерживается', 405);
