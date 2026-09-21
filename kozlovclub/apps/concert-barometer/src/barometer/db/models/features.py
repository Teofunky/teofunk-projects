"""feature.*, model-ready columns, each carrying the vintage it was built from.

A feature row records not only its value but ``max_input_available_at``: the
latest moment any input to it became knowable. The forecast runner refuses to
use a feature whose ``max_input_available_at`` is after the simulated decision
time, which is how principle 8 (no look-ahead leakage) is enforced mechanically
rather than by reviewer discipline.
"""

from __future__ import annotations

import datetime as dt

from sqlalchemy import (
    CheckConstraint,
    Date,
    Float,
    Index,
    PrimaryKeyConstraint,
    String,
    Text,
    text,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB
from sqlalchemy.orm import Mapped, mapped_column

from barometer.db.base import SCHEMA_FEATURE, Base, TZDateTime, utcnow_column


class FeatureValue(Base):
    __tablename__ = "feature_value"
    __table_args__ = (
        PrimaryKeyConstraint("as_of_date", "feature_code", "vintage_at", name="pk_feature_value"),
        CheckConstraint(
            "value IS NOT NULL OR missing_reason IS NOT NULL",
            name="absent_feature_has_a_reason",
        ),
        CheckConstraint(
            "max_input_available_at <= vintage_at",
            name="feature_cannot_precede_its_inputs",
        ),
        Index("ix_feature_value_code_date", "feature_code", "as_of_date"),
        Index("ix_feature_value_available", "max_input_available_at"),
        {"schema": SCHEMA_FEATURE},
    )

    as_of_date: Mapped[dt.date] = mapped_column(Date, nullable=False)
    feature_code: Mapped[str] = mapped_column(String(160), nullable=False)
    vintage_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)

    value: Mapped[float | None] = mapped_column(Float)
    missing_reason: Mapped[str | None] = mapped_column(String(60))
    feature_set_version: Mapped[str] = mapped_column(String(40), nullable=False)

    #: The no-look-ahead clock for this row.
    max_input_available_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    #: Series codes and their vintages that produced this value, for drill-down.
    computed_from: Mapped[dict] = mapped_column(
        JSONB, nullable=False, server_default=text("'{}'::jsonb")
    )
    quality_flags: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, server_default=text("'{}'::text[]")
    )
    created_at: Mapped[dt.datetime] = utcnow_column()


class FeatureDefinition(Base):
    """What a feature means and how it is built. Documentation the UI can read."""

    __tablename__ = "feature_definition"
    __table_args__ = (
        CheckConstraint(
            "calendar_exempt IS FALSE OR family = 'calendar'",
            name="only_calendar_features_are_lag_exempt",
        ),
        {"schema": SCHEMA_FEATURE},
    )

    feature_code: Mapped[str] = mapped_column(String(160), primary_key=True)
    name_ru: Mapped[str] = mapped_column(Text, nullable=False)
    family: Mapped[str] = mapped_column(String(40), nullable=False)
    description_ru: Mapped[str] = mapped_column(Text, nullable=False)
    transform: Mapped[str] = mapped_column(String(120), nullable=False)
    input_series: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, server_default=text("'{}'::text[]")
    )
    #: Calendar features are known in advance and are the only family exempt from
    #: the publication-lag rule (index_definition.forecast.integrity).
    calendar_exempt: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))
    created_at: Mapped[dt.datetime] = utcnow_column()
