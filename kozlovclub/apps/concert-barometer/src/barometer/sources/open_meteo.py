"""Open-Meteo: evening weather in Moscow.

Two endpoints, deliberately never mixed in one series (ADR 0003 and the source's
methodology note):

* https://archive-api.open-meteo.com/v1/archive  -> ERA5 reanalysis, a FACT,
  available with roughly five days of lag.
* https://api.open-meteo.com/v1/forecast          -> model output, a FORECAST.

Both are aggregated to the evening window (18:00 to 23:59 Moscow), because
weather at 09:00 has no bearing on whether somebody goes out at 20:00. A day
whose evening hours are not fully present is recorded as absent with
missing_reason='partial_period' rather than averaged over whatever arrived.

Why there is no friction index here
-----------------------------------
An earlier draft had this adapter emit a composite "weather friction" number
built from conventional thresholds (5 mm of rain, 10 m/s of wind). Those are
plausible, and they are still hand-picked coefficients entering the index,
which is what principle 2 exists to prevent. The three raw components are
published instead and the model estimates their effect on demand from history.
See ADR 0010.
"""

from __future__ import annotations

import datetime as dt
import json
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
from barometer.sources.base import (
    EVENING_HOURS,
    MOSCOW,
    MOSCOW_LAT,
    MOSCOW_LON,
    HttpAdapterMixin,
    moscow_day_bounds,
    moscow_today,
)

ARCHIVE_ENDPOINT = "https://archive-api.open-meteo.com/v1/archive"
FORECAST_ENDPOINT = "https://api.open-meteo.com/v1/forecast"

#: Open-Meteo variable -> (series code, unit, how to aggregate the evening hours)
VARIABLES = {
    "temperature_2m": ("weather.temp_evening.msk.daily", "celsius", "mean"),
    "precipitation": ("weather.precip_evening.msk.daily", "mm", "sum"),
    "wind_speed_10m": ("weather.wind_evening.msk.daily", "m_per_s", "mean"),
}

#: ERA5 reanalysis lags real time. Anything more recent than this is not yet a
#: fact and must be read from the forecast endpoint instead.
ARCHIVE_LAG_DAYS = 5

MAX_FORECAST_DAYS = 16


class OpenMeteoAdapter(HttpAdapterMixin, SourceAdapter):
    source_code = "open_meteo"
    collector_version = "1.0.0"
    legal_basis = "official_api"

    def plan(self, window: Window) -> list[FetchPlan]:
        today = moscow_today()
        archive_end = min(window.end.date(), today - dt.timedelta(days=ARCHIVE_LAG_DAYS))
        plans: list[FetchPlan] = []

        if archive_end >= window.start.date():
            start, _ = moscow_day_bounds(window.start.date())
            _, end = moscow_day_bounds(archive_end)
            plans.append(
                FetchPlan(
                    url=ARCHIVE_ENDPOINT,
                    params={
                        "latitude": MOSCOW_LAT,
                        "longitude": MOSCOW_LON,
                        "start_date": window.start.date().isoformat(),
                        "end_date": archive_end.isoformat(),
                        "hourly": ",".join(VARIABLES),
                        "timezone": "Europe/Moscow",
                    },
                    period_start=start,
                    period_end=end,
                    label=f"archive {window.start.date()}..{archive_end}",
                )
            )

        forecast_days = min(MAX_FORECAST_DAYS, max(1, (window.end.date() - today).days + 1))
        start, _ = moscow_day_bounds(today)
        _, end = moscow_day_bounds(today + dt.timedelta(days=forecast_days - 1))
        plans.append(
            FetchPlan(
                url=FORECAST_ENDPOINT,
                params={
                    "latitude": MOSCOW_LAT,
                    "longitude": MOSCOW_LON,
                    "hourly": ",".join(VARIABLES),
                    "forecast_days": forecast_days,
                    "timezone": "Europe/Moscow",
                },
                period_start=start,
                period_end=end,
                label=f"forecast {forecast_days}d",
            )
        )
        return plans

    def parse(self, outcome: FetchOutcome) -> ParseOutcome:
        is_archive = (outcome.plan.url or "").startswith(ARCHIVE_ENDPOINT)
        assertion = AssertionClass.FACT if is_archive else AssertionClass.FORECAST
        note = (
            "Реанализ ERA5: наблюдение, агрегированное по вечерним часам 18:00-23:59 "
            "по московскому времени."
            if is_archive
            else "Вывод прогностической модели Open-Meteo, а не наблюдение. "
            "Агрегировано по вечерним часам 18:00-23:59 по московскому времени."
        )

        by_day = aggregate_evenings(outcome.payload)
        observations: list[ObservationRecord] = []
        warnings: list[str] = []

        for day in sorted(by_day):
            start, end = moscow_day_bounds(day)
            values = by_day[day]
            for variable, (series, unit, _) in VARIABLES.items():
                value = values.get(variable)
                if value is None:
                    observations.append(
                        ObservationRecord(
                            series_code=series,
                            observed_at=start,
                            period="day",
                            period_end=end,
                            value=None,
                            missing_reason="partial_period",
                            unit=unit,
                            geography=self.source.geography,
                            assertion_class=assertion,
                            methodology_notes=note,
                        )
                    )
                    continue
                observations.append(
                    ObservationRecord(
                        series_code=series,
                        observed_at=start,
                        period="day",
                        period_end=end,
                        value=value,
                        unit=unit,
                        geography=self.source.geography,
                        assertion_class=assertion,
                        methodology_notes=note,
                    )
                )

        if not observations:
            warnings.append("response contained no complete evening for any day")
        return ParseOutcome(observations=observations, warnings=warnings)


def aggregate_evenings(payload: bytes) -> dict[dt.date, dict[str, float]]:
    """Collapse hourly readings into one evening figure per day per variable.

    A day is only reported when EVERY evening hour is present for that variable.
    A partial evening is an absence, not an average of what happened to arrive.
    """
    data = _load(payload)
    hourly = data.get("hourly")
    if not isinstance(hourly, dict):
        raise PermanentAdapterError("Open-Meteo response has no 'hourly' block")

    times = hourly.get("time")
    if not isinstance(times, list) or not times:
        raise PermanentAdapterError("Open-Meteo response has no 'hourly.time' array")

    parsed_times = [_parse_local_time(t) for t in times]
    buckets: dict[dt.date, dict[str, list[float]]] = {}

    for variable in VARIABLES:
        series = hourly.get(variable)
        if not isinstance(series, list):
            raise PermanentAdapterError(f"Open-Meteo response is missing '{variable}'")
        if len(series) != len(times):
            raise PermanentAdapterError(
                f"'{variable}' has {len(series)} points against {len(times)} timestamps"
            )
        for moment, raw in zip(parsed_times, series, strict=True):
            if moment.hour not in EVENING_HOURS:
                continue
            if raw is None:
                continue
            buckets.setdefault(moment.date(), {}).setdefault(variable, []).append(float(raw))

    expected = len(EVENING_HOURS)
    result: dict[dt.date, dict[str, float]] = {}
    for day, per_variable in buckets.items():
        row: dict[str, float] = {}
        for variable, (_, _, how) in VARIABLES.items():
            values = per_variable.get(variable, [])
            if len(values) < expected:
                continue  # left absent on purpose; see the docstring
            row[variable] = sum(values) if how == "sum" else sum(values) / len(values)
        if row:
            result[day] = row
    return result


def _load(payload: bytes) -> dict[str, Any]:
    try:
        data = json.loads(payload)
    except json.JSONDecodeError as exc:
        raise PermanentAdapterError(f"Open-Meteo response is not JSON: {exc}") from exc
    if not isinstance(data, dict):
        raise PermanentAdapterError("Open-Meteo response is not a JSON object")
    if "error" in data:
        raise PermanentAdapterError(f"Open-Meteo reported an error: {data.get('reason')}")
    return data


def _parse_local_time(raw: str) -> dt.datetime:
    try:
        return dt.datetime.fromisoformat(raw).replace(tzinfo=MOSCOW)
    except ValueError as exc:
        raise PermanentAdapterError(f"unrecognised timestamp {raw!r}") from exc
