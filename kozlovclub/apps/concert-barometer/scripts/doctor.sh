#!/usr/bin/env bash
# Check prerequisites and say plainly what is missing.
# Runs before `make bootstrap` so a missing tool is a sentence, not a stack trace.
set -uo pipefail

ok=0
say()  { printf '  %-22s %s\n' "$1" "$2"; }
fail() { ok=1; }

# Versions the dependency stack has been run against end to end.
VERIFIED="3.11 3.12 3.13"

echo "Checking prerequisites"

# --- Python ---------------------------------------------------------------
chosen=""
for p in python3.13 python3.12 python3.11 python3; do
  if command -v "$p" >/dev/null 2>&1; then chosen="$p"; break; fi
done

if [ -z "$chosen" ]; then
  say "python3" "NOT FOUND. Install Python 3.11, 3.12 or 3.13."
  fail
else
  ver="$("$chosen" -c 'import sys; print("%d.%d" % sys.version_info[:2])')"
  major="${ver%%.*}"; minor="${ver##*.}"
  if [ "$major" -ne 3 ] || [ "$minor" -lt 11 ]; then
    say "$chosen" "$ver, TOO OLD. Need 3.11 or newer."
    fail
  elif [[ " $VERIFIED " == *" $ver "* ]]; then
    say "$chosen" "$ver, ok"
  else
    say "$chosen" "$ver, NOT VERIFIED"
    echo
    echo "  Only Python $VERIFIED have been run end to end. On 3.14 the"
    echo "  dependency stack currently fails with:"
    echo "    TypeError: _eval_type() got an unexpected keyword argument"
    echo "               'prefer_fwd_module'"
    echo
    echo "  The build will try anyway. If it fails that way, install a"
    echo "  supported interpreter and point make at it:"
    echo
    echo "    brew install python@3.13"
    echo "    make bootstrap PYTHON_BIN=python3.13"
    echo
  fi
fi

# --- Database -------------------------------------------------------------
if command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  say "docker" "running, ok"
elif command -v docker >/dev/null 2>&1; then
  say "docker" "installed but not running. Start Docker Desktop."
  fail
elif command -v pg_isready >/dev/null 2>&1 && pg_isready -q 2>/dev/null; then
  say "docker" "not found, but PostgreSQL is running locally"
  echo
  echo "  Use the local server instead of Docker:"
  echo
  echo "    createdb barometer"
  echo "    export BAROMETER_DATABASE_URL='postgresql+psycopg://\$(whoami)@localhost:5432/barometer'"
  echo "    make bootstrap-no-docker"
  echo
  fail
else
  say "database" "NOT FOUND. PostgreSQL is required."
  echo
  echo "  Docker is only used to run PostgreSQL. Either:"
  echo
  echo "    a) install Docker Desktop, then: make bootstrap"
  echo "    b) install PostgreSQL directly, then:"
  echo "         createdb barometer"
  echo "         export BAROMETER_DATABASE_URL='postgresql+psycopg://\$(whoami)@localhost:5432/barometer'"
  echo "         make bootstrap-no-docker"
  echo
  echo "  On macOS the least trouble is Postgres.app (postgresapp.com):"
  echo "  download, drag to Applications, open, click Initialize."
  echo
  fail
fi

if [ "$ok" -eq 0 ]; then
  echo
  echo "All prerequisites present."
fi
exit "$ok"
