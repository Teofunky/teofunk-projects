"""The provenance envelope every observation must carry.

Principle 4 in one dataclass: source, endpoint, observation period, geography,
unit, retrieval time, methodology notes and the hash of the raw bytes. If an
adapter cannot fill these in, it cannot write an observation.
"""

from __future__ import annotations

import datetime as dt
import hashlib
import json
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator

from barometer.config.models import AssertionClass

LegalBasis = Literal[
    "official_api", "licensed", "public_permitted", "user_supplied", "manual_entry"
]
Period = Literal["hour", "day", "week", "month", "quarter", "year", "point"]


class FetchEnvelope(BaseModel):
    """Everything about one retrieval, independent of what was parsed out of it."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    source_code: str
    fetched_at: dt.datetime
    request_url: str | None = None
    request_method: str = "GET"
    request_params: dict[str, Any] = Field(default_factory=dict)
    http_status: int | None = None
    content_type: str | None = None
    legal_basis: LegalBasis
    geography: str | None = None
    observation_period_start: dt.datetime | None = None
    observation_period_end: dt.datetime | None = None
    collector: str
    collector_version: str
    venue_code: str | None = None
    notes: str | None = None
    #: 'not_retained' is for sources whose terms forbid keeping a copy. The
    #: snapshot row still exists, with the hash, so provenance survives.
    retention: Literal["retained", "not_retained"] = "retained"

    @model_validator(mode="after")
    def _timestamps_are_aware(self) -> FetchEnvelope:
        for name in ("fetched_at", "observation_period_start", "observation_period_end"):
            value = getattr(self, name)
            if value is not None and value.tzinfo is None:
                raise ValueError(f"{name} must be timezone-aware")
        if (
            self.observation_period_start
            and self.observation_period_end
            and self.observation_period_end < self.observation_period_start
        ):
            raise ValueError("observation_period_end precedes observation_period_start")
        return self

    def idempotency_key(self, digest: str) -> str:
        """Stable key for "this retrieval, on this day".

        Re-running the daily pipeline produces the same key and is absorbed by
        the unique index rather than duplicating evidence. The content digest is
        included so a genuinely changed payload is a new snapshot.
        """
        payload = {
            "source": self.source_code,
            "url": self.request_url,
            "method": self.request_method,
            "params": self.request_params,
            "venue": self.venue_code,
            "period_start": _iso(self.observation_period_start),
            "period_end": _iso(self.observation_period_end),
            "day": self.fetched_at.astimezone(dt.UTC).date().isoformat(),
            "digest": digest,
        }
        blob = json.dumps(payload, sort_keys=True, ensure_ascii=False, separators=(",", ":"))
        return hashlib.sha256(blob.encode("utf-8")).hexdigest()[:48]


class ObservationRecord(BaseModel):
    """One parsed value, ready to be written against a snapshot.

    ``available_at`` is the publication time when the source states one, and the
    retrieval time otherwise. It is the clock the no-look-ahead rule reads.
    """

    model_config = ConfigDict(frozen=True, extra="forbid")

    series_code: str
    observed_at: dt.datetime
    period: Period
    period_end: dt.datetime
    value: float | None = None
    missing_reason: str | None = None
    unit: str
    geography: str
    assertion_class: AssertionClass
    published_at: dt.datetime | None = None
    quality_flags: list[str] = Field(default_factory=list)
    methodology_notes: str | None = None
    transform: str | None = None

    @model_validator(mode="after")
    def _is_well_formed(self) -> ObservationRecord:
        if self.value is None and not self.missing_reason:
            raise ValueError(
                f"{self.series_code} @ {self.observed_at}: a missing value needs a "
                "missing_reason, so a gap in a chart always has a stated cause"
            )
        if self.value is not None and self.missing_reason:
            raise ValueError(
                f"{self.series_code} @ {self.observed_at}: a value and a missing_reason "
                "cannot both be present"
            )
        for name in ("observed_at", "period_end", "published_at"):
            value = getattr(self, name)
            if value is not None and value.tzinfo is None:
                raise ValueError(f"{name} must be timezone-aware")
        if self.period_end <= self.observed_at:
            raise ValueError(
                f"{self.series_code}: period_end must be after observed_at "
                f"({self.period_end} <= {self.observed_at})"
            )
        if self.value is not None and (self.value != self.value):  # NaN
            raise ValueError(f"{self.series_code}: NaN is not a value; use missing_reason")
        return self


class EventRecord(BaseModel):
    """One listed event as seen on a page. Proxy data by construction."""

    model_config = ConfigDict(frozen=True, extra="forbid")

    venue_code: str
    event_key: str
    title: str
    observed_at: dt.datetime
    external_id: str | None = None
    starts_at: dt.datetime | None = None
    stage: str | None = None
    ticket_provider: str | None = None
    ticket_url: str | None = None
    price_min: float | None = None
    price_max: float | None = None
    currency: str = "RUB"
    #: Verbatim page text. Interpreting it is a separate, reviewable step.
    availability_label: str | None = None
    categories_listed: int | None = None
    raw_fields: dict[str, Any] = Field(default_factory=dict)

    @model_validator(mode="after")
    def _prices_are_sane(self) -> EventRecord:
        if self.price_min is not None and self.price_min < 0:
            raise ValueError(f"{self.event_key}: negative price_min")
        if (
            self.price_min is not None
            and self.price_max is not None
            and self.price_max < self.price_min
        ):
            raise ValueError(f"{self.event_key}: price_max < price_min")
        return self


def _iso(value: dt.datetime | None) -> str | None:
    return value.astimezone(dt.UTC).isoformat() if value else None
