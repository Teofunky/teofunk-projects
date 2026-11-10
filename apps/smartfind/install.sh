#!/bin/bash
# Установка SmartFind: копирует программу, ставит зависимости, создаёт ~/Applications/SmartFind.app
set -e
APP_NAME="SmartFind"
SRC="$(cd "$(dirname "$0")" && pwd)"
DEST="$HOME/Library/Application Support/$APP_NAME"
APP="$HOME/Applications/$APP_NAME.app"

echo "→ Копирую файлы в $DEST"
mkdir -p "$DEST/ui"
cp "$SRC/app.py" "$SRC/engine.py" "$SRC/cleanup.py" "$DEST/"
# если SmartFind уже запущен, закрываем его, чтобы подхватилась новая версия
pkill -f "$DEST/app.py" 2>/dev/null || true
pkill -f "SmartFind/venv/bin/python app.py" 2>/dev/null || true
cp "$SRC/ui/index.html" "$DEST/ui/"

PY=""
for c in /opt/homebrew/bin/python3 /usr/local/bin/python3 /usr/bin/python3; do
  if [ -x "$c" ] && "$c" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 9) else 1)' 2>/dev/null; then
    PY="$c"; break
  fi
done
if [ -z "$PY" ]; then
  echo "✗ Нужен Python 3.9+. Поставь инструменты командной строки: xcode-select --install"; exit 1
fi
echo "→ Python: $PY ($("$PY" --version 2>&1))"

if [ ! -x "$DEST/venv/bin/python" ]; then
  "$PY" -m venv "$DEST/venv"
fi
echo "→ Ставлю зависимости (pywebview, certifi)"
"$DEST/venv/bin/python" -m pip install --upgrade pip -q
"$DEST/venv/bin/python" -m pip install --upgrade -q pywebview certifi

echo "→ Создаю $APP"
mkdir -p "$HOME/Applications"
rm -rf "$APP"
osacompile -o "$APP" -e "do shell script quoted form of \"$DEST/venv/bin/python\" & \" \" & quoted form of \"$DEST/app.py\" & \" > /dev/null 2>&1 &\""

echo "✓ Готово. Открой SmartFind из Spotlight (⌘Пробел → SmartFind) или из папки ~/Applications."
echo "  Лог поиска: $DEST/smartfind.log"
