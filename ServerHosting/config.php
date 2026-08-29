<?php
// ═══════════════════════════════════════════════════════════════════════════
// TaxiTyumen — базовый конфиг (PHP 8+ / MySQL)
// Реальные секреты храните в config.local.php (он игнорируется Git):
//   cp config.protected.php config.local.php
//   nano config.local.php
// ═══════════════════════════════════════════════════════════════════════════

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

if (!defined('TAXI_DB_HOST')) define('TAXI_DB_HOST', getenv('TAXI_DB_HOST') ?: 'localhost');
if (!defined('TAXI_DB_PORT')) define('TAXI_DB_PORT', getenv('TAXI_DB_PORT') ?: '3306');
if (!defined('TAXI_DB_NAME')) define('TAXI_DB_NAME', getenv('TAXI_DB_NAME') ?: 'taxi_tyumen');
if (!defined('TAXI_DB_USER')) define('TAXI_DB_USER', getenv('TAXI_DB_USER') ?: 'root');
if (!defined('TAXI_DB_PASS')) define('TAXI_DB_PASS', getenv('TAXI_DB_PASS') ?: '');

// Секрет для подписи сессионных токенов — ОБЯЗАТЕЛЬНО поменяйте в проде
if (!defined('AUTH_SECRET')) define('AUTH_SECRET', getenv('AUTH_SECRET') ?: 'change-me-to-long-random-string');

// (опционально) ключ sms.ru для реальной отправки SMS
if (!defined('SMS_API_ID')) define('SMS_API_ID', getenv('SMS_API_ID') ?: '');

// Тюмень UTC+5 — сдвиг для ценообразования/статистики
if (!defined('CITY_UTC_OFFSET')) define('CITY_UTC_OFFSET', 5);

// CORS: домен фронтенда (или '*' на время разработки)
if (!defined('CORS_ORIGIN')) define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: '*');

// Внутренний временной пояс БД: все DATETIME хранятся в UTC
date_default_timezone_set('UTC');
