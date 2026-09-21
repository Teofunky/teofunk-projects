#!/usr/bin/env bash
# Regenerate db/schema.sql from a migrated database.
# db/schema.sql is a READ-ONLY REFERENCE. Migrations own the schema.
set -euo pipefail

HOST="${PGHOST:-localhost}"
PORT="${PGPORT:-5432}"
USER="${PGUSER:-barometer}"
DB="${PGDATABASE:-barometer}"
OUT="$(dirname "$0")/../db/schema.sql"

{
  cat <<'HEADER'
-- =============================================================================
-- Концертный барометр: reference schema dump
--
-- GENERATED FILE. Do not edit by hand and do not apply it directly.
-- The schema is owned by the Alembic migrations in migrations/versions/.
-- Regenerate with:  make schema-dump
--
-- It exists so the structure can be read and reviewed without a database, and
-- so a schema change is visible in a diff.
-- =============================================================================

HEADER
  pg_dump -h "$HOST" -p "$PORT" -U "$USER" -d "$DB" \
    --schema-only --no-owner --no-privileges \
    -n registry -n raw -n norm -n feature -n model -n ops
} > "$OUT"

echo "wrote $OUT ($(wc -l < "$OUT") lines)"
