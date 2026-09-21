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

--
-- PostgreSQL database dump
--

\restrict cRZPMVqMbdU66Nfyb3JvSHSZxbj5hbjTlVUdqjMdNRDNfpiqKd7aIAQVkX8wPAX

-- Dumped from database version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)
-- Dumped by pg_dump version 16.13 (Ubuntu 16.13-0ubuntu0.24.04.1)

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: feature; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA feature;


--
-- Name: SCHEMA feature; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA feature IS 'Model-ready columns carrying the vintage and availability clock of their inputs.';


--
-- Name: model; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA model;


--
-- Name: SCHEMA model; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA model IS 'Model output. barometer_agent has SELECT only: principle 1 is a privilege, not a promise.';


--
-- Name: norm; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA norm;


--
-- Name: SCHEMA norm; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA norm IS 'Observations in canonical units, vintaged. Append-only; a revision is a new row.';


--
-- Name: ops; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA ops;


--
-- Name: SCHEMA ops; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA ops IS 'Pipeline runs, source health, data-quality verdicts, agent audit trail.';


--
-- Name: raw; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA raw;


--
-- Name: SCHEMA raw; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA raw IS 'Immutable evidence. Append-only. Every number downstream traces to a row here.';


--
-- Name: registry; Type: SCHEMA; Schema: -; Owner: -
--

CREATE SCHEMA registry;


--
-- Name: SCHEMA registry; Type: COMMENT; Schema: -; Owner: -
--

COMMENT ON SCHEMA registry IS 'What we may observe and what a value means. Projection of config/*.yaml.';


--
-- Name: access_policy; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.access_policy AS ENUM (
    'official_api',
    'licensed',
    'public_page',
    'manual_export',
    'authorised',
    'pending_review'
);


--
-- Name: aggregation; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.aggregation AS ENUM (
    'sum',
    'mean',
    'last',
    'median',
    'index'
);


--
-- Name: assertion_class; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.assertion_class AS ENUM (
    'fact',
    'derived_metric',
    'estimate',
    'hypothesis',
    'forecast'
);


--
-- Name: cadence; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.cadence AS ENUM (
    'hourly',
    'daily',
    'weekly',
    'monthly',
    'quarterly',
    'yearly',
    'irregular'
);


--
-- Name: frequency; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.frequency AS ENUM (
    'daily',
    'weekly',
    'monthly',
    'quarterly',
    'yearly',
    'hourly',
    'irregular'
);


--
-- Name: measures; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.measures AS ENUM (
    'direct',
    'derived',
    'inferred'
);


--
-- Name: robots_policy; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.robots_policy AS ENUM (
    'allowed',
    'disallowed',
    'unknown',
    'not_applicable'
);


--
-- Name: source_kind; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.source_kind AS ENUM (
    'api',
    'official_stat',
    'web_page',
    'licensed_feed',
    'manual',
    'file_upload'
);


--
-- Name: unit_class; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.unit_class AS ENUM (
    'index',
    'money_nominal',
    'count',
    'percent',
    'physical'
);


--
-- Name: venue_category; Type: TYPE; Schema: registry; Owner: -
--

CREATE TYPE registry.venue_category AS ENUM (
    'jazz_club',
    'music_club',
    'concert_hall',
    'theatre',
    'standup',
    'festival',
    'restaurant_bar'
);


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: feature_value; Type: TABLE; Schema: feature; Owner: -
--

CREATE TABLE feature.feature_value (
    as_of_date date NOT NULL,
    feature_code character varying(160) NOT NULL,
    vintage_at timestamp with time zone NOT NULL,
    value double precision,
    missing_reason character varying(60),
    feature_set_version character varying(40) NOT NULL,
    max_input_available_at timestamp with time zone NOT NULL,
    computed_from jsonb DEFAULT '{}'::jsonb NOT NULL,
    quality_flags text[] DEFAULT '{}'::text[] NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_feature_value_absent_feature_has_a_reason CHECK (((value IS NOT NULL) OR (missing_reason IS NOT NULL))),
    CONSTRAINT ck_feature_value_feature_cannot_precede_its_inputs CHECK ((max_input_available_at <= vintage_at))
);


--
-- Name: feature_as_of(timestamp with time zone); Type: FUNCTION; Schema: feature; Owner: -
--

CREATE FUNCTION feature.feature_as_of(p_as_of timestamp with time zone) RETURNS SETOF feature.feature_value
    LANGUAGE sql STABLE
    AS $$
  SELECT DISTINCT ON (feature_code, as_of_date) *
  FROM feature.feature_value
  WHERE vintage_at <= p_as_of
    AND max_input_available_at <= p_as_of
  ORDER BY feature_code, as_of_date, vintage_at DESC;
$$;


--
-- Name: enforce_assertion_class(); Type: FUNCTION; Schema: norm; Owner: -
--

CREATE FUNCTION norm.enforce_assertion_class() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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


--
-- Name: enforce_proxy_ceiling(); Type: FUNCTION; Schema: norm; Owner: -
--

CREATE FUNCTION norm.enforce_proxy_ceiling() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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


--
-- Name: observation; Type: TABLE; Schema: norm; Owner: -
--

CREATE TABLE norm.observation (
    observed_at timestamp with time zone NOT NULL,
    series_id uuid NOT NULL,
    vintage_at timestamp with time zone NOT NULL,
    period character varying(10) NOT NULL,
    period_end timestamp with time zone NOT NULL,
    value double precision,
    missing_reason character varying(60),
    unit character varying(60) NOT NULL,
    geography character varying(16) NOT NULL,
    assertion_class registry.assertion_class NOT NULL,
    snapshot_id uuid NOT NULL,
    retrieved_at timestamp with time zone NOT NULL,
    published_at timestamp with time zone,
    quality_flags text[] DEFAULT '{}'::text[] NOT NULL,
    methodology_notes text,
    transform character varying(80),
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    observed_date date NOT NULL,
    CONSTRAINT ck_observation_absent_value_has_a_reason CHECK (((value IS NOT NULL) OR (missing_reason IS NOT NULL))),
    CONSTRAINT ck_observation_period_is_known CHECK (((period)::text = ANY ((ARRAY['hour'::character varying, 'day'::character varying, 'week'::character varying, 'month'::character varying, 'quarter'::character varying, 'year'::character varying, 'point'::character varying])::text[]))),
    CONSTRAINT ck_observation_period_is_positive_length CHECK ((period_end > observed_at)),
    CONSTRAINT ck_observation_vintage_is_not_before_retrieval CHECK ((retrieved_at <= vintage_at))
);


--
-- Name: COLUMN observation.observed_date; Type: COMMENT; Schema: norm; Owner: -
--

COMMENT ON COLUMN norm.observation.observed_date IS 'Moscow calendar date of observed_at. Set by trigger; the join key for daily data.';


--
-- Name: observation_as_of(timestamp with time zone); Type: FUNCTION; Schema: norm; Owner: -
--

CREATE FUNCTION norm.observation_as_of(p_as_of timestamp with time zone) RETURNS SETOF norm.observation
    LANGUAGE sql STABLE
    AS $$
  SELECT DISTINCT ON (series_id, observed_at) *
  FROM norm.observation
  WHERE vintage_at <= p_as_of
    AND COALESCE(published_at, retrieved_at) <= p_as_of
  ORDER BY series_id, observed_at, vintage_at DESC;
$$;


--
-- Name: FUNCTION observation_as_of(p_as_of timestamp with time zone); Type: COMMENT; Schema: norm; Owner: -
--

COMMENT ON FUNCTION norm.observation_as_of(p_as_of timestamp with time zone) IS 'Point-in-time panel. Backtests read through this function only (principle 8).';


--
-- Name: set_observed_date(); Type: FUNCTION; Schema: norm; Owner: -
--

CREATE FUNCTION norm.set_observed_date() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  NEW.observed_date := (NEW.observed_at AT TIME ZONE 'Europe/Moscow')::date;
  RETURN NEW;
END;
$$;


--
-- Name: FUNCTION set_observed_date(); Type: COMMENT; Schema: norm; Owner: -
--

COMMENT ON FUNCTION norm.set_observed_date() IS 'Derives the Moscow calendar date so no caller can supply a wrong one.';


--
-- Name: reject_mutation(); Type: FUNCTION; Schema: ops; Owner: -
--

CREATE FUNCTION ops.reject_mutation() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
  RAISE EXCEPTION
    '%.% is append-only: % rejected. %',
    TG_TABLE_SCHEMA, TG_TABLE_NAME, TG_OP, COALESCE(TG_ARGV[0], '')
    USING ERRCODE = '23514',
          HINT = 'Record a correction as a new row with a later vintage_at.';
END;
$$;


--
-- Name: FUNCTION reject_mutation(); Type: COMMENT; Schema: ops; Owner: -
--

COMMENT ON FUNCTION ops.reject_mutation() IS 'Blocks UPDATE/DELETE on evidence and vintaged tables. See ADR 0005.';


--
-- Name: feature_definition; Type: TABLE; Schema: feature; Owner: -
--

CREATE TABLE feature.feature_definition (
    feature_code character varying(160) NOT NULL,
    name_ru text NOT NULL,
    family character varying(40) NOT NULL,
    description_ru text NOT NULL,
    transform character varying(120) NOT NULL,
    input_series text[] DEFAULT '{}'::text[] NOT NULL,
    calendar_exempt boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_feature_definition_only_calendar_features_are_lag_exempt CHECK (((calendar_exempt IS FALSE) OR ((family)::text = 'calendar'::text)))
);


--
-- Name: backtest; Type: TABLE; Schema: model; Owner: -
--

CREATE TABLE model.backtest (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    model_run_id uuid NOT NULL,
    scheme character varying(40) NOT NULL,
    folds integer NOT NULL,
    initial_train_days integer NOT NULL,
    step_days integer NOT NULL,
    horizon_days smallint NOT NULL,
    mae double precision,
    rmse double precision,
    mase_vs_seasonal_naive double precision,
    interval_level double precision,
    interval_coverage double precision,
    per_fold jsonb DEFAULT '{}'::jsonb NOT NULL,
    finished_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_backtest_backtest_has_folds CHECK ((folds >= 1)),
    CONSTRAINT ck_backtest_coverage_is_a_fraction CHECK (((interval_coverage IS NULL) OR ((interval_coverage >= (0)::double precision) AND (interval_coverage <= (1)::double precision))))
);


--
-- Name: forecast; Type: TABLE; Schema: model; Owner: -
--

CREATE TABLE model.forecast (
    forecast_created_at timestamp with time zone NOT NULL,
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    model_run_id uuid NOT NULL,
    target_code character varying(160) NOT NULL,
    target_date date NOT NULL,
    horizon_days smallint NOT NULL,
    quantity character varying(24) DEFAULT 'index'::character varying NOT NULL,
    point_forecast double precision,
    lower_bound double precision,
    upper_bound double precision,
    interval_level double precision NOT NULL,
    model_version character varying(40) NOT NULL,
    feature_vintage timestamp with time zone NOT NULL,
    feature_set_version character varying(40) NOT NULL,
    calibration_run_id uuid,
    is_backtest boolean DEFAULT false NOT NULL,
    backtest_fold integer,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_forecast_horizon_is_positive CHECK ((horizon_days > 0)),
    CONSTRAINT ck_forecast_interval_level_is_a_fraction CHECK (((interval_level > (0)::double precision) AND (interval_level < (1)::double precision))),
    CONSTRAINT ck_forecast_interval_ordered CHECK (((lower_bound IS NULL) OR (upper_bound IS NULL) OR (lower_bound <= upper_bound))),
    CONSTRAINT ck_forecast_no_look_ahead_in_features CHECK ((feature_vintage <= forecast_created_at)),
    CONSTRAINT ck_forecast_point_inside_interval CHECK (((point_forecast IS NULL) OR (lower_bound IS NULL) OR ((point_forecast >= lower_bound) AND (point_forecast <= upper_bound)))),
    CONSTRAINT ck_forecast_quantity_is_known CHECK (((quantity)::text = ANY ((ARRAY['index'::character varying, 'demand_estimate'::character varying, 'market_sales'::character varying])::text[]))),
    CONSTRAINT ck_forecast_sales_forecast_requires_calibration CHECK ((((quantity)::text <> 'market_sales'::text) OR (calibration_run_id IS NOT NULL))),
    CONSTRAINT ck_forecast_target_is_not_in_the_past CHECK ((target_date >= ((forecast_created_at AT TIME ZONE 'UTC'::text))::date))
);


--
-- Name: forecast_evaluation; Type: TABLE; Schema: model; Owner: -
--

CREATE TABLE model.forecast_evaluation (
    evaluated_at timestamp with time zone NOT NULL,
    forecast_id uuid NOT NULL,
    forecast_created_at timestamp with time zone NOT NULL,
    target_code character varying(160) NOT NULL,
    target_date date NOT NULL,
    horizon_days smallint NOT NULL,
    realised_value double precision NOT NULL,
    realised_vintage_at timestamp with time zone NOT NULL,
    error double precision NOT NULL,
    absolute_error double precision NOT NULL,
    squared_error double precision NOT NULL,
    inside_interval boolean,
    benchmark_absolute_error double precision,
    skill_vs_benchmark double precision,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_forecast_evaluation_scored_only_after_the_target_per_7aa8 CHECK ((realised_vintage_at >= (target_date + '1 day'::interval)))
);


--
-- Name: forecast_latest; Type: VIEW; Schema: model; Owner: -
--

CREATE VIEW model.forecast_latest AS
 SELECT DISTINCT ON (target_code, target_date, interval_level) forecast_created_at,
    id,
    model_run_id,
    target_code,
    target_date,
    horizon_days,
    quantity,
    point_forecast,
    lower_bound,
    upper_bound,
    interval_level,
    model_version,
    feature_vintage,
    feature_set_version,
    calibration_run_id,
    is_backtest,
    backtest_fold,
    created_at
   FROM model.forecast
  WHERE (NOT is_backtest)
  ORDER BY target_code, target_date, interval_level, forecast_created_at DESC;


--
-- Name: VIEW forecast_latest; Type: COMMENT; Schema: model; Owner: -
--

COMMENT ON VIEW model.forecast_latest IS 'Most recent live forecast per target. Older vintages remain in model.forecast.';


--
-- Name: index_value; Type: TABLE; Schema: model; Owner: -
--

CREATE TABLE model.index_value (
    as_of_date date NOT NULL,
    index_code character varying(80) NOT NULL,
    vintage_at timestamp with time zone NOT NULL,
    value double precision,
    insufficient_data_reason text,
    seasonally_adjusted boolean DEFAULT true NOT NULL,
    seasonal_baseline double precision,
    deviation_from_seasonal double precision,
    wow_change double precision,
    yoy_index_change double precision,
    confidence double precision,
    clipped boolean DEFAULT false NOT NULL,
    contributions jsonb DEFAULT '{}'::jsonb NOT NULL,
    coverage jsonb DEFAULT '{}'::jsonb NOT NULL,
    model_run_id uuid NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_index_value_absent_index_has_a_reason CHECK (((value IS NOT NULL) OR (insufficient_data_reason IS NOT NULL))),
    CONSTRAINT ck_index_value_confidence_is_a_fraction CHECK (((confidence >= (0)::double precision) AND (confidence <= (1)::double precision)))
);


--
-- Name: index_value_current; Type: VIEW; Schema: model; Owner: -
--

CREATE VIEW model.index_value_current AS
 SELECT DISTINCT ON (index_code, as_of_date) as_of_date,
    index_code,
    vintage_at,
    value,
    insufficient_data_reason,
    seasonally_adjusted,
    seasonal_baseline,
    deviation_from_seasonal,
    wow_change,
    yoy_index_change,
    confidence,
    clipped,
    contributions,
    coverage,
    model_run_id,
    created_at
   FROM model.index_value
  ORDER BY index_code, as_of_date, vintage_at DESC;


--
-- Name: model_run; Type: TABLE; Schema: model; Owner: -
--

CREATE TABLE model.model_run (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    model_name character varying(80) NOT NULL,
    model_version character varying(40) NOT NULL,
    role character varying(20) NOT NULL,
    code_git_sha character varying(64),
    feature_set_version character varying(40) NOT NULL,
    feature_vintage timestamp with time zone NOT NULL,
    trained_at timestamp with time zone NOT NULL,
    training_window_start date NOT NULL,
    training_window_end date NOT NULL,
    params jsonb DEFAULT '{}'::jsonb NOT NULL,
    metrics jsonb DEFAULT '{}'::jsonb NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_model_run_cannot_train_on_future_features CHECK ((feature_vintage <= trained_at)),
    CONSTRAINT ck_model_run_role_is_known CHECK (((role)::text = ANY ((ARRAY['benchmark'::character varying, 'candidate'::character varying, 'production'::character varying, 'experiment'::character varying])::text[]))),
    CONSTRAINT ck_model_run_training_window_ordered CHECK ((training_window_end >= training_window_start))
);


--
-- Name: event_diff; Type: TABLE; Schema: norm; Owner: -
--

CREATE TABLE norm.event_diff (
    detected_at timestamp with time zone NOT NULL,
    id bigint NOT NULL,
    venue_id uuid NOT NULL,
    event_key character varying(200) NOT NULL,
    change_type character varying(40) NOT NULL,
    snapshot_before_id uuid,
    snapshot_after_id uuid NOT NULL,
    delta jsonb DEFAULT '{}'::jsonb NOT NULL,
    ambiguity_note text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_event_diff_change_type_is_known CHECK (((change_type)::text = ANY ((ARRAY['listed'::character varying, 'delisted'::character varying, 'price_up'::character varying, 'price_down'::character varying, 'availability_changed'::character varying, 'rescheduled'::character varying, 'stage_changed'::character varying])::text[])))
);


--
-- Name: event_diff_id_seq; Type: SEQUENCE; Schema: norm; Owner: -
--

CREATE SEQUENCE norm.event_diff_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: event_diff_id_seq; Type: SEQUENCE OWNED BY; Schema: norm; Owner: -
--

ALTER SEQUENCE norm.event_diff_id_seq OWNED BY norm.event_diff.id;


--
-- Name: event_observation; Type: TABLE; Schema: norm; Owner: -
--

CREATE TABLE norm.event_observation (
    observed_at timestamp with time zone NOT NULL,
    id bigint NOT NULL,
    venue_id uuid NOT NULL,
    snapshot_id uuid NOT NULL,
    event_key character varying(200) NOT NULL,
    external_id character varying(200),
    title text NOT NULL,
    starts_at timestamp with time zone,
    stage character varying(120),
    ticket_provider character varying(80),
    ticket_url text,
    price_min numeric(12,2),
    price_max numeric(12,2),
    currency character varying(3) DEFAULT 'RUB'::character varying NOT NULL,
    availability_label character varying(120),
    categories_listed bigint,
    raw_fields jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    observed_date date NOT NULL,
    CONSTRAINT ck_event_observation_price_non_negative CHECK (((price_min IS NULL) OR (price_min >= (0)::numeric))),
    CONSTRAINT ck_event_observation_price_range_ordered CHECK (((price_min IS NULL) OR (price_max IS NULL) OR (price_max >= price_min)))
);


--
-- Name: COLUMN event_observation.observed_date; Type: COMMENT; Schema: norm; Owner: -
--

COMMENT ON COLUMN norm.event_observation.observed_date IS 'Moscow calendar date of observed_at. Set by trigger.';


--
-- Name: event_observation_id_seq; Type: SEQUENCE; Schema: norm; Owner: -
--

CREATE SEQUENCE norm.event_observation_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: event_observation_id_seq; Type: SEQUENCE OWNED BY; Schema: norm; Owner: -
--

ALTER SEQUENCE norm.event_observation_id_seq OWNED BY norm.event_observation.id;


--
-- Name: information_attention; Type: TABLE; Schema: norm; Owner: -
--

CREATE TABLE norm.information_attention (
    as_of_date date NOT NULL,
    topic_key character varying(160) NOT NULL,
    topic_label_ru text NOT NULL,
    attention_score double precision NOT NULL,
    measurement_basis character varying(120) NOT NULL,
    verification_status character varying(20) NOT NULL,
    assertion_class registry.assertion_class NOT NULL,
    snapshot_id uuid NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_information_attention_attention_is_never_a_fact_abou_e1e5 CHECK ((assertion_class = ANY (ARRAY['estimate'::registry.assertion_class, 'hypothesis'::registry.assertion_class]))),
    CONSTRAINT ck_information_attention_verification_status_is_known CHECK (((verification_status)::text = ANY ((ARRAY['unverified'::character varying, 'disputed'::character varying, 'confirmed'::character varying, 'refuted'::character varying])::text[])))
);


--
-- Name: observation_current; Type: VIEW; Schema: norm; Owner: -
--

CREATE VIEW norm.observation_current AS
 SELECT DISTINCT ON (series_id, observed_at) observed_at,
    series_id,
    vintage_at,
    period,
    period_end,
    value,
    missing_reason,
    unit,
    geography,
    assertion_class,
    snapshot_id,
    retrieved_at,
    published_at,
    quality_flags,
    methodology_notes,
    transform,
    created_at,
    observed_date
   FROM norm.observation
  ORDER BY series_id, observed_at, vintage_at DESC;


--
-- Name: VIEW observation_current; Type: COMMENT; Schema: norm; Owner: -
--

COMMENT ON VIEW norm.observation_current IS 'Latest vintage per series and period. For display. NEVER for backtests.';


--
-- Name: snapshot; Type: TABLE; Schema: raw; Owner: -
--

CREATE TABLE raw.snapshot (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    source_id uuid NOT NULL,
    venue_id uuid,
    idempotency_key character varying(200) NOT NULL,
    fetched_at timestamp with time zone NOT NULL,
    request_url text,
    request_method character varying(10) DEFAULT 'GET'::character varying NOT NULL,
    request_params jsonb DEFAULT '{}'::jsonb NOT NULL,
    http_status smallint,
    content_type character varying(120),
    content_hash character varying(64) NOT NULL,
    content_bytes bigint,
    storage_uri text,
    content_inline text,
    retention character varying(24) DEFAULT 'retained'::character varying NOT NULL,
    legal_basis character varying(24) NOT NULL,
    observation_period_start timestamp with time zone,
    observation_period_end timestamp with time zone,
    geography character varying(16),
    collector character varying(120) NOT NULL,
    collector_version character varying(40) NOT NULL,
    run_id uuid,
    notes text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_snapshot_bytes_are_somewhere_or_declared_absent CHECK (((storage_uri IS NOT NULL) OR (content_inline IS NOT NULL) OR ((retention)::text = 'not_retained'::text))),
    CONSTRAINT ck_snapshot_content_hash_is_sha256 CHECK (((content_hash)::text ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT ck_snapshot_legal_basis_is_known CHECK (((legal_basis)::text = ANY ((ARRAY['official_api'::character varying, 'licensed'::character varying, 'public_permitted'::character varying, 'user_supplied'::character varying, 'manual_entry'::character varying])::text[]))),
    CONSTRAINT ck_snapshot_observation_period_ordered CHECK (((observation_period_start IS NULL) OR (observation_period_end IS NULL) OR (observation_period_end >= observation_period_start)))
);


--
-- Name: series; Type: TABLE; Schema: registry; Owner: -
--

CREATE TABLE registry.series (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code character varying(160) NOT NULL,
    source_id uuid NOT NULL,
    segment_code character varying(120) NOT NULL,
    name_ru text NOT NULL,
    unit character varying(60) NOT NULL,
    frequency registry.frequency NOT NULL,
    measures registry.measures NOT NULL,
    assertion_class registry.assertion_class NOT NULL,
    aggregation registry.aggregation NOT NULL,
    description_ru text,
    proxy_caveat_ru text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_series_claim_matches_what_is_measured CHECK (((measures = 'direct'::registry.measures) OR ((measures = 'derived'::registry.measures) AND (assertion_class = ANY (ARRAY['derived_metric'::registry.assertion_class, 'estimate'::registry.assertion_class, 'hypothesis'::registry.assertion_class, 'forecast'::registry.assertion_class]))) OR ((measures = 'inferred'::registry.measures) AND (assertion_class = ANY (ARRAY['estimate'::registry.assertion_class, 'hypothesis'::registry.assertion_class, 'forecast'::registry.assertion_class])))))
);


--
-- Name: source; Type: TABLE; Schema: registry; Owner: -
--

CREATE TABLE registry.source (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code character varying(120) NOT NULL,
    name text NOT NULL,
    name_ru text NOT NULL,
    kind registry.source_kind NOT NULL,
    publisher text NOT NULL,
    homepage_url text,
    endpoint_url text,
    geography character varying(16) NOT NULL,
    access_policy registry.access_policy NOT NULL,
    requires_credentials boolean DEFAULT false NOT NULL,
    credential_env_var character varying(120),
    robots_policy registry.robots_policy NOT NULL,
    robots_checked_at timestamp with time zone,
    rate_limit_per_min integer,
    update_cadence registry.cadence NOT NULL,
    typical_lag_days integer NOT NULL,
    license text,
    terms_url text,
    is_proxy boolean DEFAULT false NOT NULL,
    proxy_for text,
    methodology_notes text,
    verification_required text[] DEFAULT '{}'::text[] NOT NULL,
    enabled boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_source_credentials_are_named CHECK (((NOT requires_credentials) OR (credential_env_var IS NOT NULL))),
    CONSTRAINT ck_source_enabled_requires_resolved_robots CHECK (((NOT enabled) OR (robots_policy = ANY (ARRAY['allowed'::registry.robots_policy, 'not_applicable'::registry.robots_policy])))),
    CONSTRAINT ck_source_lag_is_non_negative CHECK ((typical_lag_days >= 0)),
    CONSTRAINT ck_source_proxy_declares_target CHECK (((NOT is_proxy) OR (proxy_for IS NOT NULL)))
);


--
-- Name: observation_provenance; Type: VIEW; Schema: norm; Owner: -
--

CREATE VIEW norm.observation_provenance AS
 SELECT o.series_id,
    s.code AS series_code,
    s.name_ru AS series_name_ru,
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
    src.code AS source_code,
    src.name_ru AS source_name_ru,
    src.publisher,
    src.is_proxy,
    src.proxy_for,
    src.license,
    src.terms_url,
    src.methodology_notes AS source_methodology_notes,
    o.methodology_notes AS observation_methodology_notes,
    sn.id AS snapshot_id,
    sn.request_url,
    sn.request_params,
    sn.content_hash,
    sn.storage_uri,
    sn.legal_basis,
    sn.fetched_at
   FROM (((norm.observation o
     JOIN registry.series s ON ((s.id = o.series_id)))
     JOIN registry.source src ON ((src.id = s.source_id)))
     JOIN raw.snapshot sn ON ((sn.id = o.snapshot_id)));


--
-- Name: VIEW observation_provenance; Type: COMMENT; Schema: norm; Owner: -
--

COMMENT ON VIEW norm.observation_provenance IS 'Every published number joined to its evidence. Backs the UI drill-down.';


--
-- Name: agent_invocation; Type: TABLE; Schema: ops; Owner: -
--

CREATE TABLE ops.agent_invocation (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    run_id uuid,
    agent_code character varying(80) NOT NULL,
    provider character varying(40) NOT NULL,
    model character varying(120),
    attempt integer DEFAULT 1 NOT NULL,
    status character varying(30) NOT NULL,
    started_at timestamp with time zone NOT NULL,
    finished_at timestamp with time zone,
    input_tokens integer,
    output_tokens integer,
    contract_violations jsonb DEFAULT '[]'::jsonb NOT NULL,
    error_message text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_agent_invocation_agent_status_is_known CHECK (((status)::text = ANY ((ARRAY['succeeded'::character varying, 'failed'::character varying, 'rejected_by_contract'::character varying, 'timed_out'::character varying])::text[])))
);


--
-- Name: data_quality_check; Type: TABLE; Schema: ops; Owner: -
--

CREATE TABLE ops.data_quality_check (
    id bigint NOT NULL,
    check_code character varying(120) NOT NULL,
    target_kind character varying(40) NOT NULL,
    target_code character varying(160) NOT NULL,
    as_of_date date NOT NULL,
    passed boolean NOT NULL,
    severity character varying(10) NOT NULL,
    blocking boolean DEFAULT false NOT NULL,
    actor character varying(120) NOT NULL,
    detail jsonb DEFAULT '{}'::jsonb NOT NULL,
    message_ru text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_data_quality_check_severity_is_known CHECK (((severity)::text = ANY ((ARRAY['info'::character varying, 'warning'::character varying, 'critical'::character varying])::text[])))
);


--
-- Name: data_quality_check_id_seq; Type: SEQUENCE; Schema: ops; Owner: -
--

CREATE SEQUENCE ops.data_quality_check_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: data_quality_check_id_seq; Type: SEQUENCE OWNED BY; Schema: ops; Owner: -
--

ALTER SEQUENCE ops.data_quality_check_id_seq OWNED BY ops.data_quality_check.id;


--
-- Name: pipeline_run; Type: TABLE; Schema: ops; Owner: -
--

CREATE TABLE ops.pipeline_run (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    pipeline character varying(80) NOT NULL,
    stage character varying(80),
    actor character varying(120) NOT NULL,
    status character varying(20) NOT NULL,
    started_at timestamp with time zone NOT NULL,
    finished_at timestamp with time zone,
    params jsonb DEFAULT '{}'::jsonb NOT NULL,
    records_written bigint DEFAULT 0 NOT NULL,
    error_type character varying(120),
    error_message text,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_pipeline_run_run_duration_non_negative CHECK (((finished_at IS NULL) OR (finished_at >= started_at))),
    CONSTRAINT ck_pipeline_run_status_is_known CHECK (((status)::text = ANY ((ARRAY['running'::character varying, 'succeeded'::character varying, 'failed'::character varying, 'degraded'::character varying, 'skipped'::character varying])::text[])))
);


--
-- Name: schema_fingerprint; Type: TABLE; Schema: ops; Owner: -
--

CREATE TABLE ops.schema_fingerprint (
    id bigint NOT NULL,
    file_name character varying(80) NOT NULL,
    content_hash character varying(64) NOT NULL,
    loaded_at timestamp with time zone DEFAULT now() NOT NULL,
    note text
);


--
-- Name: schema_fingerprint_id_seq; Type: SEQUENCE; Schema: ops; Owner: -
--

CREATE SEQUENCE ops.schema_fingerprint_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: schema_fingerprint_id_seq; Type: SEQUENCE OWNED BY; Schema: ops; Owner: -
--

ALTER SEQUENCE ops.schema_fingerprint_id_seq OWNED BY ops.schema_fingerprint.id;


--
-- Name: source_health; Type: TABLE; Schema: ops; Owner: -
--

CREATE TABLE ops.source_health (
    source_id uuid NOT NULL,
    status character varying(20) DEFAULT 'never_run'::character varying NOT NULL,
    last_attempt_at timestamp with time zone,
    last_success_at timestamp with time zone,
    last_observation_at timestamp with time zone,
    freshness_hours double precision,
    expected_freshness_hours double precision,
    consecutive_failures integer DEFAULT 0 NOT NULL,
    last_error text,
    robots_last_checked_at timestamp with time zone,
    robots_result character varying(40),
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_source_health_failures_non_negative CHECK ((consecutive_failures >= 0)),
    CONSTRAINT ck_source_health_health_status_is_known CHECK (((status)::text = ANY ((ARRAY['healthy'::character varying, 'stale'::character varying, 'failing'::character varying, 'blocked'::character varying, 'disabled'::character varying, 'never_run'::character varying])::text[])))
);


--
-- Name: source_freshness; Type: VIEW; Schema: ops; Owner: -
--

CREATE VIEW ops.source_freshness AS
 SELECT src.code AS source_code,
    src.name_ru,
    src.enabled,
    src.is_proxy,
    src.update_cadence,
    src.typical_lag_days,
    h.status,
    h.last_success_at,
    h.last_observation_at,
    (EXTRACT(epoch FROM (now() - h.last_success_at)) / 3600.0) AS hours_since_success,
    h.expected_freshness_hours,
        CASE
            WHEN (h.last_success_at IS NULL) THEN 'never_run'::text
            WHEN (h.expected_freshness_hours IS NULL) THEN 'unknown'::text
            WHEN (((EXTRACT(epoch FROM (now() - h.last_success_at)) / 3600.0))::double precision > h.expected_freshness_hours) THEN 'stale'::text
            ELSE 'fresh'::text
        END AS freshness_verdict,
    h.consecutive_failures,
    h.last_error
   FROM (registry.source src
     LEFT JOIN ops.source_health h ON ((h.source_id = src.id)));


--
-- Name: snapshot_parse_error; Type: TABLE; Schema: raw; Owner: -
--

CREATE TABLE raw.snapshot_parse_error (
    id bigint NOT NULL,
    snapshot_id uuid NOT NULL,
    stage character varying(40) NOT NULL,
    error_type character varying(120) NOT NULL,
    message text NOT NULL,
    detail jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL
);


--
-- Name: snapshot_parse_error_id_seq; Type: SEQUENCE; Schema: raw; Owner: -
--

CREATE SEQUENCE raw.snapshot_parse_error_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: snapshot_parse_error_id_seq; Type: SEQUENCE OWNED BY; Schema: raw; Owner: -
--

ALTER SEQUENCE raw.snapshot_parse_error_id_seq OWNED BY raw.snapshot_parse_error.id;


--
-- Name: segment; Type: TABLE; Schema: registry; Owner: -
--

CREATE TABLE registry.segment (
    code character varying(120) NOT NULL,
    name_ru text NOT NULL,
    kind character varying(32) NOT NULL,
    parent_code character varying(120),
    display_order integer NOT NULL,
    unit_class registry.unit_class NOT NULL,
    min_series smallint DEFAULT 1 NOT NULL,
    venue_category registry.venue_category,
    proxy_badge_ru text,
    visible_in_ui boolean DEFAULT true NOT NULL,
    CONSTRAINT ck_segment_min_series_non_negative CHECK ((min_series >= 0)),
    CONSTRAINT ck_segment_no_self_parent CHECK (((parent_code IS NULL) OR ((parent_code)::text <> (code)::text)))
);


--
-- Name: venue; Type: TABLE; Schema: registry; Owner: -
--

CREATE TABLE registry.venue (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    code character varying(120) NOT NULL,
    canonical_name text NOT NULL,
    canonical_name_ru text NOT NULL,
    category registry.venue_category NOT NULL,
    city character varying(80) DEFAULT 'Москва'::character varying NOT NULL,
    geography character varying(16) DEFAULT 'RU-MOW'::character varying NOT NULL,
    website_url text,
    url_verified boolean DEFAULT false NOT NULL,
    ticket_providers text[] DEFAULT '{}'::text[] NOT NULL,
    relationship_type character varying(24) DEFAULT 'panel'::character varying NOT NULL,
    source_access_policy registry.access_policy NOT NULL,
    robots_allows_crawl boolean,
    robots_checked_at timestamp with time zone,
    access_reviewed_at timestamp with time zone,
    capacity integer,
    capacity_source_url text,
    capacity_source_note text,
    enabled boolean DEFAULT false NOT NULL,
    notes_ru text,
    extra jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamp with time zone DEFAULT now() NOT NULL,
    updated_at timestamp with time zone DEFAULT now() NOT NULL,
    CONSTRAINT ck_venue_capacity_is_plausible CHECK (((capacity IS NULL) OR ((capacity > 0) AND (capacity <= 200000)))),
    CONSTRAINT ck_venue_capacity_only_if_sourced CHECK (((capacity IS NULL) OR (capacity_source_url IS NOT NULL))),
    CONSTRAINT ck_venue_enabled_requires_review CHECK (((NOT enabled) OR ((relationship_type)::text = 'first_party'::text) OR (url_verified AND robots_allows_crawl AND (access_reviewed_at IS NOT NULL))))
);


--
-- Name: event_diff id; Type: DEFAULT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_diff ALTER COLUMN id SET DEFAULT nextval('norm.event_diff_id_seq'::regclass);


--
-- Name: event_observation id; Type: DEFAULT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_observation ALTER COLUMN id SET DEFAULT nextval('norm.event_observation_id_seq'::regclass);


--
-- Name: data_quality_check id; Type: DEFAULT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.data_quality_check ALTER COLUMN id SET DEFAULT nextval('ops.data_quality_check_id_seq'::regclass);


--
-- Name: schema_fingerprint id; Type: DEFAULT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.schema_fingerprint ALTER COLUMN id SET DEFAULT nextval('ops.schema_fingerprint_id_seq'::regclass);


--
-- Name: snapshot_parse_error id; Type: DEFAULT; Schema: raw; Owner: -
--

ALTER TABLE ONLY raw.snapshot_parse_error ALTER COLUMN id SET DEFAULT nextval('raw.snapshot_parse_error_id_seq'::regclass);


--
-- Name: feature_definition pk_feature_definition; Type: CONSTRAINT; Schema: feature; Owner: -
--

ALTER TABLE ONLY feature.feature_definition
    ADD CONSTRAINT pk_feature_definition PRIMARY KEY (feature_code);


--
-- Name: feature_value pk_feature_value; Type: CONSTRAINT; Schema: feature; Owner: -
--

ALTER TABLE ONLY feature.feature_value
    ADD CONSTRAINT pk_feature_value PRIMARY KEY (as_of_date, feature_code, vintage_at);


--
-- Name: backtest pk_backtest; Type: CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.backtest
    ADD CONSTRAINT pk_backtest PRIMARY KEY (id);


--
-- Name: forecast pk_forecast; Type: CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.forecast
    ADD CONSTRAINT pk_forecast PRIMARY KEY (forecast_created_at, id);


--
-- Name: forecast_evaluation pk_forecast_evaluation; Type: CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.forecast_evaluation
    ADD CONSTRAINT pk_forecast_evaluation PRIMARY KEY (evaluated_at, forecast_id);


--
-- Name: index_value pk_index_value; Type: CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.index_value
    ADD CONSTRAINT pk_index_value PRIMARY KEY (as_of_date, index_code, vintage_at);


--
-- Name: model_run pk_model_run; Type: CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.model_run
    ADD CONSTRAINT pk_model_run PRIMARY KEY (id);


--
-- Name: backtest uq_backtest_identity; Type: CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.backtest
    ADD CONSTRAINT uq_backtest_identity UNIQUE (model_run_id, scheme, finished_at);


--
-- Name: forecast uq_forecast_identity; Type: CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.forecast
    ADD CONSTRAINT uq_forecast_identity UNIQUE (model_run_id, target_code, target_date, interval_level, forecast_created_at);


--
-- Name: model_run uq_model_run_identity; Type: CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.model_run
    ADD CONSTRAINT uq_model_run_identity UNIQUE (model_name, model_version, trained_at);


--
-- Name: event_diff pk_event_diff; Type: CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_diff
    ADD CONSTRAINT pk_event_diff PRIMARY KEY (detected_at, id);


--
-- Name: event_observation pk_event_observation; Type: CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_observation
    ADD CONSTRAINT pk_event_observation PRIMARY KEY (observed_at, id);


--
-- Name: information_attention pk_information_attention; Type: CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.information_attention
    ADD CONSTRAINT pk_information_attention PRIMARY KEY (as_of_date, topic_key);


--
-- Name: observation pk_observation; Type: CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.observation
    ADD CONSTRAINT pk_observation PRIMARY KEY (observed_at, series_id, vintage_at);


--
-- Name: agent_invocation pk_agent_invocation; Type: CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.agent_invocation
    ADD CONSTRAINT pk_agent_invocation PRIMARY KEY (id);


--
-- Name: data_quality_check pk_data_quality_check; Type: CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.data_quality_check
    ADD CONSTRAINT pk_data_quality_check PRIMARY KEY (id);


--
-- Name: pipeline_run pk_pipeline_run; Type: CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.pipeline_run
    ADD CONSTRAINT pk_pipeline_run PRIMARY KEY (id);


--
-- Name: schema_fingerprint pk_schema_fingerprint; Type: CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.schema_fingerprint
    ADD CONSTRAINT pk_schema_fingerprint PRIMARY KEY (id);


--
-- Name: source_health pk_source_health; Type: CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.source_health
    ADD CONSTRAINT pk_source_health PRIMARY KEY (source_id);


--
-- Name: schema_fingerprint uq_fingerprint_file_hash; Type: CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.schema_fingerprint
    ADD CONSTRAINT uq_fingerprint_file_hash UNIQUE (file_name, content_hash);


--
-- Name: snapshot pk_snapshot; Type: CONSTRAINT; Schema: raw; Owner: -
--

ALTER TABLE ONLY raw.snapshot
    ADD CONSTRAINT pk_snapshot PRIMARY KEY (id);


--
-- Name: snapshot_parse_error pk_snapshot_parse_error; Type: CONSTRAINT; Schema: raw; Owner: -
--

ALTER TABLE ONLY raw.snapshot_parse_error
    ADD CONSTRAINT pk_snapshot_parse_error PRIMARY KEY (id);


--
-- Name: snapshot uq_snapshot_idempotency_key; Type: CONSTRAINT; Schema: raw; Owner: -
--

ALTER TABLE ONLY raw.snapshot
    ADD CONSTRAINT uq_snapshot_idempotency_key UNIQUE (idempotency_key);


--
-- Name: segment pk_segment; Type: CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.segment
    ADD CONSTRAINT pk_segment PRIMARY KEY (code);


--
-- Name: series pk_series; Type: CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.series
    ADD CONSTRAINT pk_series PRIMARY KEY (id);


--
-- Name: source pk_source; Type: CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.source
    ADD CONSTRAINT pk_source PRIMARY KEY (id);


--
-- Name: venue pk_venue; Type: CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.venue
    ADD CONSTRAINT pk_venue PRIMARY KEY (id);


--
-- Name: series uq_series_code; Type: CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.series
    ADD CONSTRAINT uq_series_code UNIQUE (code);


--
-- Name: source uq_source_code; Type: CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.source
    ADD CONSTRAINT uq_source_code UNIQUE (code);


--
-- Name: venue uq_venue_code; Type: CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.venue
    ADD CONSTRAINT uq_venue_code UNIQUE (code);


--
-- Name: brin_feature_value_as_of; Type: INDEX; Schema: feature; Owner: -
--

CREATE INDEX brin_feature_value_as_of ON feature.feature_value USING brin (as_of_date) WITH (pages_per_range='32');


--
-- Name: ix_feature_value_available; Type: INDEX; Schema: feature; Owner: -
--

CREATE INDEX ix_feature_value_available ON feature.feature_value USING btree (max_input_available_at);


--
-- Name: ix_feature_value_code_date; Type: INDEX; Schema: feature; Owner: -
--

CREATE INDEX ix_feature_value_code_date ON feature.feature_value USING btree (feature_code, as_of_date);


--
-- Name: brin_forecast_created; Type: INDEX; Schema: model; Owner: -
--

CREATE INDEX brin_forecast_created ON model.forecast USING brin (forecast_created_at) WITH (pages_per_range='32');


--
-- Name: ix_forecast_created; Type: INDEX; Schema: model; Owner: -
--

CREATE INDEX ix_forecast_created ON model.forecast USING btree (forecast_created_at);


--
-- Name: ix_forecast_eval_target; Type: INDEX; Schema: model; Owner: -
--

CREATE INDEX ix_forecast_eval_target ON model.forecast_evaluation USING btree (target_code, target_date);


--
-- Name: ix_forecast_run; Type: INDEX; Schema: model; Owner: -
--

CREATE INDEX ix_forecast_run ON model.forecast USING btree (model_run_id);


--
-- Name: ix_forecast_target; Type: INDEX; Schema: model; Owner: -
--

CREATE INDEX ix_forecast_target ON model.forecast USING btree (target_code, target_date);


--
-- Name: ix_index_value_code_date; Type: INDEX; Schema: model; Owner: -
--

CREATE INDEX ix_index_value_code_date ON model.index_value USING btree (index_code, as_of_date);


--
-- Name: ix_model_run_name_trained; Type: INDEX; Schema: model; Owner: -
--

CREATE INDEX ix_model_run_name_trained ON model.model_run USING btree (model_name, trained_at);


--
-- Name: brin_event_diff_detected_at; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX brin_event_diff_detected_at ON norm.event_diff USING brin (detected_at) WITH (pages_per_range='32');


--
-- Name: brin_event_observation_observed_at; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX brin_event_observation_observed_at ON norm.event_observation USING brin (observed_at) WITH (pages_per_range='32');


--
-- Name: brin_observation_observed_at; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX brin_observation_observed_at ON norm.observation USING brin (observed_at) WITH (pages_per_range='32');


--
-- Name: ix_event_diff_venue; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_event_diff_venue ON norm.event_diff USING btree (venue_id, detected_at);


--
-- Name: ix_event_obs_key; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_event_obs_key ON norm.event_observation USING btree (venue_id, event_key, observed_at);


--
-- Name: ix_event_obs_snapshot; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_event_obs_snapshot ON norm.event_observation USING btree (snapshot_id);


--
-- Name: ix_event_obs_venue_date; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_event_obs_venue_date ON norm.event_observation USING btree (venue_id, observed_date);


--
-- Name: ix_event_obs_venue_starts; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_event_obs_venue_starts ON norm.event_observation USING btree (venue_id, starts_at);


--
-- Name: ix_observation_series_date; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_observation_series_date ON norm.observation USING btree (series_id, observed_date);


--
-- Name: ix_observation_series_observed; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_observation_series_observed ON norm.observation USING btree (series_id, observed_at);


--
-- Name: ix_observation_snapshot; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_observation_snapshot ON norm.observation USING btree (snapshot_id);


--
-- Name: ix_observation_vintage; Type: INDEX; Schema: norm; Owner: -
--

CREATE INDEX ix_observation_vintage ON norm.observation USING btree (vintage_at);


--
-- Name: ix_agent_invocation_agent_started; Type: INDEX; Schema: ops; Owner: -
--

CREATE INDEX ix_agent_invocation_agent_started ON ops.agent_invocation USING btree (agent_code, started_at);


--
-- Name: ix_dq_check_failed; Type: INDEX; Schema: ops; Owner: -
--

CREATE INDEX ix_dq_check_failed ON ops.data_quality_check USING btree (passed, as_of_date);


--
-- Name: ix_dq_check_target_date; Type: INDEX; Schema: ops; Owner: -
--

CREATE INDEX ix_dq_check_target_date ON ops.data_quality_check USING btree (target_code, as_of_date);


--
-- Name: ix_pipeline_run_pipeline_started; Type: INDEX; Schema: ops; Owner: -
--

CREATE INDEX ix_pipeline_run_pipeline_started ON ops.pipeline_run USING btree (pipeline, started_at);


--
-- Name: ix_snapshot_content_hash; Type: INDEX; Schema: raw; Owner: -
--

CREATE INDEX ix_snapshot_content_hash ON raw.snapshot USING btree (content_hash);


--
-- Name: ix_snapshot_parse_error_snapshot; Type: INDEX; Schema: raw; Owner: -
--

CREATE INDEX ix_snapshot_parse_error_snapshot ON raw.snapshot_parse_error USING btree (snapshot_id);


--
-- Name: ix_snapshot_run_id; Type: INDEX; Schema: raw; Owner: -
--

CREATE INDEX ix_snapshot_run_id ON raw.snapshot USING btree (run_id);


--
-- Name: ix_snapshot_source_fetched; Type: INDEX; Schema: raw; Owner: -
--

CREATE INDEX ix_snapshot_source_fetched ON raw.snapshot USING btree (source_id, fetched_at);


--
-- Name: ix_series_segment_code; Type: INDEX; Schema: registry; Owner: -
--

CREATE INDEX ix_series_segment_code ON registry.series USING btree (segment_code);


--
-- Name: ix_series_source_id; Type: INDEX; Schema: registry; Owner: -
--

CREATE INDEX ix_series_source_id ON registry.series USING btree (source_id);


--
-- Name: ix_venue_category; Type: INDEX; Schema: registry; Owner: -
--

CREATE INDEX ix_venue_category ON registry.venue USING btree (category);


--
-- Name: feature_value trg_feature_value_append_only; Type: TRIGGER; Schema: feature; Owner: -
--

CREATE TRIGGER trg_feature_value_append_only BEFORE DELETE OR UPDATE ON feature.feature_value FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation('Recompute writes a new vintage; it does not overwrite one.');


--
-- Name: forecast trg_forecast_append_only; Type: TRIGGER; Schema: model; Owner: -
--

CREATE TRIGGER trg_forecast_append_only BEFORE DELETE OR UPDATE ON model.forecast FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation('Historical forecast vintages are never overwritten.');


--
-- Name: index_value trg_index_value_append_only; Type: TRIGGER; Schema: model; Owner: -
--

CREATE TRIGGER trg_index_value_append_only BEFORE DELETE OR UPDATE ON model.index_value FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation('A restated index reading is a new vintage.');


--
-- Name: event_diff trg_event_diff_append_only; Type: TRIGGER; Schema: norm; Owner: -
--

CREATE TRIGGER trg_event_diff_append_only BEFORE DELETE OR UPDATE ON norm.event_diff FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation('A diff describes a moment that has already passed.');


--
-- Name: event_observation trg_event_observation_append_only; Type: TRIGGER; Schema: norm; Owner: -
--

CREATE TRIGGER trg_event_observation_append_only BEFORE DELETE OR UPDATE ON norm.event_observation FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation('Each snapshot of a listing is its own row.');


--
-- Name: event_observation trg_event_observation_observed_date; Type: TRIGGER; Schema: norm; Owner: -
--

CREATE TRIGGER trg_event_observation_observed_date BEFORE INSERT ON norm.event_observation FOR EACH ROW EXECUTE FUNCTION norm.set_observed_date();


--
-- Name: information_attention trg_information_attention_append_only; Type: TRIGGER; Schema: norm; Owner: -
--

CREATE TRIGGER trg_information_attention_append_only BEFORE DELETE OR UPDATE ON norm.information_attention FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation('Attention measured on a date is not revised afterwards.');


--
-- Name: observation trg_observation_append_only; Type: TRIGGER; Schema: norm; Owner: -
--

CREATE TRIGGER trg_observation_append_only BEFORE DELETE OR UPDATE ON norm.observation FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation('A revised value is a new row with a later vintage_at.');


--
-- Name: observation trg_observation_assertion_class; Type: TRIGGER; Schema: norm; Owner: -
--

CREATE TRIGGER trg_observation_assertion_class BEFORE INSERT ON norm.observation FOR EACH ROW EXECUTE FUNCTION norm.enforce_assertion_class();


--
-- Name: observation trg_observation_observed_date; Type: TRIGGER; Schema: norm; Owner: -
--

CREATE TRIGGER trg_observation_observed_date BEFORE INSERT ON norm.observation FOR EACH ROW EXECUTE FUNCTION norm.set_observed_date();


--
-- Name: observation trg_observation_proxy_ceiling; Type: TRIGGER; Schema: norm; Owner: -
--

CREATE TRIGGER trg_observation_proxy_ceiling BEFORE INSERT ON norm.observation FOR EACH ROW EXECUTE FUNCTION norm.enforce_proxy_ceiling();


--
-- Name: snapshot trg_snapshot_append_only; Type: TRIGGER; Schema: raw; Owner: -
--

CREATE TRIGGER trg_snapshot_append_only BEFORE DELETE OR UPDATE ON raw.snapshot FOR EACH ROW EXECUTE FUNCTION ops.reject_mutation('Raw evidence is never rewritten.');


--
-- Name: backtest fk_backtest_model_run_id_model_run; Type: FK CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.backtest
    ADD CONSTRAINT fk_backtest_model_run_id_model_run FOREIGN KEY (model_run_id) REFERENCES model.model_run(id) ON DELETE RESTRICT;


--
-- Name: forecast fk_forecast_calibration_run_id_model_run; Type: FK CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.forecast
    ADD CONSTRAINT fk_forecast_calibration_run_id_model_run FOREIGN KEY (calibration_run_id) REFERENCES model.model_run(id) ON DELETE RESTRICT;


--
-- Name: forecast fk_forecast_model_run_id_model_run; Type: FK CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.forecast
    ADD CONSTRAINT fk_forecast_model_run_id_model_run FOREIGN KEY (model_run_id) REFERENCES model.model_run(id) ON DELETE RESTRICT;


--
-- Name: index_value fk_index_value_model_run_id_model_run; Type: FK CONSTRAINT; Schema: model; Owner: -
--

ALTER TABLE ONLY model.index_value
    ADD CONSTRAINT fk_index_value_model_run_id_model_run FOREIGN KEY (model_run_id) REFERENCES model.model_run(id) ON DELETE RESTRICT;


--
-- Name: event_diff fk_event_diff_snapshot_after_id_snapshot; Type: FK CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_diff
    ADD CONSTRAINT fk_event_diff_snapshot_after_id_snapshot FOREIGN KEY (snapshot_after_id) REFERENCES raw.snapshot(id) ON DELETE RESTRICT;


--
-- Name: event_diff fk_event_diff_snapshot_before_id_snapshot; Type: FK CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_diff
    ADD CONSTRAINT fk_event_diff_snapshot_before_id_snapshot FOREIGN KEY (snapshot_before_id) REFERENCES raw.snapshot(id) ON DELETE RESTRICT;


--
-- Name: event_diff fk_event_diff_venue_id_venue; Type: FK CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_diff
    ADD CONSTRAINT fk_event_diff_venue_id_venue FOREIGN KEY (venue_id) REFERENCES registry.venue(id) ON DELETE RESTRICT;


--
-- Name: event_observation fk_event_observation_snapshot_id_snapshot; Type: FK CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_observation
    ADD CONSTRAINT fk_event_observation_snapshot_id_snapshot FOREIGN KEY (snapshot_id) REFERENCES raw.snapshot(id) ON DELETE RESTRICT;


--
-- Name: event_observation fk_event_observation_venue_id_venue; Type: FK CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.event_observation
    ADD CONSTRAINT fk_event_observation_venue_id_venue FOREIGN KEY (venue_id) REFERENCES registry.venue(id) ON DELETE RESTRICT;


--
-- Name: information_attention fk_information_attention_snapshot_id_snapshot; Type: FK CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.information_attention
    ADD CONSTRAINT fk_information_attention_snapshot_id_snapshot FOREIGN KEY (snapshot_id) REFERENCES raw.snapshot(id) ON DELETE RESTRICT;


--
-- Name: observation fk_observation_series_id_series; Type: FK CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.observation
    ADD CONSTRAINT fk_observation_series_id_series FOREIGN KEY (series_id) REFERENCES registry.series(id) ON DELETE RESTRICT;


--
-- Name: observation fk_observation_snapshot_id_snapshot; Type: FK CONSTRAINT; Schema: norm; Owner: -
--

ALTER TABLE ONLY norm.observation
    ADD CONSTRAINT fk_observation_snapshot_id_snapshot FOREIGN KEY (snapshot_id) REFERENCES raw.snapshot(id) ON DELETE RESTRICT;


--
-- Name: agent_invocation fk_agent_invocation_run_id_pipeline_run; Type: FK CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.agent_invocation
    ADD CONSTRAINT fk_agent_invocation_run_id_pipeline_run FOREIGN KEY (run_id) REFERENCES ops.pipeline_run(id) ON DELETE SET NULL;


--
-- Name: source_health fk_source_health_source_id_source; Type: FK CONSTRAINT; Schema: ops; Owner: -
--

ALTER TABLE ONLY ops.source_health
    ADD CONSTRAINT fk_source_health_source_id_source FOREIGN KEY (source_id) REFERENCES registry.source(id) ON DELETE CASCADE;


--
-- Name: snapshot_parse_error fk_snapshot_parse_error_snapshot_id_snapshot; Type: FK CONSTRAINT; Schema: raw; Owner: -
--

ALTER TABLE ONLY raw.snapshot_parse_error
    ADD CONSTRAINT fk_snapshot_parse_error_snapshot_id_snapshot FOREIGN KEY (snapshot_id) REFERENCES raw.snapshot(id) ON DELETE RESTRICT;


--
-- Name: snapshot fk_snapshot_source_id_source; Type: FK CONSTRAINT; Schema: raw; Owner: -
--

ALTER TABLE ONLY raw.snapshot
    ADD CONSTRAINT fk_snapshot_source_id_source FOREIGN KEY (source_id) REFERENCES registry.source(id) ON DELETE RESTRICT;


--
-- Name: snapshot fk_snapshot_venue_id_venue; Type: FK CONSTRAINT; Schema: raw; Owner: -
--

ALTER TABLE ONLY raw.snapshot
    ADD CONSTRAINT fk_snapshot_venue_id_venue FOREIGN KEY (venue_id) REFERENCES registry.venue(id) ON DELETE RESTRICT;


--
-- Name: segment fk_segment_parent_code_segment; Type: FK CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.segment
    ADD CONSTRAINT fk_segment_parent_code_segment FOREIGN KEY (parent_code) REFERENCES registry.segment(code) ON DELETE RESTRICT;


--
-- Name: series fk_series_segment_code_segment; Type: FK CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.series
    ADD CONSTRAINT fk_series_segment_code_segment FOREIGN KEY (segment_code) REFERENCES registry.segment(code) ON DELETE RESTRICT;


--
-- Name: series fk_series_source_id_source; Type: FK CONSTRAINT; Schema: registry; Owner: -
--

ALTER TABLE ONLY registry.series
    ADD CONSTRAINT fk_series_source_id_source FOREIGN KEY (source_id) REFERENCES registry.source(id) ON DELETE RESTRICT;


--
-- PostgreSQL database dump complete
--

\unrestrict cRZPMVqMbdU66Nfyb3JvSHSZxbj5hbjTlVUdqjMdNRDNfpiqKd7aIAQVkX8wPAX

