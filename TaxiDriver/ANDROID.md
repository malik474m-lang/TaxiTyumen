# TaxiDriver для Android — сборка и установка

Приложение водителя теперь собирается под **Android** (ранее — только Windows).
Бэкенд уже настроен: `https://taxi.event72.ru/api/` (`Services/ApiService.cs`).

## Что добавлено для Android

| Изменение | Файл |
|---|---|
| Таргет `net8.0-android` (мин. Android 8.0 / API 26) | `TaxiDriver.csproj` |
| Разрешения геолокации `FINE/COARSE_LOCATION` | `Platforms/Android/AndroidManifest.xml` |
| Запрос разрешения GPS в рантайме при выходе «на линию» | `Services/LocationService.cs` |
| KeepScreenOn — экран не гаснет у водителя на смене | `Services/LocationService.cs` |
| Точность GPS повышена до `High` | `Services/LocationService.cs` |
| Брендированная иконка и сплэш (такси-жёлтый) | `Resources/AppIcon/*`, `Resources/Splash/*` |
| `android:exported=true`, SingleTop, HTTPS-only | `MainActivity.cs`, манифест |

Точность: слежение работает, пока приложение открыто («на линии» + экран не гаснет).
Фоновый трекинг при свёрнутом приложении — см. раздел «Дальше».

## Требования

- **Windows + Visual Studio 2022 17.8+** с workload «.NET Multi-platform App UI»
  (в инсталлере отметить Android SDK) — *или* чистый CLI:
  ```powershell
  dotnet workload install maui-android
  ```
- Android 8.0+ (API 26) на устройстве/эмуляторе.

## Запуск на телефоне (Debug)

1. На телефоне включите **Режим разработчика → Отладка по USB** (или Wi-Fi debug).
2. Подключите кабелем, подтвердите RSA-отпечаток на экране телефона.
3. В PowerShell:
   ```powershell
   cd TaxiDriver
   dotnet build -t:Run -f net8.0-android        # соберёт, установит и запустит
   ```
   В VS 2022: выберите таргет `Android → ваше устройство` и F5.

## Сборка Release APK для раздачи

1. Один раз создайте keystore (храните его и пароли в надёжном месте!):
   ```powershell
   keytool -genkeypair -v -keystore taxi-driver.keystore `
     -alias taxi-driver -keyalg RSA -keysize 2048 -validity 10000
   ```
2. Опубликуйте APK:
   ```powershell
   dotnet publish TaxiDriver/TaxiDriver.csproj -f net8.0-android -c Release `
     -p:AndroidPackageFormat=apk `
     -p:AndroidKeyStore=true `
     -p:AndroidSigningKeyStore=taxi-driver.keystore `
     -p:AndroidSigningStorePass=ПАРОЛЬ_KEYSTORE `
     -p:AndroidSigningKeyAlias=taxi-driver `
     -p:AndroidSigningKeyPass=ПАРОЛЬ_КЛЮЧА
   ```
3. APK лежит в `bin/Release/net8.0-android/publish/` — отправьте водителям
   (Telegram/почта), они разрешают «установку из неизвестных источников» и ставят.

Для Google Play вместо APK собирайте AAB: `-p:AndroidPackageFormat=aab`.

## Проверка после установки

- Вход водителем: `+79221000001…05` / `Driver123!` (демо) или реальный аккаунт.
- «Выйти на линию» → системный диалог геолокации → «Разрешить при использовании».
- На админ-карте автопарка и в диспетчерской водитель появляется на карте Тюмени —
  координаты уходят на сервер каждые 5 сек (`drivers/location`).

## Дальше (дорожная карта фона)

1. **Foreground Service + POST_NOTIFICATIONS** — постоянный GPS-трекинг при
   свёрнутом приложении (Android 8+ требует foreground-service с уведомлением;
   на Android 14+ — разрешение `FOREGROUND_SERVICE_LOCATION` + манифест service).
2. **FCM push** вместо/вместе с polling — мгновенные «Новый заказ» в фоне.
3. Адаптивная иконка (monochrome-слой для Android 13+).
