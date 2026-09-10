#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════
#  TaxiTyumen — сборка офлайн-пакета карты (OpenStreetMap → PMTiles)
#  Тюмень + Тюменский район, векторные тайлы для приложения водителя.
#  Данные OpenStreetMap (ODbL): бесплатно, офлайн и коммерция разрешены.
#
#  Запуск:  ./TaxiDriver/maps/build-pmtiles.sh
#  Опции:   BOUNDS, MAXZOOM, OSM_URL, JAVA_HEAP, NE_URL, SKIP_FONTS=1, REUSE_OSM=1
#  Нужно:   Java 21+, ~6 ГБ на диске.
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail

BOUNDS="${BOUNDS:-64.95,56.70,66.35,57.75}"   # Тюмень + Тюменский район
MINZOOM="${MINZOOM:-0}"
MAXZOOM="${MAXZOOM:-14}"
OSM_URL="${OSM_URL:-https://download.geofabrik.de/russia/ural-fed-district-latest.osm.pbf}"
JAVA_HEAP="${JAVA_HEAP:-6g}"
NE_URL="${NE_URL:-}"                          # свой адрес полного Natural Earth

MAPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK_DIR="$MAPS_DIR/work"
SOURCES_DIR="$WORK_DIR/sources"
TMP_DIR="$WORK_DIR/tmp"
OUT_DIR="$(dirname "$MAPS_DIR")/Resources/Raw/map"
JAR="$WORK_DIR/planetiler.jar"
OSM_FILE="$WORK_DIR/region.osm.pbf"
OUT_FILE="$OUT_DIR/tyumen.pmtiles"
TEST_RES="https://github.com/onthegomap/planetiler/raw/main/planetiler-core/src/test/resources"

NE_PATH="$SOURCES_DIR/natural_earth_vector.sqlite.zip"
WP_PATH="$SOURCES_DIR/water-polygons-split-3857.zip"
LC_PATH="$SOURCES_DIR/lake_centerline.shp.zip"

mkdir -p "$WORK_DIR" "$SOURCES_DIR" "$TMP_DIR" "$OUT_DIR"
step() { printf '\n=== %s\n' "$1"; }

# Скачать файл по первому доступному зеркалу; .part — чтобы обрыв
# не оставил битый файл, который planetiler откажется читать
download_first() {
  local out="$1" timeout="$2"; shift 2
  if [ -f "$out" ]; then
    echo "  используется имеющийся файл ($(du -h "$out" | cut -f1))"
    return 0
  fi
  local u
  for u in "$@"; do
    echo "  скачивание $u"
    if curl -fL --retry 2 --max-time "$timeout" -o "$out.part" "$u"; then
      mv -f "$out.part" "$out"
      echo "  ✓ готово ($(du -h "$out" | cut -f1))"
      return 0
    fi
    rm -f "$out.part"
    echo "  ! недоступно: $u" >&2
  done
  return 1
}

step "Проверка Java"
java -version 2>&1 | head -1

step "Planetiler"
download_first "$JAR" 300 \
  "https://github.com/onthegomap/planetiler/releases/latest/download/planetiler.jar" \
  || { echo "Не удалось скачать planetiler.jar"; exit 1; }

step "Данные OpenStreetMap (Уральский ФО)"
if [ -f "$OSM_FILE" ] && [ "${REUSE_OSM:-0}" = "1" ]; then
  echo "  используем скачанный ранее файл (REUSE_OSM=1)"
else
  download_first "$OSM_FILE" 7200 "$OSM_URL" \
    || { echo "Не удалось скачать выгрузку OSM"; exit 1; }
fi
du -h "$OSM_FILE"

step "Вспомогательные источники (Natural Earth, водные полигоны)"

# Natural Earth: полный мировой набор (нижние зумы карты). naciscdn.org часто
# недоступен из РФ — цепочка зеркал, в конце лёгкий тестовый набор с GitHub:
# карта соберётся в любом случае, просто зумы 0–9 будут визуально проще.
NE_CANDIDATES=()
[ -n "$NE_URL" ] && NE_CANDIDATES+=("$NE_URL")
NE_CANDIDATES+=(
  "https://naciscdn.org/naturalearth/packages/natural_earth_vector.sqlite.zip"
  "$TEST_RES/natural_earth_vector.sqlite.zip"
)
download_first "$NE_PATH" 600 "${NE_CANDIDATES[@]}" \
  || { echo "Natural Earth не удалось скачать ни из одного зеркала"; exit 1; }

ne_size=$(stat -c%s "$NE_PATH" 2>/dev/null || stat -f%z "$NE_PATH")
if [ "$ne_size" -lt 20000000 ]; then
  cat >&2 <<EOF
  ВНИМАНИЕ: полный Natural Earth (~240 МБ) с naciscdn.org недоступен —
  взята маленькая официальная тестовая версия с GitHub. Карта полноценна
  с зума 10 и детальнее (всё из OSM), зумы 0-9 станут визуально проще.

  Полные низкие зумы: скачайте
    https://naciscdn.org/naturalearth/packages/natural_earth_vector.sqlite.zip
  в другой сети (или через VPN), положите сюда:
    $NE_PATH
  и перезапустите с REUSE_OSM=1.
EOF
fi

# Водные полигоны: Тюмень внутри материка — лёгкий тестовый набор
# (реки и пруды города всё равно идут из OSM).
download_first "$WP_PATH" 120 "$TEST_RES/water-polygons-split-3857.zip" \
  || { echo "Не удалось скачать водные полигоны"; exit 1; }

step "Сборка PMTiles: bbox $BOUNDS, зумы $MINZOOM–$MAXZOOM"
rm -f "$OUT_FILE"
java "-Xmx$JAVA_HEAP" -jar "$JAR" \
  --osm-path="$OSM_FILE" \
  --bounds="$BOUNDS" \
  --minzoom="$MINZOOM" \
  --maxzoom="$MAXZOOM" \
  --languages=ru,en \
  --download \
  --download-dir="$SOURCES_DIR" \
  --tmpdir="$TMP_DIR" \
  --natural-earth-path="$NE_PATH" \
  --water-polygons-path="$WP_PATH" \
  --lake-centerlines-path="$LC_PATH" \
  --output="$OUT_FILE" \
  --force

[ -f "$OUT_FILE" ] || { echo "PMTiles не создан — смотрите лог выше"; exit 1; }
du -h "$OUT_FILE"

if [ "${SKIP_FONTS:-0}" != "1" ]; then
  step "Шрифты подписей (Noto Sans, кириллица)"
  FONTS_ZIP="$WORK_DIR/fonts.zip"
  FONTS_DIR="$WORK_DIR/fonts"
  download_first "$FONTS_ZIP" 300 \
    "https://github.com/openmaptiles/fonts/releases/download/v2.0/v2.0.zip" \
    || { echo "Не удалось скачать шрифты"; exit 1; }
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
