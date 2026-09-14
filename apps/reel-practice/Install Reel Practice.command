#!/bin/bash
set -e
HERE="$(cd "$(dirname "$0")" && pwd)"
SUPPORT="$HOME/Library/Application Support/Reel Practice"
APP="$HERE/Reel Practice.app"
PORT=8797

clear
echo "Reel Practice 2.7 — Mac app installer"
echo

if ! command -v ffmpeg >/dev/null 2>&1; then
  echo "FFmpeg not found. Install it first: brew install ffmpeg"
  read -n 1 -s -r -p "Press any key to close"
  exit 1
fi

if ! command -v tesseract >/dev/null 2>&1; then
  if command -v brew >/dev/null 2>&1; then
    echo "Installing Tesseract for on-video text analysis…"
    brew install tesseract
  else
    echo "Tesseract is required for Auto Tags from video. Install Homebrew, then: brew install tesseract"
    read -n 1 -s -r -p "Press any key to close"
    exit 1
  fi
fi

PYTHON_BIN="$(command -v python3 || true)"
if [ -z "$PYTHON_BIN" ]; then
  echo "Python 3 is required."
  read -n 1 -s -r -p "Press any key to close"
  exit 1
fi

PY_OK=$($PYTHON_BIN - <<'PY'
import sys
print(1 if sys.version_info >= (3,10) else 0)
PY
)
if [ "$PY_OK" != "1" ] && command -v brew >/dev/null 2>&1; then
  echo "Updating Python with Homebrew…"
  brew install python
  if [ -x /opt/homebrew/bin/python3 ]; then PYTHON_BIN=/opt/homebrew/bin/python3; elif [ -x /usr/local/bin/python3 ]; then PYTHON_BIN=/usr/local/bin/python3; fi
fi

echo "Using: $($PYTHON_BIN --version)"
mkdir -p "$SUPPORT"

# Preserve existing app data in Application Support, otherwise import the newest sibling build.
if [ ! -f "$SUPPORT/data/reel_practice.sqlite3" ]; then
  for OLD in "$HERE/../reel_practice_mac_v231/data" "$HERE/../reel_practice_mac_v23/data" "$HERE/../reel_practice_mac_v22/data" "$HERE/../reel_practice_mac_v21/data"; do
    if [ -f "$OLD/reel_practice.sqlite3" ]; then
      echo "Importing existing library from: $OLD"
      rm -rf "$SUPPORT/data"
      cp -R "$OLD" "$SUPPORT/data"
      break
    fi
  done
fi
mkdir -p "$SUPPORT/data/videos" "$SUPPORT/data/thumbs" "$SUPPORT/data/tabs" "$SUPPORT/data/sheets" "$SUPPORT/data/_scan"

# Update application code, never overwrite the user's data directory.
cp "$HERE/server/app.py" "$SUPPORT/app.py"
cp "$HERE/server/requirements.txt" "$SUPPORT/requirements.txt"
rm -rf "$SUPPORT/static"
cp -R "$HERE/server/static" "$SUPPORT/static"

echo "Installing backend dependencies…"
rm -rf "$SUPPORT/.venv"
"$PYTHON_BIN" -m venv "$SUPPORT/.venv"
. "$SUPPORT/.venv/bin/activate"
python -m pip install --upgrade pip >/dev/null
pip install --upgrade -r "$SUPPORT/requirements.txt"

# Build a real macOS app launcher. Daily use does not open Terminal.
rm -rf "$APP"
osacompile -s -o "$APP" "$HERE/launcher.applescript"

# Add a readable app name/version to Info.plist where possible.
/usr/libexec/PlistBuddy -c "Set :CFBundleName Reel Practice" "$APP/Contents/Info.plist" >/dev/null 2>&1 || true
/usr/libexec/PlistBuddy -c "Set :CFBundleDisplayName Reel Practice" "$APP/Contents/Info.plist" >/dev/null 2>&1 || true
/usr/libexec/PlistBuddy -c "Add :CFBundleShortVersionString string 2.7" "$APP/Contents/Info.plist" >/dev/null 2>&1 || /usr/libexec/PlistBuddy -c "Set :CFBundleShortVersionString 2.7" "$APP/Contents/Info.plist" >/dev/null 2>&1 || true

# Stop an old 2.7 process if one exists.
/usr/sbin/lsof -tiTCP:$PORT -sTCP:LISTEN | /usr/bin/xargs -r /bin/kill 2>/dev/null || true

echo
echo "Installed."
echo "Your library is stored safely in:"
echo "$SUPPORT/data"
echo
echo "Daily use: double-click Reel Practice.app"
echo "You can drag Reel Practice.app to Applications or the Dock."
echo "Terminal is NOT needed after installation."
echo
read -n 1 -s -r -p "Press any key to close"
