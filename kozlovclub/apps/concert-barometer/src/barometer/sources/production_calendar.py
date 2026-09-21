"""Russian production calendar: holidays, short days and moved working days.

Endpoint: https://xmlcalendar.ru/data/ru/{year}/calendar.json

Payload shape:
    {"year": 2026, "months": [{"month": 1, "days": "1,2,3,4,5,6,7,8,9*,..."}]}

Markers inside the day list:
    (none)  non-working day
    *       working day, shortened before a holiday
    +       working day that falls on a weekend (a transferred day)

Calendar facts are the one family known in advance, which is why
feature.feature_definition.calendar_exempt exists. That exemption is granted to
the feature, not claimed here: this adapter only records what the calendar says.
"""

from __future__ import annotations

import calendar as pycalendar
import datetime as dt
import json
import re
from typing import Any

from barometer.config.models import AssertionClass
from barometer.ingest.adapter import (
    FetchOutcome,
    FetchPlan,
    ParseOutcome,
    PermanentAdapterError,
    SourceAdapter,
    Window,
)
from barometer.provenance.record import ObservationRecord
from barometer.sources.base import HttpAdapterMixin, moscow_day_bounds

ENDPOINT = "https://xmlcalendar.ru/data/ru/{year}/calendar.json"
SERIES = "calendar.is_holiday.ru.daily"

DAY_PATTERN = re.compile(r"^(\d{1,2})([*+]?)$")

SHORT_DAY = "*"
TRANSFERRED_WORKING_DAY = "+"


class ProductionCalendarAdapter(HttpAdapterMixin, SourceAdapter):
    source_code = "production_calendar"
    collector_version = "1.0.0"
    legal_basis = "official_api"

    def plan(self, window: Window) -> list[FetchPlan]:
        years = sorted({window.start.year, window.end.year})
        plans = []
        for year in years:
            start = dt.datetime(year, 1, 1, tzinfo=dt.UTC)
            end = dt.datetime(year + 1, 1, 1, tzinfo=dt.UTC)
            plans.append(
                FetchPlan(
                    url=ENDPOINT.format(year=year),
                    period_start=start,
                    period_end=end,
                    label=f"calendar {year}",
                )
            )
        return plans

    def parse(self, outcome: FetchOutcome) -> ParseOutcome:
        year, marked = parse_calendar(outcome.payload)
        observations: list[ObservationRecord] = []

        for day in _every_day_of(year):
            marker = marked.get(day)
            is_non_working = _is_non_working(day, marker)
            start, end = moscow_day_bounds(day)

            flags: list[str] = []
            if marker == SHORT_DAY:
                flags.append("short_working_day")
            elif marker == TRANSFERRED_WORKING_DAY:
                flags.append("transferred_working_day")

            observations.append(
                ObservationRecord(
                    series_code=SERIES,
                    observed_at=start,
                    period="day",
                    period_end=end,
                    value=1.0 if is_non_working else 0.0,
                    unit="boolean",
                    geography="RU",
                    assertion_class=AssertionClass.FACT,
                    quality_flags=flags,
                    methodology_notes=(
                        "1 = нерабочий день по производственному календарю РФ. "
                        "Сокращённые предпраздничные дни и перенесённые рабочие дни "
                        "остаются рабочими и помечены флагом качества."
                    ),
                )
            )

        return ParseOutcome(observations=observations)


def parse_calendar(payload: bytes) -> tuple[int, dict[dt.date, str]]:
    """Return the year and a map of explicitly marked days to their marker.

    Days absent from the map are ordinary: working on weekdays, non-working at
    weekends. That default is applied in _is_non_working, not here, so this
    function reports only what the source actually stated.
    """
    try:
        data: Any = json.loads(payload)
    except json.JSONDecodeError as exc:
        raise PermanentAdapterError(f"calendar response is not JSON: {exc}") from exc
    if not isinstance(data, dict):
        raise PermanentAdapterError("calendar response is not a JSON object")

    year = data.get("year")
    if not isinstance(year, int):
        raise PermanentAdapterError(f"calendar has no usable 'year': {year!r}")

    months = data.get("months")
    if not isinstance(months, list) or not months:
        raise PermanentAdapterError("calendar has no 'months' array")

    marked: dict[dt.date, str] = {}
    for entry in months:
        if not isinstance(entry, dict):
            raise PermanentAdapterError("a month entry is not an object")
        month = entry.get("month")
        raw_days = entry.get("days", "")
        if not isinstance(month, int) or not 1 <= month <= 12:
            raise PermanentAdapterError(f"unusable month number {month!r}")
        if not isinstance(raw_days, str):
            raise PermanentAdapterError(f"month {month}: 'days' is not a string")

        for token in (t.strip() for t in raw_days.split(",")):
            if not token:
                continue
            match = DAY_PATTERN.match(token)
            if not match:
                raise PermanentAdapterError(f"month {month}: unrecognised day token {token!r}")
            day_number, marker = int(match.group(1)), match.group(2)
            try:
                marked[dt.date(year, month, day_number)] = marker
            except ValueError as exc:
                raise PermanentAdapterError(
                    f"calendar lists {day_number}.{month}.{year}, which is not a real date"
                ) from exc

    if not marked:
        raise PermanentAdapterError("calendar listed no days at all")
    return year, marked


def _is_non_working(day: dt.date, marker: str | None) -> bool:
    if marker == TRANSFERRED_WORKING_DAY:
        return False  # a working Saturday
    if marker == SHORT_DAY:
        return False  # shortened, but still a working day
    if marker == "":
        return True  # explicitly listed without a marker: a holiday
    return day.weekday() >= 5  # unlisted: ordinary weekend or ordinary weekday


def _every_day_of(year: int) -> list[dt.date]:
    days: list[dt.date] = []
    for month in range(1, 13):
        _, length = pycalendar.monthrange(year, month)
        days.extend(dt.date(year, month, d) for d in range(1, length + 1))
    return days
