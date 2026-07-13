#!/bin/bash
set -e
cd "$(dirname "$0")"
if [ ! -d ".venv" ]; then
  python3 -m venv .venv
fi
source .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install -r requirements.txt
if [ ! -f .env ]; then
  cp .env.example .env
fi
python -m uvicorn app.main:app --host 127.0.0.1 --port 8765 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true' EXIT INT TERM
for _ in {1..30}; do
  if curl -fsS http://127.0.0.1:8765/health >/dev/null 2>&1; then
    open "http://127.0.0.1:8765" 2>/dev/null || true
    break
  fi
  sleep 1
done
wait $SERVER_PID
