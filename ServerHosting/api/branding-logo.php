<?php
// GET  /api/branding-logo.php?app=client — публичная выдача логотипа
// POST /api/branding-logo.php — загрузка/удаление (admin Bearer, multipart/form-data)
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$app = (string) ($_GET['app'] ?? $_POST['app'] ?? '');
if (!in_array($app, Branding::APPS, true)) {
    Response::error('Неизвестное приложение', 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT logo_path FROM branding_settings WHERE app = ? LIMIT 1');
    $stmt->execute([$app]);
    $path = BrandingLogo::absolutePath($stmt->fetchColumn() ?: null);
    if (!$path) {
        Response::error('Свой логотип не загружен', 404);
    }

    $etag = '"' . sha1_file($path) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: ' . BrandingLogo::mimeFor($path));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=3600, must-revalidate');
    header('ETag: ' . $etag);
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $claims = Guard::claims();
    Guard::role($claims, 'admin');

    if (($_POST['action'] ?? '') === 'remove') {
        BrandingLogo::remove($db, $app);
        Bus::publish('branding');
        Response::json(['ok' => true, 'logoUrl' => null]);
    }

    try {
        BrandingLogo::store($db, $app, $_FILES['logo'] ?? []);
        Bus::publish('branding');
        Response::json([
            'ok' => true,
            'logoUrl' => '/api/branding-logo.php?app=' . rawurlencode($app) . '&v=' . time(),
        ]);
    } catch (\Throwable $e) {
        Response::error($e->getMessage(), 422);
    }
}

Response::error('Метод не поддерживается', 405);
