# Сборка APK приложения КЛИЕНТА: клонирует/обновляет репозиторий и собирает.
# Запуск:  powershell -ExecutionPolicy Bypass -File build-client.ps1
#   или:  cmd /c powershell -File build-client.ps1
#
# Требуется: .NET SDK с workload MAUI Android
#   dotnet workload install maui-android
#   (или Visual Studio 2022 с выбранной рабочей нагрузкой «.NET Multi-platform App UI»)
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

Write-Host "Собираю APK клиента..." -ForegroundColor Cyan
$proj = Join-Path $dir 'TaxiClient\TaxiClient.csproj'
dotnet publish $proj `
    -f net10.0-android -c Release `
    -p:AndroidPackageFormat=apk

if ($LASTEXITCODE -ne 0) {
    Write-Host "СБОРКА НЕ УДАЛАСЬ — пришлите вывод выше" -ForegroundColor Red
    exit $LASTEXITCODE
}

$apkDir = Join-Path $dir 'TaxiClient\bin\Release\net10.0-android'
Write-Host "`nГОТОВО. APK клиента лежит здесь:" -ForegroundColor Green
Get-ChildItem $apkDir -Recurse -Filter *.apk | Select-Object FullName, Length
Start-Process explorer.exe $apkDir
