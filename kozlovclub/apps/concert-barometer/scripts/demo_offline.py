#!/usr/bin/env python
"""Run the real pipeline with stored fixtures instead of live requests.

Why this exists
---------------
The four cleared sources need outbound network access. In a sandbox, on a
plane, or behind a corporate proxy, `barometer ingest all` cannot reach them and
you see a stack of connection errors instead of a working system.

This script exercises EVERY production component except the socket: the same
permission check, the same runner, the same provenance writer, the same
database triggers, the same views. Only `fetch` is swapped for a fixture read.

What it does NOT do
-------------------
It does not prove the parsers match the live services, because the fixtures are
synthetic (tests/fixtures/README.md says so at the top). Once you have network,
`barometer ingest all --days 14` is the real test.

Usage:
    python scripts/demo_offline.py [--reset]
"""

from __future__ import annotations

import argparse
import datetime as dt
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "src"))

from sqlalchemy import text  # noqa: E402

from barometer.config.loader import load_registries  # noqa: E402
from barometer.db.session import get_engine, session_scope  # noqa: E402
from barometer.db.sync import sync_registries  # noqa: E402
from barometer.ingest.adapter import FetchOutcome, Window  # noqa: E402
from barometer.ingest.runner import IngestRunner  # noqa: E402
from barometer.settings import get_settings  # noqa: E402
from barometer.sources.cbr_fx import CbrFxAdapter  # noqa: E402
from barometer.sources.cbr_key_rate import CbrKeyRateAdapter  # noqa: E402
from barometer.sources.production_calendar import ProductionCalendarAdapter  # noqa: E402
from barometer.storage import get_snapshot_store  # noqa: E402

FIXTURES = ROOT / "tests" / "fixtures"
NOW = dt.datetime(2026, 9, 21, 9, 0, tzinfo=dt.UTC)
#: Wednesday to Sunday, so the weekend shows what the system does when a source
#: has nothing to publish. That case is more interesting than the easy days.
WINDOW = Window(
    start=dt.datetime(2026, 9, 16, tzinfo=dt.UTC),
    end=dt.datetime(2026, 9, 20, tzinfo=dt.UTC),
)

#: Invented rates, one per business day, so the demo does not look like a stuck
#: series. Synthetic, as everything in tests/fixtures is.
DEMO_RATES = {
    dt.date(2026, 9, 16): ("81,4523", "95,2017"),
    dt.date(2026, 9, 17): ("81,9102", "95,6640"),
    dt.date(2026, 9, 18): ("82,3377", "96,1185"),
}


class FixtureFetcher:
    def __init__(self, payload_for) -> None:
        self.payload_for = payload_for

    def fetch(self, plan):
        return FetchOutcome(
            plan=plan,
            payload=self.payload_for(plan),
            fetched_at=NOW,
            http_status=200,
            content_type="application/xml",
        )

    def close(self) -> None:
        return None


def fx_payload(plan) -> bytes:
    """Behave the way the real service behaves, weekend included.

    Ask CBR for a Saturday and it answers with Friday's rates, with the Date
    attribute set to Friday. The adapter is supposed to notice and record an
    absence rather than dating Friday's rate to Saturday, so the fixture server
    has to reproduce that or the demo would not exercise it.
    """
    requested = dt.datetime.strptime(plan.params["date_req"], "%d/%m/%Y").date()
    answered = requested
    while answered not in DEMO_RATES:
        answered -= dt.timedelta(days=1)
        if answered < min(DEMO_RATES):
            answered = min(DEMO_RATES)
            break

    usd, eur = DEMO_RATES[answered]
    payload = (FIXTURES / "cbr_fx_business_day.xml").read_bytes()
    payload = payload.replace(b"18.09.2026", answered.strftime("%d.%m.%Y").encode())
    payload = payload.replace(b"81,4523", usd.encode())
    payload = payload.replace(b"95,2017", eur.encode())
    return payload


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--reset", action="store_true", help="clear observations and snapshots first"
    )
    args = parser.parse_args()

    settings = get_settings()
    regs = load_registries()

    if args.reset:
        with get_engine().begin() as conn:
            # Append-only triggers block DELETE, so the demo truncates instead.
            conn.execute(
                text(
                    "TRUNCATE norm.observation, norm.event_observation, "
                    "norm.event_diff, raw.snapshot_parse_error, raw.snapshot CASCADE"
                )
            )
        print("cleared previous demo data\n")

    with session_scope() as session:
        sync_registries(session, registries=regs)

    plans = [
        (
            CbrFxAdapter(regs.sources.by_code["cbr_fx"]),
            fx_payload,
        ),
        (
            CbrKeyRateAdapter(regs.sources.by_code["cbr_key_rate"]),
            lambda _: (FIXTURES / "cbr_key_rate.xml").read_bytes(),
        ),
        (
            ProductionCalendarAdapter(regs.sources.by_code["production_calendar"]),
            lambda _: (FIXTURES / "production_calendar_2026.json").read_bytes(),
        ),
    ]

    print(f"window {WINDOW.start.date()} .. {WINDOW.end.date()}, fixtures instead of network\n")
    for adapter, payload_for in plans:
        adapter.bind_fetcher(FixtureFetcher(payload_for))
        with session_scope() as session:
            runner = IngestRunner(
                session, get_snapshot_store(settings), regs.agents.defaults.retry, settings
            )
            report = runner.run(adapter, WINDOW)
        print(
            f"  {report.status:<10} {adapter.source_code:<22} "
            f"snapshots={report.snapshots_new}(+{report.snapshots_seen} seen) "
            f"observations={report.observations}"
        )
        for warning in report.warnings[:3]:
            print(f"             warning: {warning}")

    _report()
    return 0


def _report() -> None:
    with get_engine().connect() as conn:
        print("\n" + "=" * 78)
        print("OBSERVATIONS, WITH THEIR EVIDENCE")
        print("=" * 78)
        rows = conn.execute(
            text(
                """
                SELECT observed_date, series_code, value, missing_reason, unit,
                       assertion_class, quality_flags, source_code,
                       substring(content_hash for 12) AS sha, request_url
                FROM norm.observation_provenance
                WHERE series_code <> 'calendar.is_holiday.ru.daily'
                ORDER BY series_code, observed_date
                """
            )
        ).all()
        for r in rows:
            value = f"{r.value:g}" if r.value is not None else f"absent ({r.missing_reason})"
            flags = f"  [{','.join(r.quality_flags)}]" if r.quality_flags else ""
            print(f"{r.observed_date}  {r.series_code:<26} {value:>14} {r.unit:<18}{flags}")
            print(f"{'':>12}  {r.assertion_class:<10} {r.source_code:<20} sha256={r.sha}")

        print("\n" + "=" * 78)
        print("THE SAME PANEL, AS IT WAS KNOWN AT TWO DIFFERENT MOMENTS")
        print("=" * 78)
        for label, moment in (
            ("before the fetch", NOW - dt.timedelta(hours=1)),
            ("after the fetch ", NOW + dt.timedelta(hours=1)),
        ):
            n = conn.execute(
                text("SELECT count(*) FROM norm.observation_as_of(:t)"), {"t": moment}
            ).scalar_one()
            print(f"  {label}  ({moment.isoformat(timespec='minutes')}):  {n} observations")
        print("\n  A backtest reading through observation_as_of() cannot see a value")
        print("  that had not been published yet. That is principle 8, mechanically.")

        print("\n" + "=" * 78)
        print("CALENDAR, A SAMPLE")
        print("=" * 78)
        cal = conn.execute(
            text(
                """
                SELECT observed_date, value, quality_flags
                FROM norm.observation_provenance
                WHERE series_code = 'calendar.is_holiday.ru.daily'
                  AND (quality_flags <> '{}' OR value = 1)
                  AND observed_date BETWEEN DATE '2026-09-01' AND DATE '2026-11-10'
                ORDER BY observed_date
                LIMIT 14
                """
            )
        ).all()
        for r in cal:
            kind = "нерабочий" if r.value == 1 else "рабочий"
            flags = f"  [{','.join(r.quality_flags)}]" if r.quality_flags else ""
            print(f"  {r.observed_date}  {kind}{flags}")

        print("\n" + "=" * 78)
        print("SOURCE HEALTH")
        print("=" * 78)
        for r in conn.execute(
            text(
                """
                SELECT source_code, enabled, status, freshness_verdict, consecutive_failures
                FROM ops.source_freshness
                WHERE enabled
                ORDER BY source_code
                """
            )
        ).all():
            print(
                f"  {r.source_code:<24} {r.status or '-':<10} "
                f"{r.freshness_verdict or '-':<10} failures={r.consecutive_failures or 0}"
            )

        totals = conn.execute(
            text(
                """
                SELECT (SELECT count(*) FROM raw.snapshot) AS snapshots,
                       (SELECT count(*) FROM norm.observation) AS observations,
                       (SELECT count(*) FROM norm.observation WHERE value IS NULL) AS absences
                """
            )
        ).one()
        print(
            f"\n{totals.observations} observations from {totals.snapshots} snapshots, "
            f"{totals.absences} of them explicit absences.\n"
            "Every one resolves to a stored raw payload and its SHA-256.\n"
            "Fixture data: synthetic values, faithful formats. Not observations of the world."
        )


if __name__ == "__main__":
    raise SystemExit(main())
