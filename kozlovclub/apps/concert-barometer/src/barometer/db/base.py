"""Declarative base, schema names and shared column types."""

from __future__ import annotations

import datetime as dt
from typing import Any

from sqlalchemy import DateTime, MetaData, text
from sqlalchemy.dialects.postgresql import ENUM as PgEnum
from sqlalchemy.orm import DeclarativeBase, mapped_column

from barometer.config.models import (
    AccessPolicy,
    Aggregation,
    AssertionClass,
    Cadence,
    Frequency,
    Measures,
    RobotsPolicy,
    SourceKind,
    UnitClass,
    VenueCategory,
)

# Schema layering, immutable left to right (ADR 0002):
#   raw -> norm -> feature -> model.  registry and ops sit beside them.
SCHEMA_REGISTRY = "registry"
SCHEMA_RAW = "raw"
SCHEMA_NORM = "norm"
SCHEMA_FEATURE = "feature"
SCHEMA_MODEL = "model"
SCHEMA_OPS = "ops"

ALL_SCHEMAS = (
    SCHEMA_REGISTRY,
    SCHEMA_RAW,
    SCHEMA_NORM,
    SCHEMA_FEATURE,
    SCHEMA_MODEL,
    SCHEMA_OPS,
)

NAMING_CONVENTION = {
    "ix": "ix_%(table_name)s_%(column_0_N_name)s",
    "uq": "uq_%(table_name)s_%(column_0_N_name)s",
    "ck": "ck_%(table_name)s_%(constraint_name)s",
    "fk": "fk_%(table_name)s_%(column_0_name)s_%(referred_table_name)s",
    "pk": "pk_%(table_name)s",
}

metadata = MetaData(naming_convention=NAMING_CONVENTION)


class Base(DeclarativeBase):
    metadata = metadata

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        pk = self.__mapper__.primary_key
        vals = ", ".join(f"{c.name}={getattr(self, c.name)!r}" for c in pk)
        return f"<{type(self).__name__} {vals}>"


# ---------------------------------------------------------------------------
# Enum types, created once in the registry schema and reused everywhere
# ---------------------------------------------------------------------------


def _pg_enum(python_enum: type, name: str) -> PgEnum:
    return PgEnum(
        python_enum,
        name=name,
        schema=SCHEMA_REGISTRY,
        create_type=True,
        values_callable=lambda e: [m.value for m in e],
    )


assertion_class_enum = _pg_enum(AssertionClass, "assertion_class")
measures_enum = _pg_enum(Measures, "measures")
source_kind_enum = _pg_enum(SourceKind, "source_kind")
access_policy_enum = _pg_enum(AccessPolicy, "access_policy")
robots_policy_enum = _pg_enum(RobotsPolicy, "robots_policy")
frequency_enum = _pg_enum(Frequency, "frequency")
cadence_enum = _pg_enum(Cadence, "cadence")
aggregation_enum = _pg_enum(Aggregation, "aggregation")
venue_category_enum = _pg_enum(VenueCategory, "venue_category")
unit_class_enum = _pg_enum(UnitClass, "unit_class")


# ---------------------------------------------------------------------------
# Column helpers
# ---------------------------------------------------------------------------

TZDateTime = DateTime(timezone=True)


def utcnow_column(**kwargs: Any):
    """A timestamptz defaulting to transaction time on the server, not the client."""
    return mapped_column(
        TZDateTime,
        server_default=text("now()"),
        nullable=False,
        **kwargs,
    )


def utcnow() -> dt.datetime:
    return dt.datetime.now(dt.UTC)
