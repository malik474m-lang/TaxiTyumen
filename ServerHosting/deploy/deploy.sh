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

# Реальный config.php с секретами: копируем ТОЛЬКО если его нет (не затираем настройки)
if [ ! -f "$ROOT/config.php" ]; then
  cp "$ROOT/ServerHosting/config.php" "$ROOT/config.php"
  echo "  config.php ← создан (настройте секреты БД)"
fi

# Иначе: ServerHosting/config.php тоже должен содержать реальные данные БД —
# deploy.sh копирует каталоги, но config.php никогда не трогает.

# Корневой .htaccess если ещё не создан
if [ ! -f "$ROOT/.htaccess" ]; then
  printf 'Options -Indexes\nAddDefaultCharset utf-8\n' > "$ROOT/.htaccess"
  echo "  .htaccess ← создан"
fi

echo "OK: корень домена синхронизирован с ServerHosting."
echo "После каждого 'git pull origin main' запускайте: bash ServerHosting/deploy/deploy.sh"
