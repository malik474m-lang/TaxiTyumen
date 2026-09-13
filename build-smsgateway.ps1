# Сборка APK приложения SMS-ШЛЮЗА (телефон с SIM-картой отправляет SMS сервиса).
# Запуск:  powershell -ExecutionPolicy Bypass -File build-smsgateway.ps1
$ErrorActionPreference = 'Stop'
$repoUrl = 'https://github.com/malik474m-lang/TaxiTyumen.git'
$dir = Join-Path $PSScriptRoot 'TaxiTyumen'

# ── Тот же ключ подписи, что у клиента и водителя ──────────────────────────
$keystore = Join-Path $PSScriptRoot 'taxi-release.keystore'
$ksPass   = 'TaxiTyumen2024'
$ksAlias  = 'taxi-release'
if (-not (Test-Path $keystore)) {
    Write-Host "Создаю постоянный ключ подписи (один раз)..." -ForegroundColor Cyan
    & keytool -genkeypair -v `
        -keystore $keystore `
        -storepass $ksPass -keypass $ksPass `
        -alias $ksAlias -keyalg RSA -keysize 2048 -validity 10950 `
        -dname "CN=TaxiTyumen, OU=Taxi, O=TaxiTyumen, L=Tyumen, C=RU"
    if ($LASTEXITCODE -ne 0) {
        throw 'Не удалось создать ключ подписи (нужна Java JDK с keytool).'
    }
    Write-Host "Ключ создан: $keystore" -ForegroundColor Green
}

if (-not (Test-Path (Join-Path $dir '.git'))) {
    Write-Host "Клонирую репозиторий..." -ForegroundColor Cyan
    git clone $repoUrl $dir
} else {
    Write-Host "Обновляю репозиторий..." -ForegroundColor Cyan
    git -C $dir pull --ff-only origin main
}
if ($LASTEXITCODE -ne 0) { throw 'Ошибка git clone/pull' }

Write-Host "Собираю APK SMS-шлюза..." -ForegroundColor Cyan
$proj = Join-Path $dir 'TaxiSmsGateway\TaxiSmsGateway.csproj'

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

$apkDir = Join-Path $dir 'TaxiSmsGateway\bin\Release\net10.0-android'
Get-ChildItem $apkDir -Recurse -Filter *.apk |
    Where-Object { $_.Name -notlike '*-Signed.apk' } |
    Remove-Item -Force -ErrorAction SilentlyContinue

$signed = @(Get-ChildItem $apkDir -Recurse -Filter *-Signed.apk)
if ($signed.Count -eq 0) {
    Write-Host "Подписанный APK не найден:" -ForegroundColor Red
    Get-ChildItem $apkDir | Select-Object Name
    exit 1
}

Write-Host "`nГОТОВО! Ставьте на телефон-шлюз ЭТОТ файл:" -ForegroundColor Green
foreach ($f in $signed) { Write-Host ("  {0}  ({1:N1} МБ)" -f $f.FullName, ($f.Length / 1MB)) -ForegroundColor Yellow }
Write-Host @"

Дальше:
  1. Админка → «SMS-шлюз» → включить сервис и «Выдать новый токен»;
  2. В приложении на телефоне указать адрес сервера и токен → «Проверить связь»;
  3. Нажать «Запустить шлюз» и разрешить отправку SMS.
"@ -ForegroundColor DarkGray

Start-Process explorer.exe $apkDir
