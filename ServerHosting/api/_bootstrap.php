<?php
// Общий бутстрап всех api-эндпоинтов: конфиг, ядро, CORS, сиды, live-тики
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once dirname(__DIR__) . '/config.php';
foreach (glob(dirname(__DIR__) . '/src/*.php') as $file) {
    require_once $file;
}

// CORS (для отдельного домена/PWA фронтенда)
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Сиды и живые тики — best effort, не ломаем запрос
try {
    $db = Db::pdo();
    Seed::ensure($db);
} catch (\Throwable $e) {
    Response::error('MySQL недоступна: ' . $e->getMessage(), 503);
}
