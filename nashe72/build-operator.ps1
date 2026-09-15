# Сборка пульта оператора nashe72 (Windows WPF).
# Запуск:  powershell -ExecutionPolicy Bypass -File build-operator.ps1
#
# Результат: nashe72/publish/ — готовая папка для копирования оператору.
$ErrorActionPreference = 'Stop'

$projDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$proj = Join-Path $projDir 'TaxiOperator.csproj'

if (-not (Test-Path $proj)) {
    throw "TaxiOperator.csproj не найден в $projDir"
}

Write-Host "Собираю пульт оператора nashe72..." -ForegroundColor Cyan

dotnet publish $proj `
    -c Release `
    -r win-x64 `
    --self-contained false `
    -o (Join-Path $projDir 'publish')

if ($LASTEXITCODE -ne 0) {
    Write-Host "СБОРКА НЕ УДАЛАСЬ — пришлите вывод выше" -ForegroundColor Red
    exit $LASTEXITCODE
}

$outDir = Join-Path $projDir 'publish'
Write-Host "`nГОТОВО! Папка для оператора:" -ForegroundColor Green
Write-Host "  $outDir" -ForegroundColor Yellow
Write-Host "`nЗапуск: TaxiOperator.exe" -ForegroundColor Cyan
Write-Host "Требуется .NET Desktop Runtime 10.0+" -ForegroundColor DarkGray

# Создаём zip для удобной передачи
$zip = Join-Path $projDir 'TaxiOperator-nashe72.zip'
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path "$outDir\*" -DestinationPath $zip
Write-Host "`nАрхив: $zip" -ForegroundColor Green

Start-Process explorer.exe $outDir
