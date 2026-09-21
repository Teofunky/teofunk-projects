-- Prologue for migration 0001: schemas, extensions and enum types.
-- Runs before any CREATE TABLE.

CREATE SCHEMA IF NOT EXISTS registry;
CREATE SCHEMA IF NOT EXISTS raw;
CREATE SCHEMA IF NOT EXISTS norm;
CREATE SCHEMA IF NOT EXISTS feature;
CREATE SCHEMA IF NOT EXISTS model;
CREATE SCHEMA IF NOT EXISTS ops;

COMMENT ON SCHEMA registry IS
  'What we may observe and what a value means. Projection of config/*.yaml.';
COMMENT ON SCHEMA raw IS
  'Immutable evidence. Append-only. Every number downstream traces to a row here.';
COMMENT ON SCHEMA norm IS
  'Observations in canonical units, vintaged. Append-only; a revision is a new row.';
COMMENT ON SCHEMA feature IS
  'Model-ready columns carrying the vintage and availability clock of their inputs.';
COMMENT ON SCHEMA model IS
  'Model output. Written by the quant pipeline only. No agent has INSERT here.';
COMMENT ON SCHEMA ops IS
  'Pipeline runs, source health, data-quality verdicts, agent audit trail.';

-- gen_random_uuid() is built in from PostgreSQL 13; pgcrypto stays as a fallback
-- for older servers and is harmless where the function already exists.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_proc WHERE proname = 'gen_random_uuid') THEN
    CREATE EXTENSION IF NOT EXISTS pgcrypto;
  END IF;
END
$$;

-- Enum types, created once. Column definitions reference them with create_type=False.
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'assertion_class' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.assertion_class AS ENUM
      ('fact', 'derived_metric', 'estimate', 'hypothesis', 'forecast');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'measures' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.measures AS ENUM ('direct', 'derived', 'inferred');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'source_kind' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.source_kind AS ENUM
      ('api', 'official_stat', 'web_page', 'licensed_feed', 'manual', 'file_upload');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'access_policy' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.access_policy AS ENUM
      ('official_api', 'licensed', 'public_page', 'manual_export', 'authorised', 'pending_review');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'robots_policy' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.robots_policy AS ENUM
      ('allowed', 'disallowed', 'unknown', 'not_applicable');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'frequency' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.frequency AS ENUM
      ('daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'hourly', 'irregular');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'cadence' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.cadence AS ENUM
      ('hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'irregular');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'aggregation' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.aggregation AS ENUM ('sum', 'mean', 'last', 'median', 'index');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'venue_category' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.venue_category AS ENUM
      ('jazz_club', 'music_club', 'concert_hall', 'theatre', 'standup', 'festival',
       'restaurant_bar');
  END IF;

  IF NOT EXISTS (SELECT 1 FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                 WHERE t.typname = 'unit_class' AND n.nspname = 'registry') THEN
    CREATE TYPE registry.unit_class AS ENUM
      ('index', 'money_nominal', 'count', 'percent', 'physical');
  END IF;
END
$$;
