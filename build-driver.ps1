# Сборка APK приложения водителя: клонирует/обновляет репозиторий и собирает.
# Запуск:  powershell -ExecutionPolicy Bypass -File build-driver.ps1
#   или:  cmd /c powershell -File build-driver.ps1
$ErrorActionPreference = 'Stop'
$repoUrl = 'https://github.com/malik474m-lang/TaxiTyumen.git'
$dir = Join-Path $PSScriptRoot 'TaxiTyumen'

if (-not (Test-Path (Join-Path $dir '.git'))) {
    Write-Host "Клонирую репозиторий..." -ForegroundColor Cyan
    git clone $repoUrl $dir
} else {
    Write-Host "Обновляю репозиторий..." -ForegroundColor Cyan
    git -C $dir pull --ff-only origin main
}
if ($LASTEXITCODE -ne 0) { throw 'Ошибка git clone/pull' }

Write-Host "Собираю APK..." -ForegroundColor Cyan
$proj = Join-Path $dir 'TaxiDriver\TaxiDriver.csproj'
dotnet publish $proj `
    -f net10.0-android -c Release `
    -p:AndroidPackageFormat=apk

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
     «Установить в любом случае» (файл подписан отладочным ключом — это
     нормально для установки с компьютера, вредоносного кода там нет).
"@ -ForegroundColor DarkGray

Start-Process explorer.exe $apkDir
