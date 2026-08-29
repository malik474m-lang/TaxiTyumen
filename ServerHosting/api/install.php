<?php
// GET api/install.php — одноразовая установка на хостинге:
// применяет sql/schema.sql и наполняет демо-данными (тарифы/персонал/водители).
// Удалите файл после установки!
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/Db.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = new \PDO(
        sprintf('mysql:host=%s;port=%s;charset=utf8mb4', TAXI_DB_HOST, TAXI_DB_PORT),
        TAXI_DB_USER,
        TAXI_DB_PASS,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
    // Создаём БД, если её нет (нужны права CREATE)
    $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', TAXI_DB_NAME));
    $pdo->exec(sprintf('USE `%s`', TAXI_DB_NAME));

    // Проверяем, не установлено ли уже
    $installed = false;
    try {
        $installed = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
    } catch (\Throwable) {
    }

    $sql = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
    if ($sql === false) {
        throw new \RuntimeException('sql/schema.sql не найден');
    }
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }

    foreach (glob(dirname(__DIR__) . '/src/*.php') as $file) {
        require_once $file;
    }
    $db = Db::pdo();
    Seed::ensure($db);

    echo json_encode([
        'ok' => true,
        'alreadyInstalled' => $installed,
        'database' => TAXI_DB_NAME,
        'next' => [
            '1) Удалите api/install.php с хостинга',
            '2) Поменяйте AUTH_SECRET и AUTH-пароли демо-аккаунтов',
            '3) Вход: /api/auth/login.php — админ +79001234567 / Admin123!',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
