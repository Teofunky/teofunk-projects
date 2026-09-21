#!/usr/bin/env bash
# Check prerequisites and say plainly what is missing.
# Runs before `make bootstrap` so a missing tool is a sentence, not a stack trace.
set -uo pipefail

ok=0
say()  { printf '  %-22s %s\n' "$1" "$2"; }
fail() { ok=1; }

echo "Checking prerequisites"

if command -v python3 >/dev/null 2>&1; then
  ver="$(python3 -c 'import sys; print("%d.%d" % sys.version_info[:2])')"
  major="${ver%%.*}"; minor="${ver##*.}"
  if [ "$major" -gt 3 ] || { [ "$major" -eq 3 ] && [ "$minor" -ge 11 ]; }; then
    say "python3" "$ver, ok"
  else
    say "python3" "$ver, TOO OLD. Need 3.11 or newer."
    fail
  fi
else
  say "python3" "NOT FOUND. Install Python 3.11 or newer."
  fail
fi

if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  say "docker" "running, ok"
elif command -v docker >/dev/null 2>&1; then
  say "docker" "installed but not running. Start Docker Desktop."
  fail
else
  say "docker" "NOT FOUND."
  echo
  echo "  Docker is only used to run PostgreSQL. If you already have"
  echo "  PostgreSQL 14+ somewhere, skip it:"
  echo
  echo "    export BAROMETER_DATABASE_URL='postgresql+psycopg://user:pass@host:5432/barometer'"
  echo "    make bootstrap-no-docker"
  echo
  fail
fi

if [ "$ok" -eq 0 ]; then
  echo
  echo "All prerequisites present."
fi
exit "$ok"
