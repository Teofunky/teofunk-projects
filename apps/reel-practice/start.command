#!/bin/bash
set -e
cd "$(dirname "$0")"
if [ ! -d .venv ]; then echo "Run install.command first"; read -n 1; exit 1; fi
source .venv/bin/activate
PORT=8787
(sleep 1.3; open "http://127.0.0.1:$PORT") &
echo "Reel Practice is running"
echo "Open: http://127.0.0.1:$PORT"
echo "Keep this Terminal window open. Ctrl+C stops the app."
uvicorn app:app --host 127.0.0.1 --port $PORT
