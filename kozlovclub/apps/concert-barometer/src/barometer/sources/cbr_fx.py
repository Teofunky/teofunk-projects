"""Bank of Russia official FX rates.

Endpoint: https://www.cbr.ru/scripts/XML_daily.asp?date_req=DD/MM/YYYY
Response: windows-1251 encoded XML, one <Valute> per currency.

The trap this adapter exists to avoid
-------------------------------------
Rates are published on business days only. Ask for a Sunday and the service
answers with FRIDAY's rates, with the Date attribute set to Friday. Storing
that against Sunday would invent a publication that never happened and would
make a forward fill look like a fresh observation.

So the adapter compares the Date attribute with the date it asked for. On a
mismatch it records an explicit absence (missing_reason='non_business_day')
rather than a stale value. Filling that gap, if a model wants it filled, is the
normalisation layer's decision and is flagged 'forward_filled' there.
"""

from __future__ import annotations

import datetime as dt
from decimal import Decimal, InvalidOperation
from xml.etree import ElementTree

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

ENDPOINT = "https://www.cbr.ru/scripts/XML_daily.asp"

#: Currency char code to the series it feeds.
CURRENCIES = {
    "USD": "cbr.usd_rub.daily",
    "EUR": "cbr.eur_rub.daily",
}

#: Asking for one date per request, so a long window is a lot of requests.
#: Beyond this the adapter refuses and asks for a narrower window rather than
#: quietly hammering the service.
MAX_DAYS_PER_RUN = 120


class CbrFxAdapter(HttpAdapterMixin, SourceAdapter):
    source_code = "cbr_fx"
    collector_version = "1.0.0"
    legal_basis = "official_api"

    def plan(self, window: Window) -> list[FetchPlan]:
        days = _dates_in(window)
        if len(days) > MAX_DAYS_PER_RUN:
            raise PermanentAdapterError(
                f"window covers {len(days)} days, over the {MAX_DAYS_PER_RUN} day "
                "limit for one run. Backfill in smaller windows."
            )
        plans = []
        for day in days:
            start, end = moscow_day_bounds(day)
            plans.append(
                FetchPlan(
                    url=ENDPOINT,
                    params={"date_req": day.strftime("%d/%m/%Y")},
                    period_start=start,
                    period_end=end,
                    label=f"fx {day.isoformat()}",
                )
            )
        return plans

    def parse(self, outcome: FetchOutcome) -> ParseOutcome:
        requested = dt.datetime.strptime(outcome.plan.params["date_req"], "%d/%m/%Y").date()
        start, end = moscow_day_bounds(requested)

        root = _parse_xml(outcome.payload)
        published_for = _published_date(root)

        if published_for != requested:
            # A non-business day. Say so, once per series, rather than storing
            # the previous business day's rate as if it were today's.
            reason = "non_business_day"
            return ParseOutcome(
                observations=[
                    ObservationRecord(
                        series_code=series,
                        observed_at=start,
                        period="day",
                        period_end=end,
                        value=None,
                        missing_reason=reason,
                        unit="rub_per_unit",
                        geography=self.source.geography,
                        assertion_class=AssertionClass.FACT,
                        methodology_notes=(
                            f"ЦБ вернул курсы за {published_for.isoformat()} в ответ на "
                            f"запрос за {requested.isoformat()}: курсы публикуются "
                            "только по рабочим дням."
                        ),
                    )
                    for series in CURRENCIES.values()
                ],
                warnings=[
                    f"{requested.isoformat()} is not a publication day "
                    f"(service answered for {published_for.isoformat()})"
                ],
            )

        observations: list[ObservationRecord] = []
        warnings: list[str] = []
        found = _rates_by_char_code(root)

        for char_code, series in CURRENCIES.items():
            rate = found.get(char_code)
            if rate is None:
                warnings.append(f"{char_code} absent from the response for {requested}")
                observations.append(
                    ObservationRecord(
                        series_code=series,
                        observed_at=start,
                        period="day",
                        period_end=end,
                        value=None,
                        missing_reason="not_in_payload",
                        unit="rub_per_unit",
                        geography=self.source.geography,
                        assertion_class=AssertionClass.FACT,
                    )
                )
                continue

            observations.append(
                ObservationRecord(
                    series_code=series,
                    observed_at=start,
                    period="day",
                    period_end=end,
                    value=float(rate),
                    unit="rub_per_unit",
                    geography=self.source.geography,
                    assertion_class=AssertionClass.FACT,
                    published_at=start,
                    quality_flags=["unit_converted"] if found_nominal(root, char_code) != 1 else [],
                    methodology_notes=(
                        "Официальный курс ЦБ РФ, приведённый к одной единице валюты "
                        "делением Value на Nominal."
                    ),
                )
            )

        return ParseOutcome(observations=observations, warnings=warnings)


# ---------------------------------------------------------------------------
# Parsing helpers, kept separate so they can be tested on a fixture alone
# ---------------------------------------------------------------------------


def _parse_xml(payload: bytes) -> ElementTree.Element:
    try:
        # The declaration says windows-1251; ElementTree honours it from bytes.
        return ElementTree.fromstring(payload)
    except ElementTree.ParseError as exc:
        raise PermanentAdapterError(f"CBR returned unparseable XML: {exc}") from exc


def _published_date(root: ElementTree.Element) -> dt.date:
    raw = root.get("Date")
    if not raw:
        raise PermanentAdapterError("CBR response has no Date attribute")
    try:
        return dt.datetime.strptime(raw, "%d.%m.%Y").date()
    except ValueError as exc:
        raise PermanentAdapterError(f"unexpected Date attribute {raw!r}") from exc


def _rates_by_char_code(root: ElementTree.Element) -> dict[str, Decimal]:
    rates: dict[str, Decimal] = {}
    for valute in root.iter("Valute"):
        code = (valute.findtext("CharCode") or "").strip().upper()
        if code not in CURRENCIES:
            continue
        rates[code] = _rate_per_unit(valute, code)
    return rates


def _rate_per_unit(valute: ElementTree.Element, code: str) -> Decimal:
    raw_value = (valute.findtext("Value") or "").strip()
    raw_nominal = (valute.findtext("Nominal") or "1").strip()
    try:
        # CBR writes decimals with a comma.
        value = Decimal(raw_value.replace(",", ".").replace("\xa0", "").replace(" ", ""))
        nominal = Decimal(raw_nominal.replace(",", "."))
    except InvalidOperation as exc:
        raise PermanentAdapterError(
            f"{code}: could not read Value={raw_value!r} Nominal={raw_nominal!r}"
        ) from exc
    if nominal <= 0:
        raise PermanentAdapterError(f"{code}: Nominal is {nominal}, which cannot be right")
    return value / nominal


def found_nominal(root: ElementTree.Element, code: str) -> int:
    for valute in root.iter("Valute"):
        if (valute.findtext("CharCode") or "").strip().upper() == code:
            try:
                return int((valute.findtext("Nominal") or "1").strip())
            except ValueError:
                return 1
    return 1


def _dates_in(window: Window) -> list[dt.date]:
    start = window.start.date()
    end = window.end.date()
    return [start + dt.timedelta(days=i) for i in range((end - start).days + 1)]
