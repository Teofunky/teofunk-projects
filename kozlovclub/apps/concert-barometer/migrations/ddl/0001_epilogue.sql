-- Epilogue for migration 0001.
--
-- The non-negotiable principles are enforced here, in the database, so that a
-- future bug, a hurried script or an over-eager agent cannot quietly violate
-- them. Application-level checks are a convenience; these are the guarantee.

-- ===========================================================================
-- 1. APPEND-ONLY TABLES  (principles 4 and 7)
-- ===========================================================================

CREATE OR REPLACE FUNCTION ops.reject_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION
    '%.% is append-only: % rejected. %',
    TG_TABLE_SCHEMA, TG_TABLE_NAME, TG_OP, COALESCE(TG_ARGV[0], '')
    USING ERRCODE = '23514',
          HINT = 'Record a correction as a new row with a later vintage_at.';
END;
$$;

COMMENT ON FUNCTION ops.reject_mutation() IS
  'Blocks UPDATE/DELETE on evidence and vintaged tables. See ADR 0005.';

CREATE TRIGGER trg_snapshot_append_only
  BEFORE UPDATE OR DELETE ON raw.snapshot
  FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation(
    'Raw evidence is never rewritten.');

CREATE TRIGGER trg_observation_append_only
  BEFORE UPDATE OR DELETE ON norm.observation
  FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation(
    'A revised value is a new row with a later vintage_at.');

CREATE TRIGGER trg_event_observation_append_only
  BEFORE UPDATE OR DELETE ON norm.event_observation
  FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation(
    'Each snapshot of a listing is its own row.');

CREATE TRIGGER trg_event_diff_append_only
  BEFORE UPDATE OR DELETE ON norm.event_diff
  FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation(
    'A diff describes a moment that has already passed.');

CREATE TRIGGER trg_information_attention_append_only
  BEFORE UPDATE OR DELETE ON norm.information_attention
  FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation(
    'Attention measured on a date is not revised afterwards.');

CREATE TRIGGER trg_feature_value_append_only
  BEFORE UPDATE OR DELETE ON feature.feature_value
  FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation(
    'Recompute writes a new vintage; it does not overwrite one.');

CREATE TRIGGER trg_index_value_append_only
  BEFORE UPDATE OR DELETE ON model.index_value
  FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation(
    'A restated index reading is a new vintage.');

-- Principle 7, the one that makes the accuracy page trustworthy.
CREATE TRIGGER trg_forecast_append_only
  BEFORE UPDATE OR DELETE ON model.forecast
  FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation(
    'Historical forecast vintages are never overwritten.');


-- ===========================================================================
-- 2. AN INFERENCE IS NEVER A FACT  (principles 3 and 5)
--    Cross-table rule: the claim an observation makes must be permitted by
--    what its series actually measures.
-- ===========================================================================

CREATE OR REPLACE FUNCTION norm.enforce_assertion_class() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_measures registry.measures;
  v_code     text;
BEGIN
  SELECT s.measures, s.code INTO v_measures, v_code
  FROM registry.series s WHERE s.id = NEW.series_id;

  IF v_measures IS NULL THEN
    RAISE EXCEPTION 'observation references unknown series %', NEW.series_id
      USING ERRCODE = '23503';
  END IF;

  IF v_measures = 'inferred'
     AND NEW.assertion_class IN ('fact', 'derived_metric') THEN
    RAISE EXCEPTION
      'series % measures an inference; it may not claim ''%''',
      v_code, NEW.assertion_class
      USING ERRCODE = '23514',
            HINT = 'Inferred series may claim estimate, hypothesis or forecast.';
  END IF;

  IF v_measures = 'derived' AND NEW.assertion_class = 'fact' THEN
    RAISE EXCEPTION
      'series % is a derived quantity; it may not claim ''fact''', v_code
      USING ERRCODE = '23514';
  END IF;

  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_observation_assertion_class
  BEFORE INSERT ON norm.observation
  FOR EACH ROW EXECUTE FUNCTION norm.enforce_assertion_class();


-- ===========================================================================
-- 3. PROXY SOURCES CANNOT PRODUCE FACTS ABOUT TRANSACTIONS  (principle 5)
-- ===========================================================================

CREATE OR REPLACE FUNCTION norm.enforce_proxy_ceiling() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  v_is_proxy  boolean;
  v_measures  registry.measures;
  v_code      text;
BEGIN
  SELECT src.is_proxy, s.measures, s.code
    INTO v_is_proxy, v_measures, v_code
  FROM registry.series s
  JOIN registry.source src ON src.id = s.source_id
  WHERE s.id = NEW.series_id;

  IF v_is_proxy AND v_measures = 'inferred' AND NEW.assertion_class = 'fact' THEN
    RAISE EXCEPTION
      'series % infers from a proxy source and can never be a fact', v_code
      USING ERRCODE = '23514';
  END IF;

  RETURN NEW;
END;
$$;

CREATE TRIGGER trg_observation_proxy_ceiling
  BEFORE INSERT ON norm.observation
  FOR EACH ROW EXECUTE FUNCTION norm.enforce_proxy_ceiling();


-- ===========================================================================
-- 4. VINTAGE VIEWS, "what do we know now" and "what did we know then"
-- ===========================================================================

CREATE VIEW norm.observation_current AS
SELECT DISTINCT ON (series_id, observed_at) *
FROM norm.observation
ORDER BY series_id, observed_at, vintage_at DESC;

COMMENT ON VIEW norm.observation_current IS
  'Latest vintage per series and period. For display. NEVER for backtests.';

-- The anti-look-ahead workhorse: the panel exactly as it stood at p_as_of.
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

CREATE OR REPLACE FUNCTION feature.feature_as_of(p_as_of timestamptz)
RETURNS SETOF feature.feature_value
LANGUAGE sql STABLE AS $$
  SELECT DISTINCT ON (feature_code, as_of_date) *
  FROM feature.feature_value
  WHERE vintage_at <= p_as_of
    AND max_input_available_at <= p_as_of
  ORDER BY feature_code, as_of_date, vintage_at DESC;
$$;

CREATE VIEW model.index_value_current AS
SELECT DISTINCT ON (index_code, as_of_date) *
FROM model.index_value
ORDER BY index_code, as_of_date, vintage_at DESC;

CREATE VIEW model.forecast_latest AS
SELECT DISTINCT ON (target_code, target_date, interval_level) *
FROM model.forecast
WHERE NOT is_backtest
ORDER BY target_code, target_date, interval_level, forecast_created_at DESC;

COMMENT ON VIEW model.forecast_latest IS
  'Most recent live forecast per target. Older vintages remain in model.forecast.';


-- ===========================================================================
-- 5. PROVENANCE DRILL-DOWN, one view the UI and the auditor both use
-- ===========================================================================

CREATE VIEW norm.observation_provenance AS
SELECT
  o.series_id,
  s.code                    AS series_code,
  s.name_ru                 AS series_name_ru,
  s.unit,
  s.measures,
  o.observed_at,
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


-- ===========================================================================
-- 6. FRESHNESS, what the dashboard shows on the data-freshness page
-- ===========================================================================

CREATE VIEW ops.source_freshness AS
SELECT
  src.code                       AS source_code,
  src.name_ru,
  src.enabled,
  src.is_proxy,
  src.update_cadence,
  src.typical_lag_days,
  h.status,
  h.last_success_at,
  h.last_observation_at,
  EXTRACT(EPOCH FROM (now() - h.last_success_at)) / 3600.0 AS hours_since_success,
  h.expected_freshness_hours,
  CASE
    WHEN h.last_success_at IS NULL THEN 'never_run'
    WHEN h.expected_freshness_hours IS NULL THEN 'unknown'
    WHEN EXTRACT(EPOCH FROM (now() - h.last_success_at)) / 3600.0
         > h.expected_freshness_hours THEN 'stale'
    ELSE 'fresh'
  END                            AS freshness_verdict,
  h.consecutive_failures,
  h.last_error
FROM registry.source src
LEFT JOIN ops.source_health h ON h.source_id = src.id;


-- ===========================================================================
-- 7. ROLE SEPARATION, principle 1, enforced by privilege
--    An agent can read everything and write evidence and observations.
--    Only the quant pipeline may write the model layer.
-- ===========================================================================

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'barometer_agent') THEN
    CREATE ROLE barometer_agent NOLOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'barometer_quant') THEN
    CREATE ROLE barometer_quant NOLOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'barometer_readonly') THEN
    CREATE ROLE barometer_readonly NOLOGIN;
  END IF;
END
$$;

GRANT USAGE ON SCHEMA registry, raw, norm, feature, model, ops
  TO barometer_agent, barometer_quant, barometer_readonly;

GRANT SELECT ON ALL TABLES IN SCHEMA registry, raw, norm, feature, model, ops
  TO barometer_agent, barometer_quant, barometer_readonly;

-- Agents write evidence, observations and quality verdicts. Nothing else.
GRANT INSERT ON
  raw.snapshot,
  raw.snapshot_parse_error,
  norm.observation,
  norm.event_observation,
  norm.event_diff,
  norm.information_attention,
  ops.data_quality_check,
  ops.agent_invocation,
  ops.pipeline_run
  TO barometer_agent;
GRANT UPDATE ON ops.pipeline_run, ops.source_health TO barometer_agent;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA raw, norm, ops TO barometer_agent;

-- The hard boundary: no agent may write a modelled number.
REVOKE INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA model FROM barometer_agent;
REVOKE INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA feature FROM barometer_agent;

GRANT INSERT ON ALL TABLES IN SCHEMA feature, model TO barometer_quant;
GRANT INSERT, UPDATE ON ops.pipeline_run TO barometer_quant;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA feature, model, ops TO barometer_quant;

COMMENT ON SCHEMA model IS
  'Model output. barometer_agent has SELECT only: principle 1 is a privilege, '
  'not a promise.';


-- ===========================================================================
-- 8. TIME PARTITIONING, TimescaleDB where available, BRIN where not (ADR 0008)
-- ===========================================================================

DO $$
DECLARE
  v_has_timescale boolean;
BEGIN
  SELECT EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'timescaledb')
    INTO v_has_timescale;

  IF v_has_timescale THEN
    CREATE EXTENSION IF NOT EXISTS timescaledb;

    PERFORM create_hypertable('norm.observation', 'observed_at',
      chunk_time_interval => INTERVAL '90 days', if_not_exists => TRUE,
      migrate_data => TRUE);
    PERFORM create_hypertable('norm.event_observation', 'observed_at',
      chunk_time_interval => INTERVAL '30 days', if_not_exists => TRUE,
      migrate_data => TRUE);
    PERFORM create_hypertable('norm.event_diff', 'detected_at',
      chunk_time_interval => INTERVAL '90 days', if_not_exists => TRUE,
      migrate_data => TRUE);
    PERFORM create_hypertable('feature.feature_value', 'as_of_date',
      chunk_time_interval => INTERVAL '180 days', if_not_exists => TRUE,
      migrate_data => TRUE);
    PERFORM create_hypertable('model.index_value', 'as_of_date',
      chunk_time_interval => INTERVAL '365 days', if_not_exists => TRUE,
      migrate_data => TRUE);
    PERFORM create_hypertable('model.forecast', 'forecast_created_at',
      chunk_time_interval => INTERVAL '90 days', if_not_exists => TRUE,
      migrate_data => TRUE);

    RAISE NOTICE 'TimescaleDB present: hypertables created.';
  ELSE
    -- Plain PostgreSQL: BRIN indexes give most of the range-scan benefit on
    -- append-only, time-ordered tables at a fraction of the size.
    CREATE INDEX IF NOT EXISTS brin_observation_observed_at
      ON norm.observation USING brin (observed_at) WITH (pages_per_range = 32);
    CREATE INDEX IF NOT EXISTS brin_event_observation_observed_at
      ON norm.event_observation USING brin (observed_at) WITH (pages_per_range = 32);
    CREATE INDEX IF NOT EXISTS brin_event_diff_detected_at
      ON norm.event_diff USING brin (detected_at) WITH (pages_per_range = 32);
    CREATE INDEX IF NOT EXISTS brin_feature_value_as_of
      ON feature.feature_value USING brin (as_of_date) WITH (pages_per_range = 32);
    CREATE INDEX IF NOT EXISTS brin_forecast_created
      ON model.forecast USING brin (forecast_created_at) WITH (pages_per_range = 32);

    RAISE NOTICE 'TimescaleDB not available: BRIN indexes created instead.';
  END IF;
END
$$;
