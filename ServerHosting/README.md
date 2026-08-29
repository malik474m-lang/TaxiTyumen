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
| `POST /api/auth/password.php` | все (Bearer) | Смена своего пароля: верным старым паролем или свежим SMS-кодом |
| `GET /api/orders/?view=active\|available\|history\|all\|clientActive\|driverCurrent\|today` | все | Списки заказов |
| `POST /api/orders/` | client | Создание заказа (цена по дорогам OSRM + геометрия + опции) |
| `POST /api/orders/operator.php` | operator/admin | Операторский заказ со звонка |
| `GET /api/orders/item.php?id=` | все | Карточка заказа (+ GPS-симуляция) |
| `POST /api/orders/action.php` | по ролям | `accept/reject/arrived/start/complete/cancel/assign/rate` — комиссия 15 %, штрафы, рейтинг |
| `GET /api/drivers/?online=1` | все | Список водителей с координатами |
| `POST /api/drivers/action.php` | driver/admin | `status/location` (GPS+history) — водителем; `topup` — operator/admin, `verify` — admin |
| `GET /api/drivers/track.php` | driver/operator/admin | GPS-история водителя/заказа, расчёт километража |
| `GET /api/tariffs/` | все | Тарифы; `PUT` — редактирование (admin) |
| `POST /api/pricing.php` | все | Оценка цены всех тарифов + геометрия маршрута |
| `GET/POST /api/chat.php` | участники | Чат по заказу + `action=read`, уведомление второго участника |
| `GET/POST /api/notifications.php` | Bearer/admin | Лента/прочтение; admin: user/role/all, in-app/SMS |
| `GET /api/geocoding.php` | все | Server-side DaData + Nominatim search и reverse geocode |
| `GET /api/services.php` | admin | Диагностика MySQL, OSRM, sms.ru, Zvonok, DaData, storage, realtime |
| `GET/PUT /api/branding.php` | app=… публично, список/PUT — admin | Серверный брендинг 3 приложений (`logoUrl` входит в DTO) |
| `GET/POST /api/branding-logo.php` | GET публично, POST admin | Выдача/загрузка/удаление логотипа, PNG/JPEG/WebP ≤ 2 МБ |
| `GET/POST /api/operators/shift.php` | operator | Смены + выработка |
| `GET/PUT /api/autocall.php` | operator/admin | Настройки автодозвона (эскалация + автоназначение) |
| `GET /api/stats.php` | admin | Выручка, рейтинг направлений, графики по дням и часам |
| `GET /api/export/orders.php` | admin | CSV-выгрузка (UTF-8 BOM + `;`) |
| `GET /api/events.php?since=` | все | Лёгкий realtime-поллинг (вместо SSE) |
| `GET /api/places.php` | все | Подсказки адресов Тюмени |
| `GET /api/install.php` | — | Одноразовый установщик (удалить после!) |

## Деплой на хостинг через `git pull origin main` (три варианта)

Репозиторий в `~/domains/ваш-домен/` (webroot), сайт смотрит на его содержимое:

**A. Симлинки** (если хостинг разрешает `FollowSymLinks`):
`api`, `admin`, `src`, `sql` — симлинки в `ServerHosting/`;
`git pull` обновляет сайт автоматически.

**B. mod_rewrite** (если симлинки запрещены — 403 Forbidden):
скопируйте `ServerHosting/deploy/.htaccess` в корень домена — URL `/api/*` и
`/admin/*` внутренне проксируются в `ServerHosting/` без симлинков и копий;
`git pull` обновляет сайт автоматически.

**C. Синк-скрипт** (если не сработало и B):
```bash
cd ~/domains/ваш-домен
git pull origin main
bash ServerHosting/deploy/deploy.sh   # копирует api/admin/src/sql на место
```
Файл `config.php` с секретами скрипт НЕ затирает.

## Деплой на shared-хостинг (5 минут)

1. **Залейте** содержимое папки `ServerHosting/` в `public_html` (или подпапку `api-site/`) по FTP/SFTP
2. **Создайте MySQL-базу** в панели хостинга, получите `db_name / user / password`
3. **Создайте `config.local.php`**: `cp config.protected.php config.local.php`, впишите реквизиты БД и **обязательно поменяйте `AUTH_SECRET`**. Этот файл игнорируется Git и переживает `git pull/reset`.
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

### Разбор ошибки «Пользователь не найден»

1. **Номер не зарегистрирован.** `login.php` не создаёт пользователей: сначала `POST /api/auth/sms.php {action:"send"}` (авто-регистрация) или `register.php`.
2. **Опечатка в демо-номере.** Админ — ровно `+79001234567`; `8-900-...`, скобки и пробелы сервер нормализует сам.
3. **Тело запроса не JSON.** curl/Postman со схемой по умолчанию шлют form-data — API с этой версии принимает и его, но лучше слать `Content-Type: application/json`.

### Чеклист после установки

1. Удалите `api/install.php` с хостинга
2. Храните реквизиты и `AUTH_SECRET` в `config.local.php` (не в отслеживаемом `config.php`) и задайте длинную случайную строку
3. Войдите админом (`/api/auth/login.php`) и смените пароль через `/api/auth/password.php`
4. Те же действия — для оператора и демо-водителей/демо-клиента (или удалите их из `users`)
5. Укажите конкретный домен в `CORS_ORIGIN` вместо `*`
6. Для SMS регистрации в кабинете sms.ru получите API-ключ и впишите в `SMS_API_ID`

## Совместимость с исходными C# клиентами

`api/router.php` + `api/.htaccess` поддерживают исходные ASP.NET URL:
`/api/Auth/login|register|refresh|send-sms|verify-sms`,
`/api/Orders/{id}/accept|reject|complete|force-assign|cancel|rate|status`,
`/api/Drivers/{id}/location|status`, `online`, `/api/Balance/{id}/topup|history`,
`/api/Pricing/estimate-all`, `/api/Operators/shift/{start,end}`, `/api/Chat/{id}/read`.

Router адаптирует:
- статусы `DriverAssigned ↔ driver_assigned`;
- enum тарифов/оплаты (`Economy ↔ economy`, `BonusPoints ↔ bonus`);
- PascalCase JSON (`ClientId`, `PickupAddress`);
- старый формат AuthResponse (`token` на верхнем уровне);
- query-string `driverId` в action-запросах.

DaData/Nominatim весь выполнен сервером: мобильные приложения используют
`/api/geocoding.php`, жёсткого ключа в исходниках больше нет.

## Отличия от веб-версии (Next.js)

| Аспект | Web (Node) | ServerHosting (PHP) |
|---|---|---|
| БД | PostgreSQL (Drizzle) | MySQL (PDO) |
| Realtime | SSE `/api/events` | Поллинг `events.php?since=lastId` |
| Автодозвон | in-memory тик 10 c | Тик через `last_tick_at` в БД (многопроцессность) |
| SMS | `process.env.SMS_API_ID` | `define('SMS_API_ID')` |
| Токены | HMAC `Sign` | Идентичный HMAC — совместимы |
| Geo-ритейл | OSRM через fetch | OSRM через `file_get_contents` (curl не нужен) |
