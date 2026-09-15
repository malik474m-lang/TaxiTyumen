#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# TaxiTyumen — деплой на shared-хостинг (jino.ru и другие).
#
# ДВА режима запуска:
#
# 1. Если репозиторий склонирован в корень домена:
#      cd ~/domains/ВАШ-ДОМЕН
#      git pull origin main
#      bash ServerHosting/deploy/deploy.sh
#
# 2. Если файлы скопированы вручную (нет .git в корне домена):
#      cd ~/domains/ВАШ-ДОМЕН
#      git clone https://github.com/malik474m-lang/TaxiTyumen.git /tmp/taxi-deploy
#      bash /tmp/taxi-deploy/ServerHosting/deploy/deploy.sh
#    Скрипт сам найдёт репозиторий и скопирует файлы.
# ═══════════════════════════════════════════════════════════════════════════
set -euo pipefail

ROOT="$(pwd)"
REPO=""
REPO_URL="https://github.com/malik474m-lang/TaxiTyumen.git"
TMP_REPO="/tmp/taxi-deploy-$$"

# ── Находим репозиторий ─────────────────────────────────────────────────────
if [ -d "$ROOT/ServerHosting" ] && [ -d "$ROOT/.git" ]; then
    # Режим 1: репозиторий в корне домена
    REPO="$ROOT"
    echo "Режим: репозиторий в корне домена"
elif [ -d "$ROOT/ServerHosting" ]; then
    # Режим 1б: папка ServerHosting есть, но без .git (файлы скопированы)
    REPO="$ROOT"
    echo "Режим: файлы уже скопированы (без .git)"
else
    # Режим 2: нужен клон
    echo "Репозиторий не найден в текущей папке."
    echo "Клонирую во временную папку..."
    rm -rf "$TMP_REPO"
    git clone --depth 1 "$REPO_URL" "$TMP_REPO" 2>/dev/null || {
        echo "Ошибка клонирования. Проверьте доступ к GitHub." >&2
        exit 1
    }
    REPO="$TMP_REPO"
    echo "Режим: свежий клон из GitHub"
fi

if [ ! -d "$REPO/ServerHosting" ]; then
    echo "Ошибка: в $REPO нет папки ServerHosting" >&2
    rm -rf "$TMP_REPO"
    exit 1
fi

# ── Обновляем если это git-репозиторий ─────────────────────────────────────
if [ -d "$REPO/.git" ]; then
    echo "Обновляю репозиторий..."
    (cd "$REPO" && git pull --ff-only origin main 2>/dev/null) || \
        echo "  git pull не удался, использую текущее состояние"
fi

# ── Синхронизируем файлы ───────────────────────────────────────────────────
echo ""
echo "Копирую файлы:"

for d in api admin src sql; do
    rm -rf "$ROOT/$d"
    cp -r "$REPO/ServerHosting/$d" "$ROOT/$d"
    echo "  ✓ $d/"
done

# config.php — обновляем всегда (несекретная часть)
cp -f "$REPO/ServerHosting/config.php" "$ROOT/config.php"
echo "  ✓ config.php"

# config.local.php — НЕ перезаписываем (там секреты)
if [ ! -f "$ROOT/config.local.php" ]; then
    if [ -f "$REPO/ServerHosting/config.local.php" ]; then
        cp "$REPO/ServerHosting/config.local.php" "$ROOT/config.local.php"
        echo "  ✓ config.local.php (создан)"
    fi
    echo "  ⚠ config.local.php не найден — запустите install.php"
fi

# Корневой index.php (редирект на админку, закрывает 403)
if [ -f "$REPO/Install-Taxi/index.php" ]; then
    cp -f "$REPO/Install-Taxi/index.php" "$ROOT/index.php"
    echo "  ✓ index.php"
elif [ ! -f "$ROOT/index.php" ]; then
    # Резерв: создаём минимальный если нет
    echo '<?php header("Location: admin/login.php");' > "$ROOT/index.php"
    echo "  ✓ index.php (создан)"
fi

# Корневой .htaccess
if [ -f "$REPO/Install-Taxi/.htaccess" ]; then
    cp -f "$REPO/Install-Taxi/.htaccess" "$ROOT/.htaccess"
    echo "  ✓ .htaccess"
elif [ ! -f "$ROOT/.htaccess" ]; then
    printf 'Options -Indexes\nAddDefaultCharset utf-8\n' > "$ROOT/.htaccess"
    echo "  ✓ .htaccess (создан)"
fi

# Загрузки (логотипы, фото) — создаём если нет, НЕ удаляем
for sub in branding applications drivers; do
    mkdir -p "$ROOT/uploads/$sub"
done
if [ -f "$REPO/ServerHosting/uploads/branding/.htaccess" ]; then
    cp -f "$REPO/ServerHosting/uploads/branding/.htaccess" "$ROOT/uploads/branding/.htaccess"
fi
if [ -f "$REPO/ServerHosting/uploads/drivers/.htaccess" ]; then
    cp -f "$REPO/ServerHosting/uploads/drivers/.htaccess" "$ROOT/uploads/drivers/.htaccess"
fi
echo "  ✓ uploads/"

# ── Установка лицензионного пакета ─────────────────────────────────────────
if [ -f "$REPO/Install-Taxi/install.php" ] && [ ! -f "$ROOT/install.php" ]; then
    cp "$REPO/Install-Taxi/install.php" "$ROOT/install.php"
    echo "  ✓ install.php (для первичной установки)"
fi

# ── Очистка ────────────────────────────────────────────────────────────────
if [ "$REPO" = "$TMP_REPO" ]; then
    rm -rf "$TMP_REPO"
fi

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "  ДЕПЛОЙ ЗАВЕРШЁН"
echo "═══════════════════════════════════════════════════════════════"
echo ""
echo "  Проверка:"
echo "    curl -s https://ВАШ-ДОМЕН/api/ | head -c 200"
echo ""
if [ ! -f "$ROOT/config.local.php" ]; then
    echo "  ⚠ База не настроена! Откройте:"
    echo "    https://ВАШ-ДОМЕН/install.php"
else
    echo "  ✓ config.local.php на месте"
    echo "  ✓ Система готова к работе"
fi
echo ""
