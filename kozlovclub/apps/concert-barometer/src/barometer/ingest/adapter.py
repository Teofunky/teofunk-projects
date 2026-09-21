"""The contract every source adapter implements.

An adapter does two things and nothing else: it FETCHES bytes and it PARSES
them into records. It does not touch the database, does not decide whether it
is allowed to run, and does not invent values for missing periods. Those are
the runner's job, which is what keeps provenance and legality in one place.

Writing a new adapter
---------------------
    class MyAdapter(SourceAdapter):
        source_code = "my_source"
        collector_version = "1.0.0"

        def plan(self, window): -> list[FetchPlan]
        def fetch(self, plan): -> FetchOutcome
        def parse(self, outcome): -> ParseOutcome

Anything the source cannot supply is returned as an ObservationRecord with
value=None and a missing_reason, never omitted silently.
"""

from __future__ import annotations

import datetime as dt
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any

from barometer.config.models import SourceDef
from barometer.provenance.record import (
    EventRecord,
    FetchEnvelope,
    LegalBasis,
    ObservationRecord,
)


@dataclass(frozen=True, slots=True)
class Window:
    """The period an ingest run is asked to cover."""

    start: dt.datetime
    end: dt.datetime

    def __post_init__(self) -> None:
        if self.start.tzinfo is None or self.end.tzinfo is None:
            raise ValueError("Window bounds must be timezone-aware")
        if self.end < self.start:
            raise ValueError("Window end precedes start")

    @classmethod
    def last_days(cls, days: int, now: dt.datetime | None = None) -> Window:
        end = now or dt.datetime.now(dt.UTC)
        return cls(start=end - dt.timedelta(days=days), end=end)


@dataclass(frozen=True, slots=True)
class FetchPlan:
    """One intended request. Produced before anything is sent, so it can be
    logged, rate-limited, robots-checked and dry-run."""

    url: str | None
    method: str = "GET"
    params: dict[str, Any] = field(default_factory=dict)
    headers: dict[str, str] = field(default_factory=dict)
    venue_code: str | None = None
    period_start: dt.datetime | None = None
    period_end: dt.datetime | None = None
    label: str = ""


@dataclass(slots=True)
class FetchOutcome:
    """What came back. ``payload`` is the exact bytes that will be hashed."""

    plan: FetchPlan
    payload: bytes
    fetched_at: dt.datetime
    http_status: int | None = None
    content_type: str | None = None
    notes: str | None = None


@dataclass(slots=True)
class ParseOutcome:
    observations: list[ObservationRecord] = field(default_factory=list)
    events: list[EventRecord] = field(default_factory=list)
    #: Problems that did not stop the parse but that a human should see.
    warnings: list[str] = field(default_factory=list)


class AdapterError(RuntimeError):
    """Base class for adapter failures."""


class TransientAdapterError(AdapterError):
    """Worth retrying: timeout, 5xx, rate limit."""


class PermanentAdapterError(AdapterError):
    """Not worth retrying: auth failure, 404, contract change, robots disallow."""


class SourceAdapter(ABC):
    """Base class for every source adapter."""

    #: Must match a `code` in config/sources.yaml.
    source_code: str
    #: Bumped whenever parsing changes, so old snapshots stay attributable to
    #: the code that produced their observations.
    collector_version: str = "0.1.0"
    legal_basis: LegalBasis = "official_api"

    def __init__(self, source: SourceDef) -> None:
        if source.code != self.source_code:
            raise ValueError(
                f"{type(self).__name__} is for source '{self.source_code}' "
                f"but was given '{source.code}'"
            )
        self.source = source

    @abstractmethod
    def plan(self, window: Window) -> list[FetchPlan]:
        """Decide what to request for this window. May be empty."""

    @abstractmethod
    def fetch(self, plan: FetchPlan) -> FetchOutcome:
        """Perform one request. Raise Transient/PermanentAdapterError on failure."""

    @abstractmethod
    def parse(self, outcome: FetchOutcome) -> ParseOutcome:
        """Turn bytes into records. Must not fabricate values for missing periods."""

    # -- shared helpers ---------------------------------------------------

    def envelope(self, outcome: FetchOutcome) -> FetchEnvelope:
        """Build the provenance envelope for a fetch. Adapters rarely override this."""
        return FetchEnvelope(
            source_code=self.source_code,
            fetched_at=outcome.fetched_at,
            request_url=outcome.plan.url,
            request_method=outcome.plan.method,
            request_params=outcome.plan.params,
            http_status=outcome.http_status,
            content_type=outcome.content_type,
            legal_basis=self.legal_basis,
            geography=self.source.geography,
            observation_period_start=outcome.plan.period_start,
            observation_period_end=outcome.plan.period_end,
            collector=type(self).__name__,
            collector_version=self.collector_version,
            venue_code=outcome.plan.venue_code,
            notes=outcome.notes,
        )

    def series_codes(self) -> set[str]:
        return {s.code for s in self.source.series}

    def __repr__(self) -> str:  # pragma: no cover
        return f"<{type(self).__name__} source={self.source_code} v{self.collector_version}>"
