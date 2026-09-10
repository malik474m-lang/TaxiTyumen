# ═══════════════════════════════════════════════════════════════════════════
#  TaxiTyumen — сборка офлайн-пакета карты (OpenStreetMap → PMTiles)
#  Тюмень + Тюменский район, векторные тайлы для приложения водителя.
#
#  Данные: OpenStreetMap (ODbL) — бесплатно, коммерческое использование
#  и офлайн разрешены. Ключи и лимиты не нужны.
#
#  Запуск (из корня репозитория):
#      .\TaxiDriver\maps\build-pmtiles.ps1
#      .\TaxiDriver\maps\build-pmtiles.ps1 -SkipFonts        # только карта
#      .\TaxiDriver\maps\build-pmtiles.ps1 -MaxZoom 15       # детальнее/тяжелее
#
#  Требуется: Java 21+ (winget install EclipseAdoptium.Temurin.21.JDK)
#             ~6 ГБ свободного места, 15–40 минут на первую сборку.
# ═══════════════════════════════════════════════════════════════════════════
[CmdletBinding()]
param(
    # Тюмень + Тюменский район (запад, юг, восток, север)
    [string]$Bounds  = "64.95,56.70,66.35,57.75",
    [int]$MinZoom    = 0,
    [int]$MaxZoom    = 14,
    [string]$OsmUrl  = "https://download.geofabrik.de/russia/ural-fed-district-latest.osm.pbf",
    [string]$JavaHeap = "4g",
    [switch]$SkipFonts,
    [switch]$ReuseOsm
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

$MapsDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$WorkDir  = Join-Path $MapsDir "work"
$OutDir   = Join-Path (Split-Path -Parent $MapsDir) "Resources\Raw\map"
$Jar      = Join-Path $WorkDir "planetiler.jar"
$OsmFile  = Join-Path $WorkDir "region.osm.pbf"
$OutFile  = Join-Path $OutDir  "tyumen.pmtiles"

New-Item -ItemType Directory -Force -Path $WorkDir, $OutDir | Out-Null

function Write-Step($text) { Write-Host "`n=== $text" -ForegroundColor Cyan }

# ── 0. Проверка Java ────────────────────────────────────────────────────────
Write-Step "Проверка Java"
try {
    $javaVersion = (& java -version 2>&1 | Select-Object -First 1).ToString()
    Write-Host "  $javaVersion"
} catch {
    throw "Java не найдена. Установите JDK 21+: winget install EclipseAdoptium.Temurin.21.JDK"
}

# ── 1. Planetiler ───────────────────────────────────────────────────────────
Write-Step "Planetiler (конвертер OSM → векторные тайлы)"
if (-not (Test-Path $Jar)) {
    $jarUrl = "https://github.com/onthegomap/planetiler/releases/latest/download/planetiler.jar"
    Write-Host "  скачивание $jarUrl"
    Invoke-WebRequest -Uri $jarUrl -OutFile $Jar
}
Write-Host ("  planetiler.jar: {0:N1} МБ" -f ((Get-Item $Jar).Length / 1MB))

# ── 2. Выгрузка OpenStreetMap ───────────────────────────────────────────────
Write-Step "Данные OpenStreetMap (Уральский ФО)"
if ((Test-Path $OsmFile) -and $ReuseOsm) {
    Write-Host "  используем скачанный ранее файл (-ReuseOsm)"
} else {
    Write-Host "  скачивание $OsmUrl"
    Write-Host "  (несколько сотен МБ — это самый долгий шаг)"
    Invoke-WebRequest -Uri $OsmUrl -OutFile $OsmFile
}
Write-Host ("  region.osm.pbf: {0:N1} МБ" -f ((Get-Item $OsmFile).Length / 1MB))

# ── 3. Сборка векторных тайлов ──────────────────────────────────────────────
Write-Step "Сборка PMTiles: bbox $Bounds, зумы $MinZoom–$MaxZoom"
if (Test-Path $OutFile) { Remove-Item $OutFile -Force }

# Тюмень — внутри материка: вместо глобальных океанских полигонов (≈1 ГБ)
# берём лёгкие тестовые наборы planetiler. Для приморских городов замените
# ссылки на полные (--download без этих двух параметров).
$testRes = "https://github.com/onthegomap/planetiler/raw/main/planetiler-core/src/test/resources"

& java "-Xmx$JavaHeap" -jar $Jar `
    --osm-path=$OsmFile `
    --bounds=$Bounds `
    --minzoom=$MinZoom `
    --maxzoom=$MaxZoom `
    --languages=ru,en `
    --download `
    --water-polygons-url="$testRes/water-polygons-split-3857.zip" `
    --natural-earth-url="$testRes/natural_earth_vector.sqlite.zip" `
    --output=$OutFile `
    --force

if (-not (Test-Path $OutFile)) { throw "PMTiles не создан — смотрите лог выше" }
Write-Host ("  ✓ tyumen.pmtiles: {0:N1} МБ" -f ((Get-Item $OutFile).Length / 1MB)) -ForegroundColor Green

# ── 4. Шрифты подписей (кириллица) ──────────────────────────────────────────
if (-not $SkipFonts) {
    Write-Step "Шрифты подписей (Noto Sans, кириллица)"
    $fontsZip = Join-Path $WorkDir "fonts.zip"
    $fontsDir = Join-Path $WorkDir "fonts"
    if (-not (Test-Path $fontsZip)) {
        Invoke-WebRequest -Uri "https://github.com/openmaptiles/fonts/releases/download/v2.0/v2.0.zip" -OutFile $fontsZip
    }
    if (Test-Path $fontsDir) { Remove-Item $fontsDir -Recurse -Force }
    Expand-Archive -Path $fontsZip -DestinationPath $fontsDir -Force

    # Латиница, латиница-расширенная, кириллица, знаки препинания
    $ranges = @("0-255", "256-511", "1024-1279", "8192-8447")
    $srcStack = Get-ChildItem -Path $fontsDir -Recurse -Directory |
        Where-Object { $_.Name -eq "Noto Sans Regular" } | Select-Object -First 1
    if (-not $srcStack) { throw "В архиве шрифтов нет набора 'Noto Sans Regular'" }

    foreach ($r in $ranges) {
        $src = Join-Path $srcStack.FullName "$r.pbf"
        if (Test-Path $src) {
            Copy-Item $src (Join-Path $OutDir "font-NotoSansRegular-$r.pbf") -Force
            Write-Host "  ✓ $r"
        } else {
            Write-Host "  ! диапазон $r не найден" -ForegroundColor Yellow
        }
    }
}

# ── 5. Итог ─────────────────────────────────────────────────────────────────
Write-Step "Готово"
Get-ChildItem $OutDir | Where-Object { $_.Name -like "*.pmtiles" -or $_.Name -like "font-*" } |
    ForEach-Object { Write-Host ("  {0,-38} {1,8:N1} МБ" -f $_.Name, ($_.Length / 1MB)) }

Write-Host @"

Пакет лежит в TaxiDriver\Resources\Raw\map\ и попадёт в APK при сборке:
    .\build-driver.ps1

Карта работает офлайн; растровые тайлы OSM остаются запасным слоем
за пределами Тюменского района.
"@ -ForegroundColor Green
