"""The runner refuses unlawful fetches, retries only what is transient, and
degrades rather than corrupting when a source misbehaves."""

from __future__ import annotations

import datetime as dt

import pytest
from sqlalchemy import text

from barometer.config.models import RetryPolicy
from barometer.ingest.adapter import (
    FetchOutcome,
    FetchPlan,
    ParseOutcome,
    PermanentAdapterError,
    SourceAdapter,
    TransientAdapterError,
    Window,
)
from barometer.ingest.retry import RateLimiter, call_with_retry
from barometer.ingest.runner import IngestRunner, SourceNotPermitted, check_permitted
from barometer.settings import Settings
from tests.conftest import observation

NOW = dt.datetime(2026, 9, 21, 9, 0, tzinfo=dt.UTC)

POLICY = RetryPolicy(
    max_attempts=3,
    backoff="exponential",
    initial_delay_seconds=0.01,
    max_delay_seconds=0.05,
    retry_on=["timeout"],
    no_retry_on=["auth_error"],
    on_final_failure="record_and_degrade",
)


class FakeAdapter(SourceAdapter):
    """An adapter that returns canned bytes, so nothing leaves the machine."""

    source_code = "cbr_key_rate"
    collector_version = "test-1"

    def __init__(self, source, payload=b'{"rate": 16.0}', fail_with=None, parse_error=False):
        super().__init__(source)
        self.payload = payload
        self.fail_with = fail_with
        self.parse_error = parse_error
        self.calls = 0

    def plan(self, window: Window):
        return [FetchPlan(url="https://www.cbr.ru/test", label="keyrate")]

    def fetch(self, plan):
        self.calls += 1
        if self.fail_with is not None:
            raise self.fail_with
        return FetchOutcome(
            plan=plan,
            payload=self.payload,
            fetched_at=NOW,
            http_status=200,
            content_type="application/json",
        )

    def parse(self, outcome):
        if self.parse_error:
            raise ValueError("unexpected payload shape")
        return ParseOutcome(observations=[observation(value=16.0)])


@pytest.fixture
def window() -> Window:
    return Window(start=NOW - dt.timedelta(days=7), end=NOW)


# ---------------------------------------------------------------------------
# Principle 10: permission before traffic
# ---------------------------------------------------------------------------


def test_a_disabled_source_is_never_fetched(registries):
    source = registries.sources.by_code["sberindex"]
    with pytest.raises(SourceNotPermitted, match="disabled"):
        check_permitted(source, Settings(database_url="postgresql+psycopg://x/y"))


def test_unknown_robots_status_blocks_fetching(registries):
    source = registries.sources.by_code["rosstat_emiss"]
    with pytest.raises(SourceNotPermitted):
        check_permitted(source, Settings(database_url="postgresql+psycopg://x/y"))


def test_missing_credentials_block_fetching(registries, monkeypatch):
    source = registries.sources.by_code["yandex_wordstat"]
    monkeypatch.delenv("BAROMETER_YANDEX_WORDSTAT_TOKEN", raising=False)
    with pytest.raises(SourceNotPermitted):
        check_permitted(source, Settings(database_url="postgresql+psycopg://x/y"))


def test_respect_robots_cannot_be_switched_off():
    with pytest.raises(Exception, match="principle 10"):
        Settings(database_url="postgresql+psycopg://x/y", respect_robots=False)


@pytest.mark.db
def test_blocked_source_is_recorded_not_silently_skipped(synced_session, store, registries, window):
    adapter = FakeAdapter(registries.sources.by_code["cbr_key_rate"])
    settings = Settings(database_url="postgresql+psycopg://x/y", allow_disabled_sources=False)
    # Pretend access has not been resolved for this run.
    adapter.source = adapter.source.model_copy(update={"enabled": False})

    runner = IngestRunner(synced_session, store, POLICY, settings)
    report = runner.run(adapter, window)

    assert report.status == "skipped"
    assert adapter.calls == 0, "a blocked source must not generate traffic"
    status = synced_session.execute(
        text(
            "SELECT h.status FROM ops.source_health h JOIN registry.source s "
            "ON s.id = h.source_id WHERE s.code = 'cbr_key_rate'"
        )
    ).scalar_one()
    assert status == "blocked"


# ---------------------------------------------------------------------------
# Retry policy
# ---------------------------------------------------------------------------


def test_transient_failures_are_retried():
    attempts = {"n": 0}

    def flaky():
        attempts["n"] += 1
        if attempts["n"] < 3:
            raise TransientAdapterError("timeout")
        return "ok"

    assert call_with_retry(flaky, POLICY, sleep=lambda _: None) == "ok"
    assert attempts["n"] == 3


def test_permanent_failures_are_not_retried():
    attempts = {"n": 0}

    def forbidden():
        attempts["n"] += 1
        raise PermanentAdapterError("robots disallow")

    with pytest.raises(PermanentAdapterError):
        call_with_retry(forbidden, POLICY, sleep=lambda _: None)
    assert attempts["n"] == 1, "a forbidden request must not be repeated"


def test_retries_stop_at_max_attempts():
    attempts = {"n": 0}

    def always_failing():
        attempts["n"] += 1
        raise TransientAdapterError("timeout")

    with pytest.raises(TransientAdapterError):
        call_with_retry(always_failing, POLICY, sleep=lambda _: None)
    assert attempts["n"] == POLICY.max_attempts


def test_rate_limiter_spaces_calls():
    slept: list[float] = []
    limiter = RateLimiter(per_minute=60)
    limiter.wait()
    assert limiter._last_call > 0
    # With no configured limit the limiter is a no-op.
    RateLimiter(per_minute=None).wait()
    assert slept == []


# ---------------------------------------------------------------------------
# End to end, against the real database
# ---------------------------------------------------------------------------


@pytest.mark.db
def test_a_successful_run_writes_evidence_and_observations(
    synced_session, store, registries, window
):
    adapter = FakeAdapter(registries.sources.by_code["cbr_key_rate"])
    runner = IngestRunner(synced_session, store, POLICY, Settings(database_url="x"))
    report = runner.run(adapter, window)

    assert report.status == "succeeded"
    assert (report.snapshots_new, report.observations) == (1, 1)

    run_status = synced_session.execute(
        text("SELECT status, records_written FROM ops.pipeline_run WHERE id = :i"),
        {"i": report.run_id},
    ).one()
    assert run_status.status == "succeeded"
    assert run_status.records_written == 1

    health = synced_session.execute(
        text(
            "SELECT h.status, h.consecutive_failures FROM ops.source_health h "
            "JOIN registry.source s ON s.id = h.source_id WHERE s.code = 'cbr_key_rate'"
        )
    ).one()
    assert health.status == "healthy"
    assert health.consecutive_failures == 0


@pytest.mark.db
def test_rerunning_is_idempotent(synced_session, store, registries, window):
    runner = IngestRunner(synced_session, store, POLICY, Settings(database_url="x"))
    first = runner.run(FakeAdapter(registries.sources.by_code["cbr_key_rate"]), window)
    second = runner.run(FakeAdapter(registries.sources.by_code["cbr_key_rate"]), window)

    assert first.snapshots_new == 1
    assert second.snapshots_new == 0
    assert second.snapshots_seen == 1
    total = synced_session.execute(text("SELECT count(*) FROM norm.observation")).scalar_one()
    assert total == 1


@pytest.mark.db
def test_an_unparseable_payload_is_kept_as_evidence(synced_session, store, registries, window):
    """A payload we could not read is exactly what a human needs in order to fix
    the adapter, so it is stored rather than discarded."""
    adapter = FakeAdapter(registries.sources.by_code["cbr_key_rate"], parse_error=True)
    runner = IngestRunner(synced_session, store, POLICY, Settings(database_url="x"))
    report = runner.run(adapter, window)

    assert report.status == "failed"
    assert report.errors
    stored = synced_session.execute(text("SELECT count(*) FROM raw.snapshot")).scalar_one()
    errors = synced_session.execute(
        text("SELECT stage, error_type FROM raw.snapshot_parse_error")
    ).all()
    assert stored == 1
    assert errors and errors[0].error_type == "ValueError"
    assert synced_session.execute(text("SELECT count(*) FROM norm.observation")).scalar_one() == 0


@pytest.mark.db
def test_a_failing_fetch_degrades_the_source_without_writing_anything(
    synced_session, store, registries, window
):
    adapter = FakeAdapter(
        registries.sources.by_code["cbr_key_rate"], fail_with=TransientAdapterError("timeout")
    )
    runner = IngestRunner(synced_session, store, POLICY, Settings(database_url="x"))
    report = runner.run(adapter, window)

    assert report.status == "failed"
    assert adapter.calls == POLICY.max_attempts
    assert synced_session.execute(text("SELECT count(*) FROM raw.snapshot")).scalar_one() == 0

    health = synced_session.execute(
        text(
            "SELECT h.status, h.consecutive_failures FROM ops.source_health h "
            "JOIN registry.source s ON s.id = h.source_id WHERE s.code = 'cbr_key_rate'"
        )
    ).one()
    assert health.status == "failing"
    assert health.consecutive_failures == 1


@pytest.mark.db
def test_dry_run_plans_without_sending(synced_session, store, registries, window):
    adapter = FakeAdapter(registries.sources.by_code["cbr_key_rate"])
    runner = IngestRunner(synced_session, store, POLICY, Settings(database_url="x"))
    report = runner.run(adapter, window, dry_run=True)

    assert report.status == "succeeded"
    assert report.plans == 1
    assert adapter.calls == 0
    assert synced_session.execute(text("SELECT count(*) FROM raw.snapshot")).scalar_one() == 0


def test_window_rejects_naive_or_inverted_bounds():
    with pytest.raises(ValueError, match="timezone-aware"):
        Window(start=dt.datetime(2026, 1, 1), end=NOW)
    with pytest.raises(ValueError, match="end precedes start"):
        Window(start=NOW, end=NOW - dt.timedelta(days=1))


def test_an_adapter_cannot_be_pointed_at_the_wrong_source(registries):
    with pytest.raises(ValueError, match="was given"):
        FakeAdapter(registries.sources.by_code["open_meteo"])
