"""Adapter parsers, tested against format-faithful fixtures.

What these tests can and cannot prove
-------------------------------------
They prove the parsers handle the documented response shapes, the encodings,
the decimal separators and the traps each source is known for. They do NOT
prove the live services still return those shapes. That is settled on the first
live run, where a mismatch is stored as raw evidence with a parse error
attached rather than silently producing nothing.
"""

from __future__ import annotations

import datetime as dt
from decimal import Decimal
from pathlib import Path

import pytest

from barometer.config.models import AssertionClass
from barometer.ingest.adapter import FetchOutcome, FetchPlan, PermanentAdapterError, Window
from barometer.sources import ADAPTERS, NoAdapterError, build_adapter, missing_adapters
from barometer.sources.base import MOSCOW, moscow_day_bounds
from barometer.sources.cbr_fx import CbrFxAdapter
from barometer.sources.cbr_key_rate import CbrKeyRateAdapter, parse_key_rate_decisions
from barometer.sources.open_meteo import OpenMeteoAdapter, aggregate_evenings
from barometer.sources.production_calendar import ProductionCalendarAdapter, parse_calendar

FIXTURES = Path(__file__).parent / "fixtures"


def fixture(name: str) -> bytes:
    return (FIXTURES / name).read_bytes()


def outcome(adapter, payload: bytes, **plan_kwargs) -> FetchOutcome:
    plan = FetchPlan(url=plan_kwargs.pop("url", "https://example.invalid/x"), **plan_kwargs)
    return FetchOutcome(
        plan=plan,
        payload=payload,
        fetched_at=dt.datetime(2026, 9, 21, 9, 0, tzinfo=dt.UTC),
        http_status=200,
    )


def by_series(records) -> dict[str, list]:
    out: dict[str, list] = {}
    for r in records:
        out.setdefault(r.series_code, []).append(r)
    return out


# ---------------------------------------------------------------------------
# CBR FX
# ---------------------------------------------------------------------------


@pytest.fixture
def fx_adapter(registries) -> CbrFxAdapter:
    return CbrFxAdapter(registries.sources.by_code["cbr_fx"])


def test_fx_reads_windows_1251_and_comma_decimals(fx_adapter):
    result = fx_adapter.parse(
        outcome(fx_adapter, fixture("cbr_fx_business_day.xml"), params={"date_req": "18/09/2026"})
    )
    series = by_series(result.observations)
    assert series["cbr.usd_rub.daily"][0].value == pytest.approx(81.4523)
    assert series["cbr.eur_rub.daily"][0].value == pytest.approx(95.2017)


def test_fx_converts_a_rate_quoted_per_ten_units(fx_adapter):
    """Value 814.5230 with Nominal 10 is 81.4523 per unit, not 814."""
    result = fx_adapter.parse(
        outcome(fx_adapter, fixture("cbr_fx_nominal_ten.xml"), params={"date_req": "18/09/2026"})
    )
    usd = by_series(result.observations)["cbr.usd_rub.daily"][0]
    assert usd.value == pytest.approx(81.4523)
    assert "unit_converted" in usd.quality_flags


def test_fx_refuses_to_store_fridays_rate_against_sunday(fx_adapter):
    """The service answers a weekend request with the previous business day.

    Storing that as Sunday's rate would invent a publication. The adapter
    records an explicit absence instead.
    """
    result = fx_adapter.parse(
        outcome(
            fx_adapter,
            fixture("cbr_fx_business_day.xml"),  # Date attribute says 18.09.2026
            params={"date_req": "20/09/2026"},  # but we asked for the 20th
        )
    )
    for record in result.observations:
        assert record.value is None
        assert record.missing_reason == "non_business_day"
    assert any("not a publication day" in w for w in result.warnings)


def test_fx_observed_period_is_a_moscow_day(fx_adapter):
    result = fx_adapter.parse(
        outcome(fx_adapter, fixture("cbr_fx_business_day.xml"), params={"date_req": "18/09/2026"})
    )
    record = result.observations[0]
    start, end = moscow_day_bounds(dt.date(2026, 9, 18))
    assert record.observed_at == start
    assert record.period_end == end
    assert record.observed_at.tzinfo is not None


def test_fx_plan_refuses_an_unreasonably_wide_window(fx_adapter):
    wide = Window(
        start=dt.datetime(2020, 1, 1, tzinfo=dt.UTC),
        end=dt.datetime(2026, 1, 1, tzinfo=dt.UTC),
    )
    with pytest.raises(PermanentAdapterError, match="Backfill in smaller windows"):
        fx_adapter.plan(wide)


def test_fx_plan_makes_one_request_per_day(fx_adapter):
    window = Window(
        start=dt.datetime(2026, 9, 18, tzinfo=dt.UTC),
        end=dt.datetime(2026, 9, 20, tzinfo=dt.UTC),
    )
    plans = fx_adapter.plan(window)
    assert [p.params["date_req"] for p in plans] == ["18/09/2026", "19/09/2026", "20/09/2026"]


def test_fx_rejects_unparseable_xml(fx_adapter):
    with pytest.raises(PermanentAdapterError, match="unparseable XML"):
        fx_adapter.parse(outcome(fx_adapter, b"<not xml", params={"date_req": "18/09/2026"}))


# ---------------------------------------------------------------------------
# CBR key rate
# ---------------------------------------------------------------------------


@pytest.fixture
def key_rate_adapter(registries) -> CbrKeyRateAdapter:
    return CbrKeyRateAdapter(registries.sources.by_code["cbr_key_rate"])


def test_key_rate_parser_sees_through_the_asmx_wrapper():
    decisions = parse_key_rate_decisions(fixture("cbr_key_rate.xml"))
    assert decisions == [
        (dt.date(2026, 6, 9), Decimal("18.00")),
        (dt.date(2026, 7, 28), Decimal("17.00")),
        (dt.date(2026, 9, 15), Decimal("16.50")),
    ]


def test_key_rate_fills_days_between_decisions_and_flags_them(key_rate_adapter):
    start, _ = moscow_day_bounds(dt.date(2026, 9, 14))
    _, end = moscow_day_bounds(dt.date(2026, 9, 17))
    result = key_rate_adapter.parse(
        outcome(key_rate_adapter, fixture("cbr_key_rate.xml"), period_start=start, period_end=end)
    )
    by_day = {r.observed_at.astimezone(MOSCOW).date(): r for r in result.observations}

    assert by_day[dt.date(2026, 9, 14)].value == pytest.approx(17.0)
    assert "forward_filled" in by_day[dt.date(2026, 9, 14)].quality_flags

    decision_day = by_day[dt.date(2026, 9, 15)]
    assert decision_day.value == pytest.approx(16.5)
    assert decision_day.quality_flags == []
    assert decision_day.published_at is not None, "a decision day has a publication moment"

    assert by_day[dt.date(2026, 9, 17)].value == pytest.approx(16.5)
    assert "forward_filled" in by_day[dt.date(2026, 9, 17)].quality_flags


def test_key_rate_records_absence_before_the_first_known_decision(key_rate_adapter):
    start, _ = moscow_day_bounds(dt.date(2026, 6, 7))
    _, end = moscow_day_bounds(dt.date(2026, 6, 9))
    result = key_rate_adapter.parse(
        outcome(key_rate_adapter, fixture("cbr_key_rate.xml"), period_start=start, period_end=end)
    )
    by_day = {r.observed_at.astimezone(MOSCOW).date(): r for r in result.observations}
    assert by_day[dt.date(2026, 6, 7)].value is None
    assert by_day[dt.date(2026, 6, 7)].missing_reason == "no_prior_decision"
    assert by_day[dt.date(2026, 6, 9)].value == pytest.approx(18.0)


def test_key_rate_refuses_an_implausible_value():
    payload = fixture("cbr_key_rate.xml").replace(b"<Rate>18.00</Rate>", b"<Rate>1800</Rate>")
    with pytest.raises(PermanentAdapterError, match="outside any plausible range"):
        parse_key_rate_decisions(payload)


def test_key_rate_raises_when_no_rows_are_found(key_rate_adapter):
    start, _ = moscow_day_bounds(dt.date(2026, 9, 14))
    _, end = moscow_day_bounds(dt.date(2026, 9, 15))
    with pytest.raises(PermanentAdapterError, match="no <KR> rows"):
        key_rate_adapter.parse(
            outcome(
                key_rate_adapter,
                b"<DataSet></DataSet>",
                period_start=start,
                period_end=end,
            )
        )


def test_key_rate_asks_for_history_before_the_window(key_rate_adapter):
    """Without a lookbehind, a window with no decision in it would be empty."""
    window = Window.last_days(7, now=dt.datetime(2026, 9, 21, tzinfo=dt.UTC))
    plan = key_rate_adapter.plan(window)[0]
    from_date = dt.datetime.strptime(plan.params["fromDate"], "%d/%m/%Y").date()
    assert (window.start.date() - from_date).days >= 365


# ---------------------------------------------------------------------------
# Open-Meteo
# ---------------------------------------------------------------------------


@pytest.fixture
def meteo_adapter(registries) -> OpenMeteoAdapter:
    return OpenMeteoAdapter(registries.sources.by_code["open_meteo"])


def test_evening_aggregation_uses_only_evening_hours():
    days = aggregate_evenings(fixture("open_meteo_archive.json"))
    assert set(days) == {dt.date(2026, 9, 10), dt.date(2026, 9, 11), dt.date(2026, 9, 12)}
    row = days[dt.date(2026, 9, 10)]
    # Precipitation is summed over the evening; the fixture puts 0.4 mm at 19:00
    # and 20:00 on even-indexed days and nothing else after 18:00.
    assert row["precipitation"] == pytest.approx(0.8)
    assert "temperature_2m" in row and "wind_speed_10m" in row


def test_a_day_missing_an_evening_hour_is_absent_not_averaged():
    complete = aggregate_evenings(fixture("open_meteo_archive.json"))
    partial = aggregate_evenings(fixture("open_meteo_archive_partial.json"))
    gap_day = dt.date(2026, 9, 11)
    assert gap_day in complete
    assert gap_day not in partial, "a partial evening was averaged instead of reported absent"


def test_archive_is_a_fact_and_forecast_is_a_forecast(meteo_adapter):
    archive = meteo_adapter.parse(
        outcome(
            meteo_adapter,
            fixture("open_meteo_archive.json"),
            url="https://archive-api.open-meteo.com/v1/archive",
        )
    )
    forecast = meteo_adapter.parse(
        outcome(
            meteo_adapter,
            fixture("open_meteo_forecast.json"),
            url="https://api.open-meteo.com/v1/forecast",
        )
    )
    assert {r.assertion_class for r in archive.observations} == {AssertionClass.FACT}
    assert {r.assertion_class for r in forecast.observations} == {AssertionClass.FORECAST}


def test_forecast_and_archive_never_share_a_series_in_one_payload(meteo_adapter):
    """Both endpoints feed the same series codes, so the class is what separates
    an observation from a model output. A payload must be wholly one or other."""
    archive = meteo_adapter.parse(
        outcome(
            meteo_adapter,
            fixture("open_meteo_archive.json"),
            url="https://archive-api.open-meteo.com/v1/archive",
        )
    )
    per_series = by_series(archive.observations)
    for records in per_series.values():
        assert len({r.assertion_class for r in records}) == 1


def test_meteo_plan_separates_archive_from_forecast(meteo_adapter):
    window = Window.last_days(30, now=dt.datetime(2026, 9, 21, tzinfo=dt.UTC))
    urls = [p.url for p in meteo_adapter.plan(window)]
    assert any("archive" in u for u in urls)
    assert any("/v1/forecast" in u for u in urls)


def test_meteo_rejects_a_truncated_variable_array(meteo_adapter):
    import json

    data = json.loads(fixture("open_meteo_archive.json"))
    data["hourly"]["precipitation"] = data["hourly"]["precipitation"][:-3]
    with pytest.raises(PermanentAdapterError, match=r"against \d+ timestamps"):
        aggregate_evenings(json.dumps(data).encode())


def test_meteo_surfaces_an_api_error_rather_than_parsing_around_it():
    payload = b'{"error": true, "reason": "Parameter start_date is invalid"}'
    with pytest.raises(PermanentAdapterError, match="Parameter start_date is invalid"):
        aggregate_evenings(payload)


# ---------------------------------------------------------------------------
# Production calendar
# ---------------------------------------------------------------------------


@pytest.fixture
def calendar_adapter(registries) -> ProductionCalendarAdapter:
    return ProductionCalendarAdapter(registries.sources.by_code["production_calendar"])


def test_calendar_markers_are_read_correctly():
    year, marked = parse_calendar(fixture("production_calendar_2026.json"))
    assert year == 2026
    assert marked[dt.date(2026, 1, 1)] == ""  # holiday
    assert marked[dt.date(2026, 2, 20)] == "*"  # short working day
    assert marked[dt.date(2026, 9, 19)] == "+"  # transferred working Saturday


def test_calendar_covers_every_day_of_the_year(calendar_adapter):
    result = calendar_adapter.parse(
        outcome(calendar_adapter, fixture("production_calendar_2026.json"))
    )
    assert len(result.observations) == 365
    assert {r.value for r in result.observations} == {0.0, 1.0}


def test_short_and_transferred_days_remain_working_days(calendar_adapter):
    result = calendar_adapter.parse(
        outcome(calendar_adapter, fixture("production_calendar_2026.json"))
    )
    by_day = {r.observed_at.astimezone(MOSCOW).date(): r for r in result.observations}

    short_day = by_day[dt.date(2026, 2, 20)]
    assert short_day.value == 0.0
    assert "short_working_day" in short_day.quality_flags

    working_saturday = by_day[dt.date(2026, 9, 19)]
    assert working_saturday.value == 0.0, "a transferred Saturday is a working day"
    assert "transferred_working_day" in working_saturday.quality_flags

    assert by_day[dt.date(2026, 1, 1)].value == 1.0
    # An unlisted Saturday is still a non-working day.
    assert by_day[dt.date(2026, 9, 12)].value == 1.0


def test_calendar_rejects_a_nonexistent_date():
    payload = b'{"year": 2026, "months": [{"month": 2, "days": "30"}]}'
    with pytest.raises(PermanentAdapterError, match="not a real date"):
        parse_calendar(payload)


def test_calendar_rejects_an_unrecognised_token():
    payload = b'{"year": 2026, "months": [{"month": 1, "days": "1,2?,3"}]}'
    with pytest.raises(PermanentAdapterError, match="unrecognised day token"):
        parse_calendar(payload)


# ---------------------------------------------------------------------------
# Registry
# ---------------------------------------------------------------------------


def test_every_adapter_matches_a_declared_source(registries):
    declared = set(registries.sources.by_code)
    assert set(ADAPTERS) <= declared


def test_every_cleared_source_has_an_adapter_or_is_named_as_missing(registries):
    """A source cleared for access with no adapter is a visible gap, not a silent one."""
    cleared = {s.code for s in registries.sources.sources if s.is_fetchable}
    gaps = cleared - set(ADAPTERS)
    assert gaps <= {"market_research_manual"}, f"unexplained gap: {gaps}"


def test_building_an_adapter_for_an_unimplemented_source_says_so(registries):
    with pytest.raises(NoAdapterError, match="has no adapter yet"):
        build_adapter(registries.sources.by_code["sberindex"])


def test_missing_adapters_lists_the_sources_still_awaiting_one(registries):
    pending = missing_adapters(registries.sources)
    assert "yandex_wordstat" in pending
    assert "cbr_fx" not in pending
