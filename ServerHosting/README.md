# TaxiTyumen — ServerHosting (PHP + MySQL)

Самостоятельная серверная часть такси-сервиса для shared-хостинга:
**PHP 8+ , MySQL 8 (или MariaDB 10.4+), без фреймворков и composer-зависимостей** —
заливается на любой хостинг по FTP и работает «из коробки».

Полный порт бизнес-логики из репозитория (`TaxiService` .NET → этот PHP-бэкенд —
совместимое API, та же логика, те же роли и права).

## Что внутри

| Файл/папка | Назначение |
|---|---|
| `config.php` | Подключение к MySQL, секреты, Twilio/SMS/CORS — точка настройки |
| `sql/schema.sql` | Вся схема БД (таблицы, ENUM-ы, индексы, внешние ключи) |
| `api/` | REST-эндпоинты (mysql/REST) |
| `src/` | Ядро: PDO, HMAC-токены, ценообразование, сериализация, GPS-симуляция, автодозвон |

## API-эндпоинты

| Метод/URL | Роль | Описание |
|---|---|---|
| `POST /api/auth/login.php` | все | Вход → `{user, token}` (HMAC-токен 24 ч) |
| `POST /api/auth/register.php` | все | Регистрация клиента/водителя (+ авто-профиль) |
| `POST /api/auth/sms.php` | все | `action=send\|verify` — вход по SMS-коду (sms.ru при заданном `SMS_API_ID`, иначе демо-режим с `devCode`) |
| `GET /api/orders/?view=active\|available\|history\|all\|clientActive\|driverCurrent\|today` | все | Списки заказов |
| `POST /api/orders/` | client | Создание заказа (цена по дорогам OSRM + геометрия + опции) |
| `POST /api/orders/operator.php` | operator/admin | Операторский заказ со звонка |
| `GET /api/orders/item.php?id=` | все | Карточка заказа (+ GPS-симуляция) |
| `POST /api/orders/action.php` | по ролям | `accept/reject/arrived/start/complete/cancel/assign/rate` — комиссия 15 %, штрафы, рейтинг |
| `GET /api/drivers/?online=1` | все | Список водителей с координатами |
| `POST /api/drivers/action.php` | driver/admin | `status/location` — самим водителем; `topup/verify` — только админ |
| `GET /api/tariffs/` | все | Тарифы; `PUT` — редактирование (admin) |
| `POST /api/pricing.php` | все | Оценка цены всех тарифов + геометрия маршрута |
| `GET/POST /api/chat.php` | участники | Чат по заказу (пишется только от своего имени) |
| `GET/PUT /api/branding.php` | app=… публично, список/PUT — admin | Серверный брендинг 3 приложений |
| `GET/POST /api/operators/shift.php` | operator | Смены + выработка |
| `GET/PUT /api/autocall.php` | operator/admin | Настройки автодозвона (эскалация + автоназначение) |
| `GET /api/stats.php` | admin | Выручка, рейтинг направлений, графики по дням и часам |
| `GET /api/export/orders.php` | admin | CSV-выгрузка (UTF-8 BOM + `;`) |
| `GET /api/events.php?since=` | все | Лёгкий realtime-поллинг (вместо SSE) |
| `GET /api/places.php` | все | Подсказки адресов Тюмени |
| `GET /api/install.php` | — | Одноразовый установщик (удалить после!) |

## Деплой на shared-хостинг (5 минут)

1. **Залейте** содержимое папки `ServerHosting/` в `public_html` (или подпапку `api-site/`) по FTP/SFTP
2. **Создайте MySQL-базу** в панели хостинга, получите `db_name / user / password`
3. **Отредактируйте `config.php`**: впишите реквизиты БД и **обязательно поменяйте `AUTH_SECRET`** (длинная случайная строка)
4. **Запустите установщик** один раз в браузере: `https://ваш-домен.ру/api/install.php` — он создаст таблицы из `schema.sql` и зашьёт демо-данные (тарифы Тюмени, админ, оператор, 5 водителей, демо-клиент, брендинг)
5. **Удалите `api/install.php`** с хостинга и смените демо-пароли
6. Готово — фронтенд веб-порта можно нацелить на этот бэкенд (совместимый формат токенов/ответов `Authorization: Bearer <hmac>`), либо собрать свой клиент поверх REST.

Проверить здоровье БД: `curl https://ваш-домен.ру/api/tariffs/` — вернёт JSON с 4 тарифами Тюмени.

## Сессионные токены

`AUTH_SECRET` подписывает HMAC-SHA256 токены вида `base64url(payload).base64url(signature)`
с претензиями `uid / role / driverId / exp` — формат совпадает с веб-версией,
поэтому фронтенд из ветки `web-port` работает с PHP-бэкендом без правок.
Мутации проверяют роль: заказ — только его клиент, accept/reject — только сам водитель,
баланс/тарифы/брендинг — только админ (роли не пересекаются).

## Демо-аккаунты (после install.php)

| Роль | Телефон | Пароль |
|---|---|---|
| Клиент | +79221112233 | `Client123!` |
| Водители | +79221000001 … +79221000005 | `Driver123!` |
| Оператор | +79001234568 | `Operator123!` |
| Админ | +79001234567 | `Admin123!` |

**Поменяйте пароли и удалите демо-аккаунты перед продом.**

## Отличия от веб-версии (Next.js)

| Аспект | Web (Node) | ServerHosting (PHP) |
|---|---|---|
| БД | PostgreSQL (Drizzle) | MySQL (PDO) |
| Realtime | SSE `/api/events` | Поллинг `events.php?since=lastId` |
| Автодозвон | in-memory тик 10 c | Тик через `last_tick_at` в БД (многопроцессность) |
| SMS | `process.env.SMS_API_ID` | `define('SMS_API_ID')` |
| Токены | HMAC `Sign` | Идентичный HMAC — совместимы |
| Geo-ритейл | OSRM через fetch | OSRM через `file_get_contents` (curl не нужен) |
