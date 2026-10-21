#!/usr/bin/env bash
# Destructive test harness. Read TESTING.md. Never target a production or staging copy.
set -euo pipefail
umask 077
: "${ES_TEST_BOOTSTRAP:?Absolute wp-load.php path required}"
: "${ES_TEST_PHP:?Absolute PHP executable or wrapper required}"
: "${ES_TEST_BASE_URL:?Local WordPress URL required}"
if [[ "${ES_TEST_ALLOW_RESET:-}" != "1" ]]; then
  echo 'Set ES_TEST_ALLOW_RESET=1 only for a disposable local database.' >&2
  exit 2
fi
ES_TEST_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
export ES_TEST_ROOT="${ES_TEST_ROOT:-$PWD}"
export ES_TEST_OUTPUT="${ES_TEST_OUTPUT:-$(mktemp -d)}"
mkdir -p "$ES_TEST_OUTPUT"
export ES_TEST_STATE="$ES_TEST_OUTPUT/test-state.json"
trap 'rm -f -- "$ES_TEST_STATE"' EXIT
"$ES_TEST_PHP" "$ES_TEST_DIR/integration.php"
node "$ES_TEST_DIR/browser.cjs"
node "$ES_TEST_DIR/navigation-browser.cjs"
node "$ES_TEST_DIR/audit-browser.cjs"
node "$ES_TEST_DIR/safety-browser.cjs"
"$ES_TEST_PHP" "$ES_TEST_DIR/advanced.php"
