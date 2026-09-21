"""Shared helpers for concrete adapters."""

from __future__ import annotations

import datetime as dt
from zoneinfo import ZoneInfo

from barometer.ingest.adapter import FetchOutcome, FetchPlan
from barometer.ingest.http import HttpFetcher
from barometer.settings import Settings

MOSCOW = ZoneInfo("Europe/Moscow")

#: Moscow city centre, used for every weather query so the series is stable.
MOSCOW_LAT = 55.7558
MOSCOW_LON = 37.6173

#: The hours an evening out actually happens in. Weather at 09:00 has no bearing
#: on whether someone goes to a concert at 20:00.
EVENING_HOURS = range(18, 24)


def moscow_day_bounds(day: dt.date) -> tuple[dt.datetime, dt.datetime]:
    """Start and end of a Moscow calendar day, as aware UTC-comparable datetimes.

    Observations are stored with the period start in the series' own calendar,
    not in UTC days, because "Tuesday evening in Moscow" is the unit of meaning
    here and it does not line up with a UTC day.
    """
    start = dt.datetime.combine(day, dt.time.min, tzinfo=MOSCOW)
    return start, start + dt.timedelta(days=1)


def moscow_today(now: dt.datetime | None = None) -> dt.date:
    return (now or dt.datetime.now(dt.UTC)).astimezone(MOSCOW).date()


class HttpAdapterMixin:
    """Gives an adapter a shared HTTP client without each one building its own.

    ``fetch`` is provided here so concrete adapters only implement ``plan`` and
    ``parse``, which is where the source-specific knowledge actually lives.
    """

    _fetcher: HttpFetcher | None = None

    def bind_fetcher(self, fetcher: HttpFetcher) -> None:
        self._fetcher = fetcher

    @property
    def fetcher(self) -> HttpFetcher:
        if self._fetcher is None:
            self._fetcher = HttpFetcher.build()
        return self._fetcher

    def fetch(self, plan: FetchPlan) -> FetchOutcome:
        return self.fetcher.fetch(plan)

    def close(self) -> None:
        if self._fetcher is not None:
            self._fetcher.close()
            self._fetcher = None


def build_fetcher(settings: Settings | None = None) -> HttpFetcher:
    return HttpFetcher.build(settings)
