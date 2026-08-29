<?php
// ═══════════════════════════════════════════════════════════════════════════
// TaxiTyumen — серверная часть для shared-хостинга (PHP 8+ / MySQL)
// Настройте подключение к БД ниже или через переменные окружения
// ═══════════════════════════════════════════════════════════════════════════

define('TAXI_DB_HOST', getenv('TAXI_DB_HOST') ?: 'localhost');
define('TAXI_DB_PORT', getenv('TAXI_DB_PORT') ?: '3306');
define('TAXI_DB_NAME', getenv('TAXI_DB_NAME') ?: 'taxi_tyumen');
define('TAXI_DB_USER', getenv('TAXI_DB_USER') ?: 'root');
define('TAXI_DB_PASS', getenv('TAXI_DB_PASS') ?: '');

// Секрет для подписи сессионных токенов — ОБЯЗАТЕЛЬНО поменяйте в проде
define('AUTH_SECRET', getenv('AUTH_SECRET') ?: 'change-me-to-long-random-string');

// (опционально) ключ sms.ru для реальной отправки SMS
define('SMS_API_ID', getenv('SMS_API_ID') ?: '');

// Тюмень UTC+5 — сдвиг для ценообразования/статистики
define('CITY_UTC_OFFSET', 5);

// CORS: домен фронтенда (или '*' на время разработки)
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: '*');

// Внутренний временной пояс БД: все DATETIME хранятся в UTC
date_default_timezone_set('UTC');
