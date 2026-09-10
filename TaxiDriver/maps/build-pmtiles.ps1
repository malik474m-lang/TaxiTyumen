# ═══════════════════════════════════════════════════════════════════════════
#  TaxiTyumen — сборка офлайн-пакета карты (OpenStreetMap → PMTiles)
#  Тюмень + Тюменский район, векторные тайлы для приложения водителя.
#
#  Данные: OpenStreetMap (ODbL) — бесплатно, коммерческое использование
#  и офлайн разрешены. Ключи и лимиты не нужны.
#
#  Запуск (из любой папки):
#      .\TaxiDriver\maps\build-pmtiles.ps1
#      .\TaxiDriver\maps\build-pmtiles.ps1 -ReuseOsm      # не перекачивать OSM
#      .\TaxiDriver\maps\build-pmtiles.ps1 -SkipFonts     # только карта
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
    # Полный Natural Earth (~240 МБ) заказной адрес — если никакие зеркала недоступны,
    # можно скачать файл по любой ссылке (например, через VPN) в другом месте
    # и просто положить его в maps\work\sources\natural_earth_vector.sqlite.zip
    [string]$NaturalEarthUrl = "",
    [string]$JavaHeap = "6g",
    [switch]$SkipFonts,
    [switch]$ReuseOsm
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

$MapsDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$WorkDir  = Join-Path $MapsDir "work"
$SourcesDir = Join-Path $WorkDir "sources"
$TmpDir   = Join-Path $WorkDir "tmp"
$OutDir   = Join-Path (Split-Path -Parent $MapsDir) "Resources\Raw\map"
$Jar      = Join-Path $WorkDir "planetiler.jar"
$OsmFile  = Join-Path $WorkDir "region.osm.pbf"
$OutFile  = Join-Path $OutDir  "tyumen.pmtiles"
$TestRes  = "https://github.com/onthegomap/planetiler/raw/main/planetiler-core/src/test/resources"

New-Item -ItemType Directory -Force -Path $WorkDir, $SourcesDir, $TmpDir, $OutDir | Out-Null

function Write-Step($text) { Write-Host "`n=== $text" -ForegroundColor Cyan }

# Скачать файл по первому доступному зеркалу; .part — чтобы обрыв
# не оставил битый файл, который planetiler потом откажется читать
function Save-FromMirrors {
    param(
        [Parameter(Mandatory)][string]$OutFile,
        [Parameter(Mandatory)][string[]]$Urls,
        [int]$TimeoutSec = 150
    )
    if (Test-Path $OutFile) {
        Write-Host ("  используется имеющийся файл ({0:N1} МБ)" -f ((Get-Item $OutFile).Length / 1MB))
        return $true
    }
    foreach ($u in $Urls) {
        try {
            Write-Host "  скачивание $u"
            Invoke-WebRequest -Uri $u -OutFile "$OutFile.part" -TimeoutSec $TimeoutSec
            Move-Item -Force "$OutFile.part" $OutFile
            Write-Host ("  ✓ готово ({0:N1} МБ)" -f ((Get-Item $OutFile).Length / 1MB)) -ForegroundColor Green
            return $true
        } catch {
            Remove-Item -ErrorAction SilentlyContinue "$OutFile.part"
            Write-Host "  ! недоступно: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }
    return $false
}

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
    if (-not (Save-FromMirrors -OutFile $Jar -TimeoutSec 300 -Urls @(
        "https://github.com/onthegomap/planetiler/releases/latest/download/planetiler.jar"
    ))) { throw "Не удалось скачать planetiler.jar — проверьте интернет." }
}
Write-Host ("  planetiler.jar: {0:N1} МБ" -f ((Get-Item $Jar).Length / 1MB))

# ── 2. Выгрузка OpenStreetMap ───────────────────────────────────────────────
Write-Step "Данные OpenStreetMap (Уральский ФО)"
if ((Test-Path $OsmFile) -and $ReuseOsm) {
    Write-Host "  используем скачанный ранее файл (-ReuseOsm)"
} elseif (-not (Save-FromMirrors -OutFile $OsmFile -Urls @($OsmUrl) -TimeoutSec 3600)) {
    throw "Не удалось скачать выгрузку OSM — проверьте интернет или задайте -OsmUrl."
}
Write-Host ("  region.osm.pbf: {0:N1} МБ" -f ((Get-Item $OsmFile).Length / 1MB))

# ── 3. Вспомогательные источники карты ─────────────────────────────────────
Write-Step "Вспомогательные источники (Natural Earth, водные полигоны)"
$NePath = Join-Path $SourcesDir "natural_earth_vector.sqlite.zip"
$WpPath = Join-Path $SourcesDir "water-polygons-split-3857.zip"
$LcPath = Join-Path $SourcesDir "lake_centerline.shp.zip"

# Если прежний запуск качал в папку пользователя (%USERPROFILE%\data) —
# переносим драгоценные мегабайты в кеш скрипта, дальше всё лежит в maps\work
$stray = Join-Path $env:USERPROFILE "data\sources"
if (Test-Path $stray) {
    foreach ($f in Get-ChildItem $stray -File -ErrorAction SilentlyContinue) {
        $dest = Join-Path $SourcesDir $f.Name
        if (-not (Test-Path $dest) -and $f.Length -gt 100000) {
            Copy-Item $f.FullName $dest
            Write-Host "  перенесено из старой папки: $($f.Name)"
        }
    }
}

# Natural Earth: полный мировой набор (нижние зумы карты, здания заливка и т.д.)
# naciscdn.org часто недоступен из РФ — поэтому цепочка зеркал, а в конце
# лёгкий официальный тестовый набор с GitHub (карта соберётся в любом случае,
# просто зумы 0–9 будут визуально скромнее).
$neUrls = @()
if ($NaturalEarthUrl -ne "") { $neUrls += $NaturalEarthUrl }
$neUrls += @(
    "https://naciscdn.org/naturalearth/packages/natural_earth_vector.sqlite.zip",
    "$TestRes/natural_earth_vector.sqlite.zip"
)
$neSize = 0
if (Save-FromMirrors -OutFile $NePath -Urls $neUrls -TimeoutSec 300) {
    $neSize = (Get-Item $NePath).Length
    if ($neSize -lt 20000000) {
        Write-Host @"
  ВНИМАНИЕ: полный Natural Earth (~240 МБ) с naciscdn.org недоступен —
  взята маленькая официальная тестовая версия с GitHub. Карта будет
  полноценной с зума 10 и детальнее (улицы, дома — всё из OSM),
  зумы 0-9 станут визуально проще.

  Как получить полные низкие зумы:
  1. Скачайте https://naciscdn.org/naturalearth/packages/natural_earth_vector.sqlite.zip
     (~240 МБ; из другой сети или через VPN).
  2. Положите файл сюда:
     $NePath
  3. Запустите скрипт ещё раз с ключом -ReuseOsm (OSM перекачиваться не будет).
"@ -ForegroundColor Yellow
    }
} else {
    throw "Natural Earth не удалось скачать ни из одного зеркала."
}

# Водные полигоны: Тюмень внутри материка — берём лёгкий тестовый набор
# (океаны/заливы на нижних зумах; реки и пруды города всё равно идут из OSM).
if (-not (Save-FromMirrors -OutFile $WpPath -Urls @("$TestRes/water-polygons-split-3857.zip"))) {
    throw "Не удалось скачать водные полигоны."
}

# ── 4. Сборка векторных тайлов ──────────────────────────────────────────────
Write-Step "Сборка PMTiles: bbox $Bounds, зумы $MinZoom–$MaxZoom"
if (Test-Path $OutFile) { Remove-Item $OutFile -Force }

# Источники подкладываем по -path (обходит недоступные серверы и любые
# проблемы c -url переопределениями); кеши и временные файлы — в maps\work,
# а не в папке пользователя.
& java "-Xmx$JavaHeap" -jar $Jar `
    --osm-path=$OsmFile `
    --bounds=$Bounds `
    --minzoom=$MinZoom `
    --maxzoom=$MaxZoom `
    --languages=ru,en `
    --download `
    --download-dir=$SourcesDir `
    --tmpdir=$TmpDir `
    --natural-earth-path=$NePath `
    --water-polygons-path=$WpPath `
    --lake-centerlines-path=$LcPath `
    --output=$OutFile `
    --force

if (-not (Test-Path $OutFile)) { throw "PMTiles не создан — смотрите лог выше" }
Write-Host ("  ✓ tyumen.pmtiles: {0:N1} МБ" -f ((Get-Item $OutFile).Length / 1MB)) -ForegroundColor Green

# ── 5. Шрифты подписей (кириллица) ──────────────────────────────────────────
if (-not $SkipFonts) {
    Write-Step "Шрифты подписей (Noto Sans, кириллица)"
    $fontsZip = Join-Path $WorkDir "fonts.zip"
    $fontsDir = Join-Path $WorkDir "fonts"
    if (-not (Save-FromMirrors -OutFile $fontsZip -Urls @(
        "https://github.com/openmaptiles/fonts/releases/download/v2.0/v2.0.zip"
    ))) { throw "Не удалось скачать шрифты." }
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

# ── 6. Итог ─────────────────────────────────────────────────────────────────
Write-Step "Готово"
Get-ChildItem $OutDir | Where-Object { $_.Name -like "*.pmtiles" -or $_.Name -like "font-*" } |
    ForEach-Object { Write-Host ("  {0,-38} {1,8:N1} МБ" -f $_.Name, ($_.Length / 1MB)) }

# Подсказка по мусору от первых запусков в папке пользователя
if (Test-Path (Join-Path $env:USERPROFILE "data")) {
    Write-Host "`nПодсказка: папка $env:USERPROFILE\data осталась от первого запуска — её можно удалить." -ForegroundColor DarkGray
}

Write-Host @"

Пакет лежит в TaxiDriver\Resources\Raw\map\ и попадёт в APK при сборке:
    .\build-driver.ps1

Карта работает офлайн; растровые тайлы OSM остаются запасным слоем
за пределами Тюменского района и на масштабах ниже 10.
"@ -ForegroundColor Green
