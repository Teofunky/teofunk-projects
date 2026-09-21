-- Restores the observation views exactly as migration 0001 created them.
-- Lifted from 0001_epilogue.sql so a downgrade is a true reversal.

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


