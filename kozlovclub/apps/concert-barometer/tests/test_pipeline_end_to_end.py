"""The whole slice, through the real runner, the real writer and the real database.

Only the network is replaced: each adapter's ``fetch`` returns a stored fixture
instead of calling the live service. Everything after that point is production
code, including the permission check, the snapshot write, the assertion-class
triggers and the provenance view.
"""

from __future__ import annotations

import datetime as dt

import pytest
from sqlalchemy import text

from barometer.ingest.adapter import FetchOutcome, Window
from barometer.ingest.runner import IngestRunner
from barometer.settings import Settings
from barometer.sources.cbr_fx import CbrFxAdapter
from barometer.sources.cbr_key_rate import CbrKeyRateAdapter
from barometer.sources.production_calendar import ProductionCalendarAdapter
from tests.test_adapters import fixture
from tests.test_ingest import POLICY

pytestmark = pytest.mark.db

NOW = dt.datetime(2026, 9, 21, 9, 0, tzinfo=dt.UTC)
WINDOW = Window(
    start=dt.datetime(2026, 9, 16, tzinfo=dt.UTC),
    end=dt.datetime(2026, 9, 18, tzinfo=dt.UTC),
)


class ReplayFetcher:
    """Serves a fixture in place of a live request, and counts the calls."""

    def __init__(self, payload_for) -> None:
        self.payload_for = payload_for
        self.calls: list[str] = []

    def fetch(self, plan):
        self.calls.append(plan.label or plan.url or "")
        return FetchOutcome(
            plan=plan,
            payload=self.payload_for(plan),
            fetched_at=NOW,
            http_status=200,
            content_type="application/xml",
        )

    def close(self) -> None:  # pragma: no cover - interface parity
        return None


def replay(adapter, payload_for):
    adapter.bind_fetcher(ReplayFetcher(payload_for))
    return adapter


@pytest.fixture
def runner(synced_session, store):
    return IngestRunner(synced_session, store, POLICY, Settings(database_url="x"))


def test_fx_run_lands_three_days_with_evidence(runner, synced_session, registries):
    """Two business days and one weekend, and the weekend is an explicit absence."""
    fx_payloads = {
        "16/09/2026": fixture("cbr_fx_business_day.xml").replace(b"18.09.2026", b"16.09.2026"),
        "17/09/2026": fixture("cbr_fx_business_day.xml").replace(b"18.09.2026", b"17.09.2026"),
        "18/09/2026": fixture("cbr_fx_business_day.xml"),
    }
    adapter = replay(
        CbrFxAdapter(registries.sources.by_code["cbr_fx"]),
        lambda plan: fx_payloads[plan.params["date_req"]],
    )

    report = runner.run(adapter, WINDOW)

    assert report.status == "succeeded"
    assert report.snapshots_new == 3
    assert report.observations == 6  # two currencies over three days

    rows = synced_session.execute(
        text(
            """
            SELECT series_code, observed_date AS day, value, assertion_class,
                   source_code, content_hash, request_url
            FROM norm.observation_provenance
            WHERE series_code = 'cbr.usd_rub.daily'
            ORDER BY observed_at
            """
        )
    ).all()
    assert [r.day for r in rows] == [
        dt.date(2026, 9, 16),
        dt.date(2026, 9, 17),
        dt.date(2026, 9, 18),
    ]
    assert all(r.value == pytest.approx(81.4523) for r in rows)
    assert all(r.assertion_class == "fact" for r in rows)
    # The point of the whole exercise: every number resolves to its evidence.
    assert all(len(r.content_hash) == 64 for r in rows)
    assert all(r.request_url.startswith("https://www.cbr.ru/") for r in rows)


def test_three_sources_in_one_window_share_a_coherent_panel(runner, synced_session, registries):
    replay(
        CbrFxAdapter(registries.sources.by_code["cbr_fx"]),
        lambda plan: fixture("cbr_fx_business_day.xml").replace(
            b"18.09.2026", plan.params["date_req"].replace("/", ".").encode()
        ),
    )
    runs = [
        replay(
            CbrFxAdapter(registries.sources.by_code["cbr_fx"]),
            lambda plan: fixture("cbr_fx_business_day.xml").replace(
                b"18.09.2026", plan.params["date_req"].replace("/", ".").encode()
            ),
        ),
        replay(
            CbrKeyRateAdapter(registries.sources.by_code["cbr_key_rate"]),
            lambda _: fixture("cbr_key_rate.xml"),
        ),
        replay(
            ProductionCalendarAdapter(registries.sources.by_code["production_calendar"]),
            lambda _: fixture("production_calendar_2026.json"),
        ),
    ]
    for adapter in runs:
        report = runner.run(adapter, WINDOW)
        assert report.status == "succeeded", f"{adapter.source_code}: {report.errors}"

    per_source = dict(
        synced_session.execute(
            text(
                """
                SELECT src.code, count(*)
                FROM norm.observation o
                JOIN registry.series s ON s.id = o.series_id
                JOIN registry.source src ON src.id = s.source_id
                GROUP BY src.code
                """
            )
        ).all()
    )
    assert per_source["cbr_fx"] == 6
    assert per_source["cbr_key_rate"] == 3
    assert per_source["production_calendar"] == 365


def test_a_second_identical_run_writes_nothing_new(runner, synced_session, registries):
    def build():
        return replay(
            ProductionCalendarAdapter(registries.sources.by_code["production_calendar"]),
            lambda _: fixture("production_calendar_2026.json"),
        )

    first = runner.run(build(), WINDOW)
    second = runner.run(build(), WINDOW)

    assert first.snapshots_new == 1
    assert (second.snapshots_new, second.snapshots_seen) == (0, 1)
    assert second.observations == 0
    total = synced_session.execute(text("SELECT count(*) FROM norm.observation")).scalar_one()
    assert total == 365


def test_a_revised_payload_creates_a_second_vintage_not_an_overwrite(
    runner, synced_session, registries
):
    """The key rate is restated. Yesterday's belief must survive intact."""
    original = fixture("cbr_key_rate.xml")
    revised = original.replace(b"<Rate>16.50</Rate>", b"<Rate>16.75</Rate>")

    day_one = replay(
        CbrKeyRateAdapter(registries.sources.by_code["cbr_key_rate"]),
        lambda _: original,
    )
    day_one.bind_fetcher(ReplayFetcher(lambda _: original))
    runner.run(day_one, WINDOW)

    later = CbrKeyRateAdapter(registries.sources.by_code["cbr_key_rate"])
    fetcher = ReplayFetcher(lambda _: revised)
    later.bind_fetcher(fetcher)
    # A later fetch time makes this a new vintage rather than the same one.
    original_fetch = fetcher.fetch

    def fetch_later(plan):
        result = original_fetch(plan)
        result.fetched_at = NOW + dt.timedelta(days=1)
        return result

    fetcher.fetch = fetch_later  # type: ignore[method-assign]
    runner.run(later, WINDOW)

    vintages = synced_session.execute(
        text(
            """
            SELECT value, vintage_at FROM norm.observation o
            JOIN registry.series s ON s.id = o.series_id
            WHERE s.code = 'cbr.key_rate.daily' AND o.observed_date = DATE '2026-09-17'
            ORDER BY vintage_at
            """
        )
    ).all()
    assert [round(v.value, 2) for v in vintages] == [16.5, 16.75]

    as_of_first_day = synced_session.execute(
        text(
            """
            SELECT value FROM norm.observation_as_of(:t) o
            JOIN registry.series s ON s.id = o.series_id
            WHERE s.code = 'cbr.key_rate.daily' AND o.observed_date = DATE '2026-09-17'
            """
        ),
        {"t": NOW + dt.timedelta(hours=1)},
    ).scalar_one()
    assert as_of_first_day == pytest.approx(16.5), "the restatement leaked backwards in time"


def test_weekend_absence_is_stored_with_its_reason(runner, synced_session, registries):
    weekend = Window(
        start=dt.datetime(2026, 9, 20, tzinfo=dt.UTC),
        end=dt.datetime(2026, 9, 20, tzinfo=dt.UTC),
    )
    adapter = replay(
        CbrFxAdapter(registries.sources.by_code["cbr_fx"]),
        lambda _: fixture("cbr_fx_business_day.xml"),  # answers for the 18th
    )
    report = runner.run(adapter, weekend)

    assert report.status == "succeeded"
    rows = synced_session.execute(
        text(
            """
            SELECT value, missing_reason FROM norm.observation o
            JOIN registry.series s ON s.id = o.series_id
            WHERE s.code LIKE 'cbr.%_rub.daily'
            """
        )
    ).all()
    assert rows
    assert all(r.value is None and r.missing_reason == "non_business_day" for r in rows)


def test_source_health_reflects_the_run(runner, synced_session, registries):
    adapter = replay(
        ProductionCalendarAdapter(registries.sources.by_code["production_calendar"]),
        lambda _: fixture("production_calendar_2026.json"),
    )
    runner.run(adapter, WINDOW)

    row = synced_session.execute(
        text(
            """
            SELECT status, freshness_verdict, consecutive_failures
            FROM ops.source_freshness WHERE source_code = 'production_calendar'
            """
        )
    ).one()
    assert row.status == "healthy"
    assert row.consecutive_failures == 0
