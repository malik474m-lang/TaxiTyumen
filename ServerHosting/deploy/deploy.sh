#!/bin/bash
# Мануальный деплой для хостингов, где запрещены симлинки (403 Forbidden)
# Запускать ИЗ КОРНЯ домена после `git pull origin main`:
#   bash ServerHosting/deploy/deploy.sh
set -euo pipefail

ROOT="$(pwd)"
if [ ! -d "$ROOT/ServerHosting" ]; then
  echo "Ошибка: нет папки ServerHosting. Запускайте из корня домена." >&2
  exit 1
fi

# Реальные копии вместо симлинков — полностью совместимо с любым хостингом
for d in api admin src sql; do
  rm -rf "$ROOT/$d"
  cp -r "$ROOT/ServerHosting/$d" "$ROOT/$d"
  echo "  $d ← ServerHosting/$d"
done

# Постоянное хранилище логотипов (не удаляем при следующих деплоях)
mkdir -p "$ROOT/uploads/branding"
if [ -f "$ROOT/ServerHosting/uploads/branding/.htaccess" ]; then
  cp "$ROOT/ServerHosting/uploads/branding/.htaccess" "$ROOT/uploads/branding/.htaccess"
fi

# Базовый config.php можно обновлять из Git; реальные секреты — только config.local.php
if [ ! -f "$ROOT/config.php" ]; then
  cp "$ROOT/ServerHosting/config.php" "$ROOT/config.php"
  echo "  config.php ← создан"
fi
if [ ! -f "$ROOT/config.local.php" ] && [ -f "$ROOT/ServerHosting/config.local.php" ]; then
  cp "$ROOT/ServerHosting/config.local.php" "$ROOT/config.local.php"
  echo "  config.local.php ← локальные секреты"
fi

# config.local.php никогда не удаляется и не отслеживается Git.

# Корневой .htaccess если ещё не создан
if [ ! -f "$ROOT/.htaccess" ]; then
  printf 'Options -Indexes\nAddDefaultCharset utf-8\n' > "$ROOT/.htaccess"
  echo "  .htaccess ← создан"
fi

echo "OK: корень домена синхронизирован с ServerHosting."
echo "После каждого 'git pull origin main' запускайте: bash ServerHosting/deploy/deploy.sh"
