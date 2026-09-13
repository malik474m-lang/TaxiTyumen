# Сборка APK приложения водителя: клонирует/обновляет репозиторий и собирает.
# Запуск:  powershell -ExecutionPolicy Bypass -File build-driver.ps1
#   или:  cmd /c powershell -File build-driver.ps1
#
# ПОДПИСЬ: используется СВОЙ постоянный ключ taxi-release.keystore, а не
# случайный отладочный. Это устраняет две главные проблемы установки:
#   1) предупреждение «неизвестный разработчик» при каждом обновлении —
#      Android запоминает один и тот же издатель;
#   2) «Приложение не установлено» при смене подписи — подпись больше
#      не меняется от компьютера к компьютеру.
# Ключ создаётся автоматически при первой сборке рядом со скриптом.
$ErrorActionPreference = 'Stop'
$repoUrl = 'https://github.com/malik474m-lang/TaxiTyumen.git'
$dir = Join-Path $PSScriptRoot 'TaxiTyumen'

# ── Свой ключ подписи (один раз создаётся и дальше переиспользуется) ────────
$keystore = Join-Path $PSScriptRoot 'taxi-release.keystore'
$ksPass   = 'TaxiTyumen2024'
$ksAlias  = 'taxi-release'
if (-not (Test-Path $keystore)) {
    Write-Host "Создаю постоянный ключ подписи (один раз)..." -ForegroundColor Cyan
    # keytool входит в состав Java JDK
    & keytool -genkeypair -v `
        -keystore $keystore `
        -storepass $ksPass -keypass $ksPass `
        -alias $ksAlias -keyalg RSA -keysize 2048 -validity 10950 `
        -dname "CN=TaxiTyumen, OU=Taxi, O=TaxiTyumen, L=Tyumen, C=RU"
    if ($LASTEXITCODE -ne 0) {
        throw 'Не удалось создать ключ подписи. Убедитесь, что Java JDK установлена (keytool в PATH).'
    }
    Write-Host "Ключ создан: $keystore" -ForegroundColor Green
    Write-Host "ВАЖНО: сохраните этот файл в надёжное место (вместе с резервной копией)." -ForegroundColor Yellow
}

if (-not (Test-Path (Join-Path $dir '.git'))) {
    Write-Host "Клонирую репозиторий..." -ForegroundColor Cyan
    git clone $repoUrl $dir
} else {
    Write-Host "Обновляю репозиторий..." -ForegroundColor Cyan
    git -C $dir pull --ff-only origin main
}
if ($LASTEXITCODE -ne 0) { throw 'Ошибка git clone/pull' }

# PMTiles и шрифты не хранятся в Git (десятки МБ). Если пакет был собран
# в основной папке командой TaxiDriver/maps/build-pmtiles.ps1, переносим
# его в свежий рабочий клон перед сборкой APK — иначе включался только
# растровый OSM и собственный навигационный стиль не использовался.
$mapSource = Join-Path $PSScriptRoot 'TaxiDriver\Resources\Raw\map'
$mapTarget = Join-Path $dir 'TaxiDriver\Resources\Raw\map'
$pmtiles = Join-Path $mapSource 'tyumen.pmtiles'
if (Test-Path $pmtiles) {
    New-Item -ItemType Directory -Force -Path $mapTarget | Out-Null
    Copy-Item $pmtiles (Join-Path $mapTarget 'tyumen.pmtiles') -Force
    Get-ChildItem $mapSource -Filter 'font-*.pbf' -ErrorAction SilentlyContinue |
        Copy-Item -Destination $mapTarget -Force
    Write-Host ("Офлайн-карта добавлена в APK: {0:N1} МБ" -f ((Get-Item $pmtiles).Length / 1MB)) -ForegroundColor Green
} else {
    Write-Host "PMTiles не найден — будет онлайн-карта OSM + пробки TomTom." -ForegroundColor DarkGray
    Write-Host "Для собственного векторного стиля сначала запустите TaxiDriver\maps\build-pmtiles.ps1" -ForegroundColor DarkGray
}

Write-Host "Собираю APK..." -ForegroundColor Cyan
$proj = Join-Path $dir 'TaxiDriver\TaxiDriver.csproj'

# Удаляем артефакты прошлого неудачного запуска: MSBuild мог сохранить
# ошибочную строку параметров в obj/bin и повторить старую ошибку.
$projectDir = Split-Path -Parent $proj
Remove-Item (Join-Path $projectDir 'bin\Release\net10.0-android') `
    -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $projectDir 'obj\Release\net10.0-android') `
    -Recurse -Force -ErrorAction SilentlyContinue

# Каждый параметр подписи — ОТДЕЛЬНЫЙ элемент массива. Нельзя склеивать их
# с AndroidPackageFormat: тогда MSBuild считает всю строку именем APK-файла.
$publishArgs = @(
    'publish', $proj,
    '-f', 'net10.0-android',
    '-c', 'Release',
    '-p:AndroidPackageFormat=apk',
    '-p:AndroidKeyStore=true',
    "-p:AndroidSigningStorePass=$ksPass",
    "-p:AndroidSigningKeyPass=$ksPass",
    "-p:AndroidSigningKeyAlias=$ksAlias",
    "-p:AndroidSigningKeyStore=$keystore"
)
& dotnet @publishArgs

if ($LASTEXITCODE -ne 0) {
    Write-Host "СБОРКА НЕ УДАЛАСЬ — пришлите вывод выше" -ForegroundColor Red
    exit $LASTEXITCODE
}

$apkDir = Join-Path $dir 'TaxiDriver\bin\Release\net10.0-android'

# Сборка создаёт ДВА apk: подписанный (*-Signed.apk, его можно ставить)
# и неподписанный (*.apk — Android отвергнет: «пакет повреждён» / «не установлено»).
# Неподписанный удаляем, чтобы его случайно не установить.
Get-ChildItem $apkDir -Recurse -Filter *.apk |
    Where-Object { $_.Name -notlike '*-Signed.apk' } |
    Remove-Item -Force -ErrorAction SilentlyContinue

$signed = @(Get-ChildItem $apkDir -Recurse -Filter *-Signed.apk)
if ($signed.Count -eq 0) {
    Write-Host "АПК собран, но подписанный файл не найден — пришлите список файлов из папки:" -ForegroundColor Red
    Get-ChildItem $apkDir | Select-Object Name
    exit 1
}

Write-Host "`nГОТОВО! Устанавливайте НА ТЕЛЕФОН ЭТОТ файл:" -ForegroundColor Green
foreach ($f in $signed) {
    Write-Host ("  {0}  ({1:N1} МБ)" -f $f.FullName, ($f.Length / 1MB)) -ForegroundColor Yellow
}
Write-Host @"

Если не устанавливается (разбор типовых причин — в INSTALL-APK.md):
  1. На телефоне удалите СТАРОЕ приложение, затем поставьте новое
     (подпись сборки сменилась — поверх она не встанет);
  2. Разрешите установку из источника: Настройки → Приложения →
     «установка из неизвестных источников» для вашего файлового менеджера;
  3. Если Google Play Protect пишет «небезопасно»: «Подробнее» →
     «Установить в любом случае» (APK подписан постоянным ключом сервиса;
     первая установка из файла всё равно считается сторонней).
"@ -ForegroundColor DarkGray

Start-Process explorer.exe $apkDir
