# Сборка APK приложения водителя nashe72.
# Запуск:  powershell -ExecutionPolicy Bypass -File build-driver.ps1
#
# Ключ подписи taxi-release.keystore берётся из папки выше (общий с TaxiDriver).
$ErrorActionPreference = 'Stop'
$repoUrl = 'https://github.com/malik474m-lang/TaxiTyumen.git'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$dir = $root

# ── Ключ подписи (общий для всех приложений) ─────────────────────────────
$keystore = Join-Path (Split-Path -Parent $root) 'taxi-release.keystore'
if (-not (Test-Path $keystore)) {
    $keystore = Join-Path $root 'taxi-release.keystore'
}
$ksPass   = 'TaxiTyumen2024'
$ksAlias  = 'taxi-release'
if (-not (Test-Path $keystore)) {
    Write-Host "Создаю ключ подписи (один раз)..." -ForegroundColor Cyan
    & keytool -genkeypair -v `
        -keystore "$root\taxi-release.keystore" `
        -storepass $ksPass -keypass $ksPass `
        -alias $ksAlias -keyalg RSA -keysize 2048 -validity 10950 `
        -dname "CN=Nashe72, OU=Taxi, O=Nashe72, L=Tyumen, C=RU"
    if ($LASTEXITCODE -ne 0) { throw 'Нужна Java JDK (keytool в PATH).' }
    $keystore = Join-Path $root 'taxi-release.keystore'
    Write-Host "Ключ создан: $keystore" -ForegroundColor Green
}

Write-Host "Собираю APK водителя nashe72..." -ForegroundColor Cyan
$proj = Join-Path $dir 'TaxiDriver.csproj'

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

$apkDir = Join-Path $dir 'bin\Release\net10.0-android'
# Оставляем только подписанный APK
Get-ChildItem $apkDir -Recurse -Filter *.apk |
    Where-Object { $_.Name -notlike '*-Signed.apk' } |
    Remove-Item -Force -ErrorAction SilentlyContinue

$signed = @(Get-ChildItem $apkDir -Recurse -Filter *-Signed.apk)
if ($signed.Count -eq 0) {
    Write-Host "Подписанный APK не найден:" -ForegroundColor Red
    Get-ChildItem $apkDir | Select-Object Name
    exit 1
}

Write-Host "`nГОТОВО! Устанавливайте НА ТЕЛЕФОН ЭТОТ файл:" -ForegroundColor Green
foreach ($f in $signed) {
    Write-Host ("  {0}  ({1:N1} МБ)" -f $f.FullName, ($f.Length / 1MB)) -ForegroundColor Yellow
}
Write-Host @"

Приложение: Водитель nashe72
Сервер: https://nashe72.ru
ID: ru.nashe72.driver

После установки войдите телефоном водителя
(создаётся в админке nashe72.ru → «Водители»).
"@ -ForegroundColor DarkGray

Start-Process explorer.exe $apkDir
