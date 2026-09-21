-- Migration 0002: give every observation an explicit Moscow calendar date.
--
-- The problem this fixes
-- ----------------------
-- A daily observation is stored at the start of its period, which for this
-- product is midnight in Moscow: 2026-09-16T00:00+03:00. Sessions run with
-- TIME ZONE 'UTC' (barometer.db.session sets it deliberately, so nothing depends
-- on a server default). Casting that value to a date in a UTC session gives
-- 2026-09-15, because the instant is 21:00 on the previous UTC day.
--
-- Every daily GROUP BY, every join from norm.observation to feature.as_of_date
-- and model.as_of_date, and every chart axis would therefore be silently off by
-- one day for every series. An end-to-end test caught it; a query written later
-- by someone who forgot would not have been caught at all.
--
-- Why a trigger and not a generated column
-- ----------------------------------------
-- `timestamptz AT TIME ZONE 'Europe/Moscow'` is STABLE, not IMMUTABLE, because
-- the timezone database can change. PostgreSQL therefore refuses it in both a
-- GENERATED column and a CHECK constraint. A BEFORE INSERT trigger can call it,
-- works on TimescaleDB hypertables, and still guarantees the column is derived
-- by the database rather than supplied by a caller who might get it wrong.

ALTER TABLE norm.observation
  ADD COLUMN IF NOT EXISTS observed_date date;

ALTER TABLE norm.event_observation
  ADD COLUMN IF NOT EXISTS observed_date date;

COMMENT ON COLUMN norm.observation.observed_date IS
  'Moscow calendar date of observed_at. Set by trigger; the join key for daily data.';
COMMENT ON COLUMN norm.event_observation.observed_date IS
  'Moscow calendar date of observed_at. Set by trigger.';


CREATE OR REPLACE FUNCTION norm.set_observed_date() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  NEW.observed_date := (NEW.observed_at AT TIME ZONE 'Europe/Moscow')::date;
  RETURN NEW;
END;
$$;

COMMENT ON FUNCTION norm.set_observed_date() IS
  'Derives the Moscow calendar date so no caller can supply a wrong one.';

CREATE TRIGGER trg_observation_observed_date
  BEFORE INSERT ON norm.observation
  FOR EACH ROW EXECUTE FUNCTION norm.set_observed_date();

CREATE TRIGGER trg_event_observation_observed_date
  BEFORE INSERT ON norm.event_observation
  FOR EACH ROW EXECUTE FUNCTION norm.set_observed_date();

-- Backfill anything written before this migration.
UPDATE norm.observation
   SET observed_date = (observed_at AT TIME ZONE 'Europe/Moscow')::date
 WHERE observed_date IS NULL;
UPDATE norm.event_observation
   SET observed_date = (observed_at AT TIME ZONE 'Europe/Moscow')::date
 WHERE observed_date IS NULL;

ALTER TABLE norm.observation      ALTER COLUMN observed_date SET NOT NULL;
ALTER TABLE norm.event_observation ALTER COLUMN observed_date SET NOT NULL;

CREATE INDEX IF NOT EXISTS ix_observation_series_date
  ON norm.observation (series_id, observed_date);
CREATE INDEX IF NOT EXISTS ix_event_obs_venue_date
  ON norm.event_observation (venue_id, observed_date);


-- Views freeze their column list at creation, so they must be rebuilt to expose
-- the new column. The point-in-time functions are rebuilt alongside them so the
-- whole vintage surface changes in one step.
DROP VIEW IF EXISTS norm.observation_provenance;
DROP VIEW IF EXISTS norm.observation_current;

CREATE VIEW norm.observation_current AS
SELECT DISTINCT ON (series_id, observed_at) *
FROM norm.observation
ORDER BY series_id, observed_at, vintage_at DESC;

COMMENT ON VIEW norm.observation_current IS
  'Latest vintage per series and period. For display. NEVER for backtests.';

CREATE OR REPLACE FUNCTION norm.observation_as_of(p_as_of timestamptz)
RETURNS SETOF norm.observation
LANGUAGE sql STABLE AS $$
  SELECT DISTINCT ON (series_id, observed_at) *
  FROM norm.observation
  WHERE vintage_at <= p_as_of
    AND COALESCE(published_at, retrieved_at) <= p_as_of
  ORDER BY series_id, observed_at, vintage_at DESC;
$$;

COMMENT ON FUNCTION norm.observation_as_of(timestamptz) IS
  'Point-in-time panel. Backtests read through this function only (principle 8).';

CREATE VIEW norm.observation_provenance AS
SELECT
  o.series_id,
  s.code                    AS series_code,
  s.name_ru                 AS series_name_ru,
  s.unit,
  s.measures,
  o.observed_at,
  o.observed_date,
  o.period,
  o.period_end,
  o.value,
  o.missing_reason,
  o.assertion_class,
  o.quality_flags,
  o.vintage_at,
  o.retrieved_at,
  o.published_at,
  COALESCE(o.published_at, o.retrieved_at) AS available_at,
  src.code                  AS source_code,
  src.name_ru               AS source_name_ru,
  src.publisher,
  src.is_proxy,
  src.proxy_for,
  src.license,
  src.terms_url,
  src.methodology_notes     AS source_methodology_notes,
  o.methodology_notes       AS observation_methodology_notes,
  sn.id                     AS snapshot_id,
  sn.request_url,
  sn.request_params,
  sn.content_hash,
  sn.storage_uri,
  sn.legal_basis,
  sn.fetched_at
FROM norm.observation o
JOIN registry.series  s   ON s.id = o.series_id
JOIN registry.source  src ON src.id = s.source_id
JOIN raw.snapshot     sn  ON sn.id = o.snapshot_id;

COMMENT ON VIEW norm.observation_provenance IS
  'Every published number joined to its evidence. Backs the UI drill-down.';
