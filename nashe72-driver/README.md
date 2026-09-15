# nashe72-driver — приложение водителя для nashe72.ru (Android)

Мобильное приложение водителя такси для сервера **https://nashe72.ru**.
Полный порт TaxiDriver с заменённым адресом API.

## Возможности

| Раздел | Что умеет |
|---|---|
| **Заказы** | приём, отказ, статусы, промежуточные точки |
| **Навигационная карта** | MapLibre + OSM, поворот по курсу, авто-зум перед манёвром |
| **Голосовые подсказки** | «через 200 м направо», работает офлайн |
| **Пробки** | TomTom Traffic Flow (если включён на сервере) |
| **ДТП** | TomTom Traffic Incidents |
| **Офлайн-карта** | PMTiles внутри APK, работает без интернета |
| **Чат с клиентом** | обмен сообщениями по заказу |
| **Чат автопарка** | общий канал водителей |
| **Кошелёк** | безналичный заработок, пополнение, вывод по СБП |
| **Чеки НПД** | автоматические чеки самозанятого |
| **SOS** | тревожная кнопка |
| **Простой** | платное ожидание с таймером |
| **GPS-трек** | запись маршрута, честный километраж |

## Сборка APK

Требуется:
- Windows 10/11
- .NET SDK 10.0+ с workload MAUI Android
- Java JDK (keytool в PATH)

```powershell
powershell -ExecutionPolicy Bypass -File nashe72-driver/build-driver.ps1
```

Результат: `nashe72-driver/bin/Release/net10.0-android/ru.nashe72.driver-Signed.apk`

### Автономная сборка (без .NET на телефоне)

Не требуется — APK включает всё необходимое.

## Установка на телефон

1. Скопируйте `ru.nashe72.driver-Signed.apk` на телефон.
2. Разрешите установку из неизвестных источников.
3. Установите.
4. Войдите телефоном и паролем водителя.

## Создание водителя

В админке **https://nashe72.ru/admin** → **«Водители»** → «Добавить водителя»:
- телефон (логин в приложении);
- имя и фамилия;
- пароль;
- марка, модель, цвет, госномер, год авто;
- стартовый баланс.

## Сервер

Приложение подключается к стандартному API ServerHosting на nashe72.ru:

```text
POST /api/auth/login.php           — вход водителя
GET  /api/orders/?view=available   — доступные заказы
POST /api/orders/action.php        — accept/reject/status/complete
GET  /api/orders/?view=driverCurrent — текущий заказ
GET  /api/route.php?points=        — маршрут по дорогам
GET  /api/map-config.php           — карта + пробки
GET  /api/branding.php?app=driver — брендинг
GET  /api/chat.php?orderId=        — чат с клиентом
GET  /api/fleetchat                — чат автопарка
POST /api/sos.php                  — тревожная кнопка
GET  /api/drivers/track.php        — GPS-трек
```

## Офлайн-карта

Тюмень и Тюменский район — векторная карта OSM внутри APK (PMTiles).
Работает полностью без интернета. За пределами района автоматически
включаются онлайн-тайлы.

Для обновления карты:
```powershell
.\nashe72-driver\maps\build-pmtiles.ps1 -ReuseOsm
```

## Структура проекта

```
nashe72-driver/
├── TaxiDriver.csproj              — MAUI Android .NET 10
├── build-driver.ps1               — сборка APK + подпись
├── README.md                      — эта инструкция
├── MauiProgram.cs
├── App.xaml(.cs) / AppShell.xaml(.cs)
├── Models/                        — модели данных
├── Services/
│   ├── ApiService.cs              → nashe72.ru/api/
│   ├── BrandingService.cs         → брендинг с сервера
│   ├── SignalRService.cs          → realtime-уведомления
│   ├── LocationService.cs         → GPS
│   ├── VoiceNavigator.cs          → голосовые подсказки
│   ├── LocalWebServer.cs          → офлайн-карта
│   ├── MapAssets.cs               → PMTiles
│   ├── MapConfigService.cs        → конфиг карты
│   └── ...
├── Views/
│   ├── LoginPage.xaml(.cs)        — вход
│   ├── MainDriverPage.xaml(.cs)   — главный экран (карта, заказы, SOS)
│   ├── ChatPage.xaml(.cs)         — чат с клиентом
│   ├── FleetChatPage.xaml(.cs)    — чат автопарка
│   └── WalletPage.xaml(.cs)       — безнал, пополнение, вывод
├── Platforms/Android/             — нативный код
├── Resources/Raw/map/             — карта MapLibre + стиль
└── maps/                          — сборка PMTiles
```

## Настройка сервера

Приложение берёт все настройки с сервера nashe72.ru:
- брендинг (название, цвета, логотип);
- карта (провайдер, ключ, центр);
- тарифы и цены;
- опции заказа;
- пробки TomTom (если включены).

Админка: **https://nashe72.ru/admin**

## Устранение проблем

| Проблема | Решение |
|---|---|
| «Нет связи с сервером» | Проверьте `https://nashe72.ru/api/` в браузере |
| «Неверный логин» | Создайте водителя в админке сервера |
| Карта пустая | Нажмите «Скачать карту города» в приложении |
| Пробки не показываются | Включите TomTom в админке сервера |
| Голос не говорит | Включите «Озвучка» в приложении |
| APK не устанавливается | Разрешите неизвестные источники; удалите старую версию |

## Отличия от TaxiDriver

- Все API-адреса → `https://nashe72.ru/api/`;
- ID приложения: `ru.nashe72.driver`;
- Название: «Водитель nashe72»;
- Версия: 1.0 (1) — новая линейка версий для nashe72.
