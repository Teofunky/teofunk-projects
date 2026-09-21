"""norm.*, observations in canonical units, each pointing back at its evidence.

Design notes
------------
* An observation is append-only. A revision is a NEW ROW with a later
  ``vintage_at``, never an UPDATE. This is what makes backtests honest: the
  pipeline can ask "what did we know on 2025-03-01?" and get a truthful answer.
* ``snapshot_id`` is NOT NULL. There is no path to a number without evidence.
* ``observed_at`` is the START of the period the value describes, in UTC.
  ``period`` says how long that period is, so a weekly and a daily series are
  never silently compared.
"""

from __future__ import annotations

import datetime as dt
import uuid

from sqlalchemy import (
    BigInteger,
    CheckConstraint,
    Date,
    Float,
    ForeignKey,
    Index,
    Numeric,
    PrimaryKeyConstraint,
    String,
    Text,
    text,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB
from sqlalchemy.orm import Mapped, mapped_column

from barometer.config.models import AssertionClass
from barometer.db.base import (
    SCHEMA_NORM,
    SCHEMA_RAW,
    SCHEMA_REGISTRY,
    Base,
    TZDateTime,
    assertion_class_enum,
    utcnow_column,
)

PERIODS = ("hour", "day", "week", "month", "quarter", "year", "point")

#: Applied by the normaliser, visible in the UI drill-down.
QUALITY_FLAGS = (
    "forward_filled",
    "interpolated",
    "outlier_suspected",
    "source_revision",
    "partial_period",
    "unit_converted",
    "clipped",
)


class Observation(Base):
    """One value, for one series, for one period, as known at one vintage."""

    __tablename__ = "observation"
    __table_args__ = (
        # observed_at leads the PK so the table partitions cleanly by time whether
        # or not TimescaleDB is present (ADR 0008).
        PrimaryKeyConstraint("observed_at", "series_id", "vintage_at", name="pk_observation"),
        CheckConstraint(f"period IN {PERIODS}", name="period_is_known"),
        CheckConstraint("period_end > observed_at", name="period_is_positive_length"),
        CheckConstraint(
            "value IS NOT NULL OR missing_reason IS NOT NULL",
            name="absent_value_has_a_reason",
        ),
        CheckConstraint("retrieved_at <= vintage_at", name="vintage_is_not_before_retrieval"),
        Index("ix_observation_series_observed", "series_id", "observed_at"),
        Index("ix_observation_snapshot", "snapshot_id"),
        Index("ix_observation_vintage", "vintage_at"),
        {"schema": SCHEMA_NORM},
    )

    observed_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    series_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.series.id", ondelete="RESTRICT"), nullable=False
    )
    #: When this value became known to us. Revisions get a later vintage.
    vintage_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)

    period: Mapped[str] = mapped_column(String(10), nullable=False)
    period_end: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)

    value: Mapped[float | None] = mapped_column(Float)
    missing_reason: Mapped[str | None] = mapped_column(String(60))
    unit: Mapped[str] = mapped_column(String(60), nullable=False)
    geography: Mapped[str] = mapped_column(String(16), nullable=False)
    assertion_class: Mapped[AssertionClass] = mapped_column(assertion_class_enum, nullable=False)

    #: Provenance. Not nullable, on purpose.
    snapshot_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_RAW}.snapshot.id", ondelete="RESTRICT"), nullable=False
    )
    retrieved_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    #: When the publisher released it, when that is knowable. Drives the
    #: no-look-ahead rule; falls back to retrieved_at when unknown.
    published_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)

    quality_flags: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, server_default=text("'{}'::text[]")
    )
    methodology_notes: Mapped[str | None] = mapped_column(Text)
    transform: Mapped[str | None] = mapped_column(String(80))
    created_at: Mapped[dt.datetime] = utcnow_column()

    @property
    def available_at(self) -> dt.datetime:
        """The earliest moment a model may legitimately use this value."""
        return self.published_at or self.retrieved_at


class EventObservation(Base):
    """A listed event as seen on a public page at one moment.

    PROXY DATA (principle 5). Availability is not sales. The column is named
    ``availability_label`` and not ``sold``, and there is deliberately no
    ``tickets_sold`` column for panel venues anywhere in this schema.
    """

    __tablename__ = "event_observation"
    __table_args__ = (
        PrimaryKeyConstraint("observed_at", "id", name="pk_event_observation"),
        CheckConstraint(
            "price_min IS NULL OR price_max IS NULL OR price_max >= price_min",
            name="price_range_ordered",
        ),
        CheckConstraint(
            "price_min IS NULL OR price_min >= 0",
            name="price_non_negative",
        ),
        Index("ix_event_obs_venue_starts", "venue_id", "starts_at"),
        Index("ix_event_obs_snapshot", "snapshot_id"),
        Index("ix_event_obs_key", "venue_id", "event_key", "observed_at"),
        {"schema": SCHEMA_NORM},
    )

    observed_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    id: Mapped[int] = mapped_column(BigInteger, autoincrement=True, nullable=False)

    venue_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.venue.id", ondelete="RESTRICT"), nullable=False
    )
    snapshot_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_RAW}.snapshot.id", ondelete="RESTRICT"), nullable=False
    )

    #: Stable identity of an event across snapshots: venue + normalised title + date.
    event_key: Mapped[str] = mapped_column(String(200), nullable=False)
    external_id: Mapped[str | None] = mapped_column(String(200))
    title: Mapped[str] = mapped_column(Text, nullable=False)
    starts_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    stage: Mapped[str | None] = mapped_column(String(120))
    ticket_provider: Mapped[str | None] = mapped_column(String(80))
    ticket_url: Mapped[str | None] = mapped_column(Text)

    price_min: Mapped[float | None] = mapped_column(Numeric(12, 2))
    price_max: Mapped[float | None] = mapped_column(Numeric(12, 2))
    currency: Mapped[str] = mapped_column(String(3), nullable=False, server_default=text("'RUB'"))

    #: Verbatim from the page ("Билеты", "Мало билетов", "Распродано"). We store
    #: what was written, not what we think it means.
    availability_label: Mapped[str | None] = mapped_column(String(120))
    categories_listed: Mapped[int | None] = mapped_column(BigInteger)

    raw_fields: Mapped[dict] = mapped_column(
        JSONB, nullable=False, server_default=text("'{}'::jsonb")
    )
    created_at: Mapped[dt.datetime] = utcnow_column()


class EventDiff(Base):
    """What changed between two consecutive snapshots of the same venue.

    Feeds «Что изменилось со вчера?» and the competition-pressure component.
    A diff is an observation about our own observations, so it is a derived
    metric, never a fact about the market.
    """

    __tablename__ = "event_diff"
    __table_args__ = (
        PrimaryKeyConstraint("detected_at", "id", name="pk_event_diff"),
        CheckConstraint(
            "change_type IN ('listed','delisted','price_up','price_down',"
            "'availability_changed','rescheduled','stage_changed')",
            name="change_type_is_known",
        ),
        Index("ix_event_diff_venue", "venue_id", "detected_at"),
        {"schema": SCHEMA_NORM},
    )

    detected_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    id: Mapped[int] = mapped_column(BigInteger, autoincrement=True, nullable=False)

    venue_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.venue.id", ondelete="RESTRICT"), nullable=False
    )
    event_key: Mapped[str] = mapped_column(String(200), nullable=False)
    change_type: Mapped[str] = mapped_column(String(40), nullable=False)
    snapshot_before_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey(f"{SCHEMA_RAW}.snapshot.id", ondelete="RESTRICT")
    )
    snapshot_after_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_RAW}.snapshot.id", ondelete="RESTRICT"), nullable=False
    )
    delta: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    #: Set when a change could equally well be a site change, not a market change.
    ambiguity_note: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[dt.datetime] = utcnow_column()


class InformationAttention(Base):
    """Measured public attention to a report, including unverified ones.

    Principle 9. A rumour is not an event. When attention to an unconfirmed
    report is measurable and useful, it is stored HERE, as attention, with the
    verification status recorded, and it may never be joined into the event
    stress component as a confirmed occurrence.
    """

    __tablename__ = "information_attention"
    __table_args__ = (
        PrimaryKeyConstraint("as_of_date", "topic_key", name="pk_information_attention"),
        CheckConstraint(
            "verification_status IN ('unverified','disputed','confirmed','refuted')",
            name="verification_status_is_known",
        ),
        CheckConstraint(
            "assertion_class IN ('estimate','hypothesis')",
            name="attention_is_never_a_fact_about_the_world",
        ),
        {"schema": SCHEMA_NORM},
    )

    as_of_date: Mapped[dt.date] = mapped_column(Date, nullable=False)
    topic_key: Mapped[str] = mapped_column(String(160), nullable=False)
    topic_label_ru: Mapped[str] = mapped_column(Text, nullable=False)
    attention_score: Mapped[float] = mapped_column(Float, nullable=False)
    measurement_basis: Mapped[str] = mapped_column(String(120), nullable=False)
    verification_status: Mapped[str] = mapped_column(String(20), nullable=False)
    assertion_class: Mapped[AssertionClass] = mapped_column(assertion_class_enum, nullable=False)
    snapshot_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_RAW}.snapshot.id", ondelete="RESTRICT"), nullable=False
    )
    notes: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[dt.datetime] = utcnow_column()
