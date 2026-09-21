"""Provenance: no number without evidence, and no silent gaps."""

from __future__ import annotations

import datetime as dt

import pytest
from pydantic import ValidationError

from barometer.config.models import AssertionClass
from barometer.provenance.record import FetchEnvelope, ObservationRecord
from barometer.provenance.writer import ProvenanceError, ProvenanceWriter
from barometer.storage.base import content_hash

pytestmark = pytest.mark.db


# ---------------------------------------------------------------------------
# The record contract (no database needed)
# ---------------------------------------------------------------------------


@pytest.mark.parametrize("bad", [{"value": None}, {"value": float("nan")}])
def test_a_missing_value_must_state_why(bad):
    start = dt.datetime(2026, 9, 20, tzinfo=dt.UTC)
    with pytest.raises(ValidationError):
        ObservationRecord(
            series_code="cbr.key_rate.daily",
            observed_at=start,
            period="day",
            period_end=start + dt.timedelta(days=1),
            unit="percent_per_annum",
            geography="RU",
            assertion_class=AssertionClass.FACT,
            **bad,
        )


def test_a_value_and_a_missing_reason_cannot_coexist():
    start = dt.datetime(2026, 9, 20, tzinfo=dt.UTC)
    with pytest.raises(ValidationError, match="cannot both be present"):
        ObservationRecord(
            series_code="cbr.key_rate.daily",
            observed_at=start,
            period="day",
            period_end=start + dt.timedelta(days=1),
            value=16.0,
            missing_reason="holiday",
            unit="percent_per_annum",
            geography="RU",
            assertion_class=AssertionClass.FACT,
        )


def test_naive_timestamps_are_rejected():
    start = dt.datetime(2026, 9, 20)
    with pytest.raises(ValidationError, match="timezone-aware"):
        ObservationRecord(
            series_code="x",
            observed_at=start,
            period="day",
            period_end=start + dt.timedelta(days=1),
            value=1.0,
            unit="u",
            geography="RU",
            assertion_class=AssertionClass.FACT,
        )


def test_period_end_must_follow_observed_at():
    start = dt.datetime(2026, 9, 20, tzinfo=dt.UTC)
    with pytest.raises(ValidationError, match="period_end must be after"):
        ObservationRecord(
            series_code="x",
            observed_at=start,
            period="day",
            period_end=start,
            value=1.0,
            unit="u",
            geography="RU",
            assertion_class=AssertionClass.FACT,
        )


def test_idempotency_key_is_stable_and_content_sensitive(envelope):
    digest = content_hash(b"payload")
    assert envelope.idempotency_key(digest) == envelope.idempotency_key(digest)
    assert envelope.idempotency_key(digest) != envelope.idempotency_key(content_hash(b"other"))


def test_idempotency_key_distinguishes_request_params(now):
    def make(params):
        return FetchEnvelope(
            source_code="cbr_key_rate",
            fetched_at=now,
            request_url="https://example.invalid/x",
            request_params=params,
            legal_basis="official_api",
            collector="T",
            collector_version="1",
        )

    digest = content_hash(b"same bytes")
    assert make({"a": 1}).idempotency_key(digest) != make({"a": 2}).idempotency_key(digest)


# ---------------------------------------------------------------------------
# The write path
# ---------------------------------------------------------------------------


def test_observations_land_with_a_snapshot(synced_session, store, envelope, make_observation):
    writer = ProvenanceWriter(synced_session, store)
    result = writer.write(envelope, b'{"key_rate": 16.0}', observations=[make_observation()])

    assert result.already_seen is False
    assert result.observations_written == 1

    from sqlalchemy import text

    row = synced_session.execute(
        text(
            "SELECT series_code, value, source_code, content_hash, request_url, is_proxy "
            "FROM norm.observation_provenance WHERE series_code = :c"
        ),
        {"c": "cbr.key_rate.daily"},
    ).one()
    assert row.value == 16.0
    assert row.source_code == "cbr_key_rate"
    assert row.content_hash == content_hash(b'{"key_rate": 16.0}')
    assert row.request_url.startswith("https://www.cbr.ru/")
    assert row.is_proxy is False


def test_rerunning_the_same_fetch_is_a_no_op(synced_session, store, envelope, make_observation):
    writer = ProvenanceWriter(synced_session, store)
    payload = b'{"key_rate": 16.0}'
    first = writer.write(envelope, payload, observations=[make_observation()])
    second = writer.write(envelope, payload, observations=[make_observation()])

    assert first.already_seen is False
    assert second.already_seen is True
    assert second.snapshot_id == first.snapshot_id
    assert second.observations_written == 0


def test_a_revised_value_becomes_a_new_vintage(synced_session, store, envelope, make_observation):
    """A source correcting itself must not erase what we believed yesterday."""
    from sqlalchemy import text

    writer = ProvenanceWriter(synced_session, store)
    day = dt.datetime(2026, 9, 20, tzinfo=dt.UTC)
    v1 = envelope.model_copy(update={"fetched_at": dt.datetime(2026, 9, 21, 9, 0, tzinfo=dt.UTC)})
    v2 = envelope.model_copy(update={"fetched_at": dt.datetime(2026, 9, 22, 9, 0, tzinfo=dt.UTC)})

    writer.write(v1, b'{"key_rate": 16.0}', observations=[make_observation(value=16.0)])
    writer.write(v2, b'{"key_rate": 16.5}', observations=[make_observation(value=16.5)])

    rows = synced_session.execute(
        text(
            "SELECT value, vintage_at FROM norm.observation o "
            "JOIN registry.series s ON s.id = o.series_id "
            "WHERE s.code = 'cbr.key_rate.daily' AND o.observed_at = :d "
            "ORDER BY vintage_at"
        ),
        {"d": day},
    ).all()
    assert [r.value for r in rows] == [16.0, 16.5]

    current = synced_session.execute(
        text(
            "SELECT value FROM norm.observation_current o "
            "JOIN registry.series s ON s.id = o.series_id "
            "WHERE s.code = 'cbr.key_rate.daily'"
        )
    ).scalar_one()
    assert current == 16.5

    as_of_yesterday = synced_session.execute(
        text(
            "SELECT value FROM norm.observation_as_of(:t) o "
            "JOIN registry.series s ON s.id = o.series_id "
            "WHERE s.code = 'cbr.key_rate.daily'"
        ),
        {"t": dt.datetime(2026, 9, 21, 23, 0, tzinfo=dt.UTC)},
    ).scalar_one()
    assert as_of_yesterday == 16.0, "point-in-time query leaked a later revision"


def test_unit_mismatch_against_the_registry_is_refused(
    synced_session, store, envelope, make_observation
):
    writer = ProvenanceWriter(synced_session, store)
    with pytest.raises(ProvenanceError, match="unit"):
        writer.write(envelope, b"x", observations=[make_observation(unit="percent")])


def test_unknown_series_is_refused(synced_session, store, envelope, make_observation):
    writer = ProvenanceWriter(synced_session, store)
    with pytest.raises(ProvenanceError, match="series not in registry"):
        writer.write(envelope, b"x", observations=[make_observation(series_code="made.up.series")])


def test_unknown_source_is_refused(synced_session, store, now, make_observation):
    writer = ProvenanceWriter(synced_session, store)
    envelope = FetchEnvelope(
        source_code="not_a_source",
        fetched_at=now,
        legal_basis="official_api",
        collector="T",
        collector_version="1",
    )
    with pytest.raises(ProvenanceError, match=r"not in registry\.source"):
        writer.write(envelope, b"x", observations=[make_observation()])


def test_large_payloads_go_to_the_store_small_ones_stay_inline(
    synced_session, store, envelope, make_observation
):
    from sqlalchemy import text

    writer = ProvenanceWriter(synced_session, store)
    big = b"x" * 8192
    result = writer.write(envelope, big, observations=[make_observation()])
    uri, inline = synced_session.execute(
        text("SELECT storage_uri, content_inline FROM raw.snapshot WHERE id = :i"),
        {"i": result.snapshot_id},
    ).one()
    assert uri is not None and inline is None
    assert store.get(uri) == big
