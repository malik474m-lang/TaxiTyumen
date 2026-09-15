# nashe72 — пульт оператора такси (Windows)

Диспетчерский пульт для сервера **https://nashe72.ru**.
Полный порт TaxiOperator с заменённым адресом API и брендингом.

## Возможности

| Раздел | Что умеет |
|---|---|
| **Заказы** | создание операторского заказа, назначение водителя, отмена, статусы |
| **Карта автопарка** | все водители в реальном времени (обновление 5 с), поиск по машине |
| **Телефония** | SIP-звонки из пульта (гарнитура), соединение водителя и клиента |
| **SMS** | отправка SMS через сервер (шлюз или sms.ru) |
| **Брендинг** | название и цвета подтягиваются с сервера |
| **Подсказки адресов** | DaData / Яндекс / OSM через сервер |
| **SOS** | тревожные кнопки водителей |
| **Чат** | сообщения водителей автопарка |
| **Экспорт** | CSV-выгрузка заказов |

## Сборка

Требуется:
- Windows 10/11
- .NET SDK 10.0+
- (опционально) Visual Studio 2022

### Из командной строки

```powershell
cd nashe72
dotnet publish -c Release -r win-x64 --self-contained false -o publish
```

Результат: `publish/TaxiOperator.exe`

### Без SIP-телефонии

```powershell
dotnet publish -c Release -r win-x64 -p:DisableSip=true --self-contained false -o publish
```

### Полностью автономная (без .NET на компьютере)

```powershell
dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -o publish
```

Результат: один `.exe` файл (~150 МБ), запускается на любой Windows 10/11.

## Установка на компьютер оператора

1. Скопируйте папку `publish/` на компьютер оператора.
2. Запустите `TaxiOperator.exe`.
3. Введите телефон и пароль оператора (создаётся в админке nashe72.ru).

## Первый запуск

1. Убедитесь, что сервер `https://nashe72.ru` установлен и работает.
2. В админке сервера создайте оператора: **«Операторы» → «Добавить оператора»**.
3. Запустите `TaxiOperator.exe`.
4. Введите телефон оператора и пароль.

## Смена адреса сервера

Если нужно подключиться к другому серверу, измените `BaseAddress` в файлах:

```
Services/ApiService.cs
Services/BrandingService.cs
Services/DadataService.cs
Services/MapConfig.cs
```

Замените `https://nashe72.ru/api/` на нужный адрес.

Или задайте переменную окружения:

```powershell
$env:TAXI_API_URL = "https://ваш-домен.ру/api/"
```

## Структура проекта

```
nashe72/
├── App.xaml / App.xaml.cs           — точка входа
├── AssemblyInfo.cs
├── MainWindow.xaml(.cs)             — резервное главное окно
├── TaxiOperator.csproj              — проект WPF (.NET 10)
├── Models/
│   ├── AuthModels.cs                — модели авторизации
│   └── OrderModels.cs               — модели заказов
├── Services/
│   ├── ApiService.cs                — REST API клиент (→ nashe72.ru)
│   ├── BrandingService.cs           — брендинг с сервера
│   ├── DadataService.cs             — подсказки адресов
│   ├── MapConfig.cs                 — конфигурация карты
│   ├── JsonDate.cs                  — гибкий парсер дат
│   ├── SipService.cs                — SIP-телефония
│   └── SipSettings.cs               — настройки SIP
└── Views/
    ├── LoginWindow.xaml(.cs)        — окно входа
    ├── MainWindow.xaml(.cs)         — главный пульт (заказы, карта, SOS)
    ├── FleetMapWindow.xaml.cs       — карта автопарка
    ├── DriverSelectWindow.xaml(.cs) — выбор водителя для назначения
    └── SipSettingsWindow.xaml(.cs)  — настройки SIP-телефонии
```

## Требования к серверу

Пульт подключается к стандартному API ServerHosting:

```text
POST /api/auth/login.php          — вход оператора
GET  /api/orders/?view=all        — список заказов
POST /api/orders/operator.php     — создание заказа
POST /api/orders/action.php       — назначение, отмена, статусы
GET  /api/drivers/?online=1       — водители на линии
GET  /api/drivers/track.php       — GPS-трек водителя
GET  /api/map-config.php          — конфигурация карты
GET  /api/branding.php?app=operator — брендинг
GET  /api/geocoding.php?q=        — подсказки адресов
GET  /api/sos.php                 — SOS-тревоги
GET  /api/fleetchat?after=        — чат водителей
```

Полный список: [ServerHosting/README.md](../ServerHosting/README.md)

## Настройка SIP-телефонии

Пульт → **«Телефония» → «Настройки SIP»**:

| Поле | Что указать |
|---|---|
| Сервер | SIP-сервер (например `sip.plusofon.ru`) |
| Порт | 5060 |
| Логин | SIP-номер оператора |
| Пароль | пароль SIP |
| Кодек | G.711 (PCMU/PCMA) |

После настройки нажмите **«Позвонить»** на карточке заказа —
пульт соединит оператора и клиента через SIP.

Подробности: [ServerHosting/TELEPHONY.md](../ServerHosting/TELEPHONY.md)

## Устранение проблем

| Проблема | Решение |
|---|---|
| «Нет связи с сервером» | Проверьте доступность `https://nashe72.ru/api/` в браузере |
| «Неверный логин или пароль» | Создайте оператора в админке сервера |
| Карта не показывает | Проверьте ключ Яндекс Карт в админке сервера |
| Подсказки не работают | Проверьте ключ DaData в админке |
| SIP не подключается | Проверьте SIP-настройки и брандмауэр (порт 5060/UDP) |
| Приложение не запускается | Установите .NET Desktop Runtime 10.0+ |

## Лицензия

Код наследует лицензию проекта TaxiTyumen.
