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

// (опционально) DaData Suggestions API — ключ хранится только на сервере
if (!defined('DADATA_API_KEY')) define('DADATA_API_KEY', getenv('DADATA_API_KEY') ?: '');

// (опционально) OpenCage Geocoding API — независимый резервный провайдер
// геокодинга (OSM, РФ покрыта хорошо). Получить ключ: opencagedata.com
if (!defined('OPENCAGE_API_KEY')) define('OPENCAGE_API_KEY', getenv('OPENCAGE_API_KEY') ?: '');

// (опционально) TomTom Traffic API — растровый слой пробок в приложении водителя
// Получить ключ: https://developer.tomtom.com/ (бесплатно 2500 запросов/сутки)
if (!defined('TOMTOM_API_KEY')) define('TOMTOM_API_KEY', getenv('TOMTOM_API_KEY') ?: '');

// Яндекс Карты JavaScript API 2.1 — публичный ключ с ограничением по домену
// Получить: https://developer.tech.yandex.ru/services/
if (!defined('YANDEX_MAPS_API_KEY')) {
    define('YANDEX_MAPS_API_KEY', getenv('YANDEX_MAPS_API_KEY') ?: '');
}

// Сбер Интернет-эквайринг — безопаснее хранить реквизиты в окружении/
// config.local.php, а не в БД. Пока договор/доступы не получены — оставьте пусто.
if (!defined('SBER_USERNAME')) define('SBER_USERNAME', getenv('SBER_USERNAME') ?: '');
if (!defined('SBER_PASSWORD')) define('SBER_PASSWORD', getenv('SBER_PASSWORD') ?: '');

// ИНН вашего ИП: попадает в чек самозанятого как плательщик-юрлицо.
// Без него чек оформляется на физлицо и НЕ принимается к расходам ИП.
if (!defined('COMPANY_INN')) define('COMPANY_INN', getenv('COMPANY_INN') ?: '');

// Тюмень UTC+5 — сдвиг для ценообразования/статистики
if (!defined('CITY_UTC_OFFSET')) define('CITY_UTC_OFFSET', 5);

// Публичный базовый URL сервиса: уходит в Referer внешним API, у которых
// ключ может быть ограничен по Referer (TomTom). Укажите свой домен.
if (!defined('PUBLIC_BASE_URL')) define('PUBLIC_BASE_URL', getenv('PUBLIC_BASE_URL') ?: 'https://taxi.event72.ru');

// CORS: домен фронтенда (или '*' на время разработки)
if (!defined('CORS_ORIGIN')) define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: '*');

// Внутренний временной пояс БД: все DATETIME хранятся в UTC
date_default_timezone_set('UTC');
