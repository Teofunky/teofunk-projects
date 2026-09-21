"""The principles are enforced by the database, not only by Python.

Each test here tries to do something the system must never do, and asserts that
PostgreSQL refuses it. If one of these starts passing silently, the guarantee
is gone even if every Python check still looks right.
"""

from __future__ import annotations

import datetime as dt

import pytest
from sqlalchemy import text
from sqlalchemy.exc import DBAPIError, IntegrityError

pytestmark = pytest.mark.db

DAY = dt.datetime(2026, 9, 20, tzinfo=dt.UTC)
NOW = dt.datetime(2026, 9, 21, 9, 0, tzinfo=dt.UTC)


def _snapshot(session, source_code: str = "cbr_key_rate", digest: str | None = None) -> str:
    digest = digest or ("a" * 64)
    return session.execute(
        text(
            """
            INSERT INTO raw.snapshot (source_id, idempotency_key, fetched_at, content_hash,
                                      legal_basis, collector, collector_version, content_inline)
            SELECT id, :k, :t, :h, 'official_api', 'test', '1', '{}'
            FROM registry.source WHERE code = :c
            RETURNING id
            """
        ),
        {"k": f"key-{digest[:12]}", "t": NOW, "h": digest, "c": source_code},
    ).scalar_one()


def _model_run(session) -> str:
    return session.execute(
        text(
            """
            INSERT INTO model.model_run
              (model_name, model_version, role, feature_set_version, feature_vintage,
               trained_at, training_window_start, training_window_end)
            VALUES ('seasonal_naive', '1.0.0', 'benchmark', 'fs1', :v, :t,
                    DATE '2025-01-01', DATE '2026-09-01')
            RETURNING id
            """
        ),
        {"v": NOW, "t": NOW},
    ).scalar_one()


def _insert_observation(session, series_code: str, assertion_class: str, snapshot_id):
    session.execute(
        text(
            """
            INSERT INTO norm.observation
              (observed_at, series_id, vintage_at, period, period_end, value, unit,
               geography, assertion_class, snapshot_id, retrieved_at)
            SELECT :d, id, :v, 'day', :e, 1.0, unit, 'RU-MOW', :a, :s, :r
            FROM registry.series WHERE code = :c
            """
        ),
        {
            "d": DAY,
            "v": NOW,
            "e": DAY + dt.timedelta(days=1),
            "a": assertion_class,
            "s": snapshot_id,
            "r": NOW,
            "c": series_code,
        },
    )


# ---------------------------------------------------------------------------
# Principle 4: no number without evidence
# ---------------------------------------------------------------------------


def test_an_observation_without_a_snapshot_cannot_be_inserted(synced_session):
    with pytest.raises(IntegrityError):
        synced_session.execute(
            text(
                """
                INSERT INTO norm.observation
                  (observed_at, series_id, vintage_at, period, period_end, value, unit,
                   geography, assertion_class, snapshot_id, retrieved_at)
                SELECT :d, id, :v, 'day', :e, 1.0, unit, 'RU', 'fact', NULL, :r
                FROM registry.series WHERE code = 'cbr.key_rate.daily'
                """
            ),
            {"d": DAY, "v": NOW, "e": DAY + dt.timedelta(days=1), "r": NOW},
        )


# ---------------------------------------------------------------------------
# Principles 3 and 5: an inference is never a fact
# ---------------------------------------------------------------------------


def test_database_rejects_an_inferred_series_claiming_to_be_a_fact(synced_session):
    snapshot = _snapshot(synced_session, "venue_public_pages", "b" * 64)
    with pytest.raises(DBAPIError, match="may not claim"):
        _insert_observation(synced_session, "venue.inventory_pressure.msk.daily", "fact", snapshot)


def test_database_rejects_a_derived_series_claiming_to_be_a_fact(synced_session):
    snapshot = _snapshot(synced_session, "open_meteo", "c" * 64)
    with pytest.raises(DBAPIError, match="may not claim"):
        _insert_observation(synced_session, "weather.friction_index.msk.daily", "fact", snapshot)


def test_an_inferred_series_may_be_stored_as_an_estimate(synced_session):
    snapshot = _snapshot(synced_session, "venue_public_pages", "d" * 64)
    _insert_observation(synced_session, "venue.inventory_pressure.msk.daily", "estimate", snapshot)
    assert (
        synced_session.execute(
            text("SELECT count(*) FROM norm.observation WHERE assertion_class = 'estimate'")
        ).scalar_one()
        == 1
    )


def test_a_direct_series_on_a_proxy_source_may_be_a_fact(synced_session):
    """Counting listed events is observation. Reading demand into it is not."""
    snapshot = _snapshot(synced_session, "venue_public_pages", "e" * 64)
    _insert_observation(synced_session, "venue.events_listed.msk.daily", "fact", snapshot)
    assert (
        synced_session.execute(
            text("SELECT count(*) FROM norm.observation WHERE assertion_class = 'fact'")
        ).scalar_one()
        == 1
    )


# ---------------------------------------------------------------------------
# Principle 7: forecast vintages are never overwritten
# ---------------------------------------------------------------------------


def _insert_forecast(session, run_id, **overrides):
    params = {
        "c": NOW,
        "r": run_id,
        "t": "barometer_now",
        "d": dt.date(2026, 9, 28),
        "h": 7,
        "p": 102.0,
        "lo": 95.0,
        "hi": 109.0,
        "lvl": 0.8,
        "v": "1.0.0",
        "fv": NOW,
        "q": "index",
    }
    params.update(overrides)
    session.execute(
        text(
            """
            INSERT INTO model.forecast
              (forecast_created_at, model_run_id, target_code, target_date, horizon_days,
               quantity, point_forecast, lower_bound, upper_bound, interval_level,
               model_version, feature_vintage, feature_set_version)
            VALUES (:c, :r, :t, :d, :h, :q, :p, :lo, :hi, :lvl, :v, :fv, 'fs1')
            """
        ),
        params,
    )


def test_a_stored_forecast_cannot_be_updated(synced_session):
    run = _model_run(synced_session)
    _insert_forecast(synced_session, run)
    synced_session.flush()
    with pytest.raises(DBAPIError, match="append-only"):
        synced_session.execute(text("UPDATE model.forecast SET point_forecast = 999"))


def test_a_stored_forecast_cannot_be_deleted(synced_session):
    run = _model_run(synced_session)
    _insert_forecast(synced_session, run)
    synced_session.flush()
    with pytest.raises(DBAPIError, match="append-only"):
        synced_session.execute(text("DELETE FROM model.forecast"))


def test_raw_evidence_cannot_be_rewritten(synced_session):
    _snapshot(synced_session, digest="f" * 64)
    synced_session.flush()
    with pytest.raises(DBAPIError, match="append-only"):
        synced_session.execute(
            text("UPDATE raw.snapshot SET content_hash = '0' || :h"), {"h": "0" * 63}
        )


def test_an_observation_cannot_be_rewritten(synced_session):
    snapshot = _snapshot(synced_session, digest="1" * 64)
    _insert_observation(synced_session, "cbr.key_rate.daily", "fact", snapshot)
    synced_session.flush()
    with pytest.raises(DBAPIError, match="append-only"):
        synced_session.execute(text("UPDATE norm.observation SET value = 99"))


# ---------------------------------------------------------------------------
# Principle 8: no look-ahead
# ---------------------------------------------------------------------------


def test_a_forecast_built_on_future_features_is_rejected(synced_session):
    run = _model_run(synced_session)
    with pytest.raises(IntegrityError, match="no_look_ahead_in_features"):
        _insert_forecast(synced_session, run, fv=NOW + dt.timedelta(days=3))


def test_a_forecast_targeting_the_past_is_rejected(synced_session):
    run = _model_run(synced_session)
    with pytest.raises(IntegrityError, match="target_is_not_in_the_past"):
        _insert_forecast(synced_session, run, d=dt.date(2026, 9, 1))


def test_a_feature_cannot_predate_its_inputs(synced_session):
    with pytest.raises(IntegrityError, match="feature_cannot_precede_its_inputs"):
        synced_session.execute(
            text(
                """
                INSERT INTO feature.feature_value
                  (as_of_date, feature_code, vintage_at, value, feature_set_version,
                   max_input_available_at)
                VALUES (DATE '2026-09-20', 'f.test', :v, 1.0, 'fs1', :later)
                """
            ),
            {"v": NOW, "later": NOW + dt.timedelta(hours=1)},
        )


def test_point_in_time_feature_view_hides_features_not_yet_available(synced_session):
    synced_session.execute(
        text(
            """
            INSERT INTO feature.feature_value
              (as_of_date, feature_code, vintage_at, value, feature_set_version,
               max_input_available_at)
            VALUES (DATE '2026-09-20', 'f.test', :v, 1.0, 'fs1', :avail)
            """
        ),
        {"v": NOW, "avail": NOW},
    )
    before = synced_session.execute(
        text("SELECT count(*) FROM feature.feature_as_of(:t)"),
        {"t": NOW - dt.timedelta(hours=1)},
    ).scalar_one()
    after = synced_session.execute(
        text("SELECT count(*) FROM feature.feature_as_of(:t)"), {"t": NOW}
    ).scalar_one()
    assert (before, after) == (0, 1)


# ---------------------------------------------------------------------------
# The calibration gate
# ---------------------------------------------------------------------------


def test_a_market_sales_forecast_without_calibration_is_rejected(synced_session):
    run = _model_run(synced_session)
    with pytest.raises(IntegrityError, match="sales_forecast_requires_calibration"):
        _insert_forecast(synced_session, run, q="market_sales")


def test_an_interval_that_excludes_its_own_point_is_rejected(synced_session):
    run = _model_run(synced_session)
    with pytest.raises(IntegrityError, match="point_inside_interval"):
        _insert_forecast(synced_session, run, p=200.0)


# ---------------------------------------------------------------------------
# "capacity only if sourced", in the database
# ---------------------------------------------------------------------------


def test_database_rejects_an_unsourced_venue_capacity(synced_session):
    with pytest.raises(IntegrityError, match="capacity_only_if_sourced"):
        synced_session.execute(
            text("UPDATE registry.venue SET capacity = 250 WHERE code = 'esse_jazz'")
        )


# ---------------------------------------------------------------------------
# Principle 9: a rumour is attention, not an event
# ---------------------------------------------------------------------------


def test_information_attention_can_never_be_recorded_as_a_fact(synced_session):
    snapshot = _snapshot(synced_session, "production_calendar", "2" * 64)
    with pytest.raises(IntegrityError, match="attention_is_never_a_fact"):
        synced_session.execute(
            text(
                """
                INSERT INTO norm.information_attention
                  (as_of_date, topic_key, topic_label_ru, attention_score,
                   measurement_basis, verification_status, assertion_class, snapshot_id)
                VALUES (DATE '2026-09-20', 'topic', 'Тема', 1.0, 'search', 'unverified',
                        'fact', :s)
                """
            ),
            {"s": snapshot},
        )


def test_unverified_attention_is_storable_as_a_hypothesis(synced_session):
    snapshot = _snapshot(synced_session, "production_calendar", "3" * 64)
    synced_session.execute(
        text(
            """
            INSERT INTO norm.information_attention
              (as_of_date, topic_key, topic_label_ru, attention_score,
               measurement_basis, verification_status, assertion_class, snapshot_id)
            VALUES (DATE '2026-09-20', 'topic', 'Тема', 1.0, 'search', 'unverified',
                    'hypothesis', :s)
            """
        ),
        {"s": snapshot},
    )
    assert (
        synced_session.execute(text("SELECT count(*) FROM norm.information_attention")).scalar_one()
        == 1
    )


# ---------------------------------------------------------------------------
# Principle 1, as a privilege rather than a promise
# ---------------------------------------------------------------------------


def test_the_agent_role_cannot_write_the_model_layer(synced_session):
    granted = synced_session.execute(
        text(
            """
            SELECT count(*) FROM information_schema.role_table_grants
            WHERE grantee = 'barometer_agent'
              AND table_schema IN ('model', 'feature')
              AND privilege_type IN ('INSERT', 'UPDATE', 'DELETE')
            """
        )
    ).scalar_one()
    assert granted == 0, "an agent has write access to modelled numbers"


def test_the_agent_role_can_write_evidence_and_observations(synced_session):
    granted = synced_session.execute(
        text(
            """
            SELECT count(*) FROM information_schema.role_table_grants
            WHERE grantee = 'barometer_agent'
              AND privilege_type = 'INSERT'
              AND (table_schema, table_name) IN
                  (('raw','snapshot'), ('norm','observation'))
            """
        )
    ).scalar_one()
    assert granted == 2
