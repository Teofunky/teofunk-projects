"""Bank of Russia key rate.

Endpoint: the DailyInfoWebServ HTTP GET binding,
https://www.cbr.ru/DailyInfoWebServ/DailyInfo.asmx/KeyRate
with fromDate and ToDate as dd/mm/yyyy.

The response is an ASMX DataSet wrapper around repeated <KR> elements, each
holding a <DT> date and a <Rate>. Wrapper shapes from ASMX services vary with
server configuration, so the parser looks for KR elements anywhere in the tree
and ignores namespaces, rather than depending on an exact path. If the shape
turns out to differ on first live run, the runner stores the raw response as
evidence and records a parse error against it, so the fix is one look away.

The rate is a step series: it changes only on Board decision days. The service
reports one row per decision, not one per day, so the adapter emits a value for
every day in the window carrying the rate in force on that day, flagged
'forward_filled' for every day that is not itself a decision day. The flag is
what keeps a filled day distinguishable from a published one.
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

ENDPOINT = "https://www.cbr.ru/DailyInfoWebServ/DailyInfo.asmx/KeyRate"
SERIES = "cbr.key_rate.daily"

#: Ask for extra history before the window so the rate in force on the first
#: requested day is known even when the last decision predates the window.
LOOKBEHIND_DAYS = 400


class CbrKeyRateAdapter(HttpAdapterMixin, SourceAdapter):
    source_code = "cbr_key_rate"
    collector_version = "1.0.0"
    legal_basis = "official_api"

    def plan(self, window: Window) -> list[FetchPlan]:
        start = window.start.date() - dt.timedelta(days=LOOKBEHIND_DAYS)
        end = window.end.date()
        period_start, _ = moscow_day_bounds(window.start.date())
        _, period_end = moscow_day_bounds(end)
        return [
            FetchPlan(
                url=ENDPOINT,
                params={
                    "fromDate": start.strftime("%d/%m/%Y"),
                    "ToDate": end.strftime("%d/%m/%Y"),
                },
                period_start=period_start,
                period_end=period_end,
                label=f"key rate {window.start.date()}..{end}",
            )
        ]

    def parse(self, outcome: FetchOutcome) -> ParseOutcome:
        decisions = parse_key_rate_decisions(outcome.payload)
        if not decisions:
            raise PermanentAdapterError(
                "no <KR> rows found in the key rate response; the service shape may have changed"
            )

        window_start = outcome.plan.period_start
        window_end = outcome.plan.period_end
        if window_start is None or window_end is None:  # pragma: no cover - set in plan()
            raise PermanentAdapterError("key rate plan is missing its period bounds")

        first_day = window_start.date()
        last_day = (window_end - dt.timedelta(seconds=1)).date()

        observations: list[ObservationRecord] = []
        warnings: list[str] = []
        decision_days = {d for d, _ in decisions}

        day = first_day
        while day <= last_day:
            start, end = moscow_day_bounds(day)
            rate = _rate_in_force(decisions, day)
            if rate is None:
                warnings.append(f"no key rate known on or before {day.isoformat()}")
                observations.append(
                    ObservationRecord(
                        series_code=SERIES,
                        observed_at=start,
                        period="day",
                        period_end=end,
                        value=None,
                        missing_reason="no_prior_decision",
                        unit="percent_per_annum",
                        geography=self.source.geography,
                        assertion_class=AssertionClass.FACT,
                    )
                )
            else:
                is_decision_day = day in decision_days
                observations.append(
                    ObservationRecord(
                        series_code=SERIES,
                        observed_at=start,
                        period="day",
                        period_end=end,
                        value=float(rate),
                        unit="percent_per_annum",
                        geography=self.source.geography,
                        assertion_class=AssertionClass.FACT,
                        published_at=start if is_decision_day else None,
                        quality_flags=[] if is_decision_day else ["forward_filled"],
                        methodology_notes=(
                            "Ставка, действовавшая в этот день. Дни без решения "
                            "заполнены последним объявленным значением и помечены "
                            "флагом forward_filled."
                        ),
                    )
                )
            day += dt.timedelta(days=1)

        return ParseOutcome(observations=observations, warnings=warnings)


def parse_key_rate_decisions(payload: bytes) -> list[tuple[dt.date, Decimal]]:
    """Extract (date, rate) pairs, tolerant of the ASMX wrapper shape.

    Namespaces are stripped and <KR> elements are found at any depth, so a
    DataSet or diffgram wrapper does not break the parse.
    """
    try:
        root = ElementTree.fromstring(payload)
    except ElementTree.ParseError as exc:
        raise PermanentAdapterError(f"key rate response is not XML: {exc}") from exc

    decisions: list[tuple[dt.date, Decimal]] = []
    for element in root.iter():
        if _local_name(element.tag) != "KR":
            continue
        raw_date = _child_text(element, "DT")
        raw_rate = _child_text(element, "Rate")
        if raw_date is None or raw_rate is None:
            continue
        decisions.append((_parse_date(raw_date), _parse_rate(raw_rate)))

    decisions.sort(key=lambda pair: pair[0])
    return decisions


def _rate_in_force(decisions: list[tuple[dt.date, Decimal]], day: dt.date) -> Decimal | None:
    current: Decimal | None = None
    for decided_on, rate in decisions:
        if decided_on <= day:
            current = rate
        else:
            break
    return current


def _local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1] if "}" in tag else tag


def _child_text(element: ElementTree.Element, name: str) -> str | None:
    for child in element:
        if _local_name(child.tag) == name and child.text:
            return child.text.strip()
    return None


def _parse_date(raw: str) -> dt.date:
    # The service returns an ISO timestamp with an offset; older responses have
    # used a bare date. Accept both rather than depending on one.
    cleaned = raw.strip()
    for parse in (
        lambda s: dt.datetime.fromisoformat(s).date(),
        lambda s: dt.datetime.strptime(s, "%Y-%m-%d").date(),
        lambda s: dt.datetime.strptime(s, "%d.%m.%Y").date(),
    ):
        try:
            return parse(cleaned)
        except ValueError:
            continue
    raise PermanentAdapterError(f"unrecognised key rate date {raw!r}")


def _parse_rate(raw: str) -> Decimal:
    try:
        value = Decimal(raw.strip().replace(",", "."))
    except InvalidOperation as exc:
        raise PermanentAdapterError(f"unrecognised key rate value {raw!r}") from exc
    if not (Decimal(0) < value < Decimal(100)):
        raise PermanentAdapterError(
            f"key rate {value} is outside any plausible range; refusing to store it"
        )
    return value
