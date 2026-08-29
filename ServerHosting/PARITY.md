# Parity-аудит исходного TaxiTyumen → ServerHosting PHP/MySQL

Аудит выполнен по слоям исходного решения: Domain Entities, Core Interfaces/Services,
API Controllers/Hubs/Background Services, TaxiAdmin, TaxiClient, TaxiDriver, TaxiOperator.

## Серверная бизнес-логика

| Исходный компонент | PHP/MySQL | Реализация / адаптация |
|---|---|---|
| AuthService: login/register/refresh/SMS | ✅ | auth/*.php + HMAC, router `Auth/*` |
| PricingService + DistanceCalculator | ✅ | Taxi.php, OSRM + fallback Haversine×1.3, UTC+5 |
| OrderService: весь lifecycle | ✅ | orders/action.php: accept/reject/status/complete/cancel/assign/rate |
| DriverSearchService | ✅ адаптировано | уведомление 3 ближайших + request-driven AutoCall auto-assign |
| DriverService: location/status/online/info | ✅ | drivers/*, GPS speed/bearing/history |
| BalanceService | ✅ | topup/commission/penalty/refund/bonus + журнал |
| NotificationService | ✅ адаптировано | персистентные notifications + polling вместо SignalR |
| ChatController: send/history/read | ✅ | chat.php, участники, is_read/read_at, уведомление второго участника |
| AutoCallService (Zvonok) | ✅ | ZvonokService: call, template variables, balance, logs |
| DriverTimeoutService | ✅ адаптировано | DriverTimeout: request-driven tick, 5 минут |
| SignalR Hub | ✅ адаптировано | MySQL events/notifications polling; публичные события MAUI сохранены |
| OperatorsController shift start/end | ✅ | profile/hours/orders/earned + накопительные показатели |
| FleetChat (чат водителей автопарка) | ✅ | api/fleet-chat.php: история/?after=, анти-спам 1.5 c, модерация DELETE; монитор admin/fleet-chat.php |
| Простой (платное ожидание пассажира) | ✅ | orders/action.php: waiting-start/stop; биллинг при complete по free_waiting_minutes/paid_waiting_per_minute; поля в admin/tariffs.php; кнопка и живой таймер в TaxiDriver |

## Доменная модель

| Сущность/поля | Статус |
|---|---|
| User (roles, blocks, rating, total trips, SMS) | ✅ |
| Driver (авто, ВУ+expiry, verifiedAt, GPS speed/bearing, баланс, реквизиты) | ✅ |
| DriverLocationHistory | ✅ таблица + запись каждой GPS-точки + просмотр/километраж |
| Order (все статусы/цены/timestamps/cancelledBy/reviews/actualDistance) | ✅ |
| RoutePoint (промежуточные точки) | ✅ |
| OrderOption | ✅ |
| OrderRejection | ✅ |
| Transaction (pending/completed/failed/refunded) | ✅ |
| BalanceTransaction (TopUp/Commission/Refund/Bonus + расширение Penalty) | ✅ |
| OperatorProfile / OperatorShift | ✅ |
| AutoCallSettings | ✅ + внутренние настройки эскалации |
| Tariff | ✅ |

## Административные функции

| Исходная страница | PHP admin |
|---|---|
| Home dashboard + последние заказы | ✅ index.php |
| Orders + filters + force assign | ✅ orders.php |
| Drivers: add/delete/block/verify/balance/payment | ✅ drivers.php |
| Clients: list/block | ✅ clients.php (+ поиск/расходы/история) |
| Operators: add/delete/block/payment/shifts/salary | ✅ operators.php |
| Balance history | ✅ balance.php (+ ручные refund/bonus) |
| Tariffs | ✅ tariffs.php |
| Stats | ✅ stats.php |
| Export: orders/drivers/balance + date range | ✅ CSV для Excel (XLSX заменён на CSV без composer) |
| AutoCall Zvonok config/balance/template | ✅ autocall.php |
| Сообщения / API diagnostics / Branding | ✅ новые расширения |
| Counter / Weather | ⛔ не переносились: стандартные шаблонные страницы Blazor, не бизнес-функции |

## Клиентские сервисы

- ASP.NET-подобные URL сохранены через `api/router.php` и `api/.htaccess`.
- Router адаптирует PascalCase JSON, C# enum-строки, статусы и старый AuthResponse.
- TaxiClient/TaxiDriver SignalRService заменён polling уведомлений PHP с теми же событиями.
- TaxiOperator получает обновления заказов и сообщения через polling.
- DaData и HTTP Геокодер Яндекс Карт перенесены на сервер (`geocoding.php`), ключи удалены из приложений.

## Осознанные инфраструктурные адаптации

- SignalR → polling 3 секунды: shared-хостинг не поддерживает долгоживущий ASP.NET Hub.
- BackgroundService → request-driven tick: на shared-хостинге нет постоянного worker.
- XLSX/ClosedXML → CSV UTF-8 с `;`: чистый PHP без composer, файл открывается Excel.
- Firebase в исходном Program.cs не использовался NotificationService (уведомления шли SignalR);
  в PHP источником истины является таблица notifications.
