#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
#  TaxiTyumen — сборка офлайн-пакета карты (OpenStreetMap → PMTiles)
#  Тюмень + Тюменский район, векторные тайлы для приложения водителя.
#  Данные OpenStreetMap (ODbL): бесплатно, офлайн и коммерция разрешены.
#
#  Запуск:  ./TaxiDriver/maps/build-pmtiles.sh
#  Опции:   BOUNDS, MAXZOOM, OSM_URL, JAVA_HEAP, SKIP_FONTS=1, REUSE_OSM=1
#  Нужно:   Java 21+ (или Docker), ~6 ГБ на диске.
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail

BOUNDS="${BOUNDS:-64.95,56.70,66.35,57.75}"   # Тюмень + Тюменский район
MINZOOM="${MINZOOM:-0}"
MAXZOOM="${MAXZOOM:-14}"
OSM_URL="${OSM_URL:-https://download.geofabrik.de/russia/ural-fed-district-latest.osm.pbf}"
JAVA_HEAP="${JAVA_HEAP:-4g}"

MAPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$MAPS_DIR/work"
OUT_DIR="$(dirname "$MAPS_DIR")/Resources/Raw/map"
JAR="$WORK_DIR/planetiler.jar"
OSM_FILE="$WORK_DIR/region.osm.pbf"
OUT_FILE="$OUT_DIR/tyumen.pmtiles"
TEST_RES="https://github.com/onthegomap/planetiler/raw/main/planetiler-core/src/test/resources"

mkdir -p "$WORK_DIR" "$OUT_DIR"
step() { printf '\n=== %s\n' "$1"; }

step "Проверка Java"
java -version 2>&1 | head -1

step "Planetiler"
[ -f "$JAR" ] || curl -fL --progress-bar -o "$JAR" \
  "https://github.com/onthegomap/planetiler/releases/latest/download/planetiler.jar"

step "Данные OpenStreetMap"
if [ -f "$OSM_FILE" ] && [ "${REUSE_OSM:-0}" = "1" ]; then
  echo "  используем скачанный ранее файл (REUSE_OSM=1)"
else
  curl -fL --progress-bar -o "$OSM_FILE" "$OSM_URL"
fi
du -h "$OSM_FILE"

step "Сборка PMTiles: bbox $BOUNDS, зумы $MINZOOM–$MAXZOOM"
rm -f "$OUT_FILE"
java "-Xmx$JAVA_HEAP" -jar "$JAR" \
  --osm-path="$OSM_FILE" \
  --bounds="$BOUNDS" \
  --minzoom="$MINZOOM" \
  --maxzoom="$MAXZOOM" \
  --languages=ru,en \
  --download \
  --water-polygons-url="$TEST_RES/water-polygons-split-3857.zip" \
  --natural-earth-url="$TEST_RES/natural_earth_vector.sqlite.zip" \
  --output="$OUT_FILE" \
  --force

[ -f "$OUT_FILE" ] || { echo "PMTiles не создан"; exit 1; }
du -h "$OUT_FILE"

if [ "${SKIP_FONTS:-0}" != "1" ]; then
  step "Шрифты подписей (Noto Sans, кириллица)"
  FONTS_ZIP="$WORK_DIR/fonts.zip"
  FONTS_DIR="$WORK_DIR/fonts"
  [ -f "$FONTS_ZIP" ] || curl -fL --progress-bar -o "$FONTS_ZIP" \
    "https://github.com/openmaptiles/fonts/releases/download/v2.0/v2.0.zip"
  rm -rf "$FONTS_DIR" && mkdir -p "$FONTS_DIR"
  unzip -q "$FONTS_ZIP" -d "$FONTS_DIR"

  STACK_DIR="$(find "$FONTS_DIR" -type d -name 'Noto Sans Regular' | head -1)"
  [ -n "$STACK_DIR" ] || { echo "нет набора 'Noto Sans Regular'"; exit 1; }
  for r in 0-255 256-511 1024-1279 8192-8447; do
    if [ -f "$STACK_DIR/$r.pbf" ]; then
      cp "$STACK_DIR/$r.pbf" "$OUT_DIR/font-NotoSansRegular-$r.pbf"
      echo "  ✓ $r"
    else
      echo "  ! диапазон $r не найден"
    fi
  done
fi

step "Готово"
ls -lh "$OUT_DIR" | grep -E 'pmtiles|font-' || true
echo
echo "Пакет попадёт в APK при сборке приложения водителя."
