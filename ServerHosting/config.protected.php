<?php
// Шаблон production-конфига: скопируйте за пределы web-root (на уровень выше
// public_html) и подключите вместо config.php:
//   require_once __DIR__ . '/../config.protected.php';
define('TAXI_DB_HOST', 'localhost');
define('TAXI_DB_PORT', '3306');
define('TAXI_DB_NAME', 'моя_бд');
define('TAXI_DB_USER', 'мой_пользователь');
define('TAXI_DB_PASS', 'мой_пароль');
define('AUTH_SECRET', 'придумайте-длинную-случайную-строку-минимум-32-символа');
define('SMS_API_ID', '');
define('DADATA_API_KEY', '');
// Независимый резервный геокодер (OSM): https://opencagedata.com/
define('OPENCAGE_API_KEY', '');
// Слой пробок в приложении водителя: https://developer.tomtom.com/
define('TOMTOM_API_KEY', '');
// Публичный JS API-ключ Яндекс Карт; ограничьте доменом taxi.event72.ru в кабинете
// https://developer.tech.yandex.ru/services/
define('YANDEX_MAPS_API_KEY', '');
define('CORS_ORIGIN', 'https://ваш-домен.ру');
define('PUBLIC_BASE_URL', 'https://ваш-домен.ру');
// Сбер Интернет-эквайринг (логин вида *-api); пока доступа нет — пусто.
// ИНН вашего ИП — попадает в чек самозанятого как плательщик-юрлицо
define('COMPANY_INN', '');
define('SBER_USERNAME', '');
define('SBER_PASSWORD', '');
define('CITY_UTC_OFFSET', 5);
