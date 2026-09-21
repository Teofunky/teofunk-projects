"""registry.*, what we are allowed to observe, and what a value means.

Mirrors config/*.yaml. The YAML is the source of truth an engineer edits; these
tables are the runtime projection that observations can reference by foreign key.
Synchronised by `barometer db sync-registry`.
"""

from __future__ import annotations

import datetime as dt
import uuid

from sqlalchemy import (
    CheckConstraint,
    ForeignKey,
    Index,
    Integer,
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
    text,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

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
from barometer.db.base import (
    SCHEMA_REGISTRY,
    Base,
    TZDateTime,
    access_policy_enum,
    aggregation_enum,
    assertion_class_enum,
    cadence_enum,
    frequency_enum,
    measures_enum,
    robots_policy_enum,
    source_kind_enum,
    unit_class_enum,
    utcnow_column,
    venue_category_enum,
)


class Source(Base):
    """An external source we are permitted to read, and on what terms."""

    __tablename__ = "source"
    __table_args__ = (
        CheckConstraint(
            "NOT requires_credentials OR credential_env_var IS NOT NULL",
            name="credentials_are_named",
        ),
        CheckConstraint(
            "NOT is_proxy OR proxy_for IS NOT NULL",
            name="proxy_declares_target",
        ),
        # Principle 10, enforced in the database and not only in YAML.
        CheckConstraint(
            "NOT enabled OR robots_policy IN ('allowed', 'not_applicable')",
            name="enabled_requires_resolved_robots",
        ),
        CheckConstraint(
            "typical_lag_days >= 0",
            name="lag_is_non_negative",
        ),
        {"schema": SCHEMA_REGISTRY},
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    code: Mapped[str] = mapped_column(String(120), unique=True, nullable=False)
    name: Mapped[str] = mapped_column(Text, nullable=False)
    name_ru: Mapped[str] = mapped_column(Text, nullable=False)
    kind: Mapped[SourceKind] = mapped_column(source_kind_enum, nullable=False)
    publisher: Mapped[str] = mapped_column(Text, nullable=False)
    homepage_url: Mapped[str | None] = mapped_column(Text)
    endpoint_url: Mapped[str | None] = mapped_column(Text)
    geography: Mapped[str] = mapped_column(String(16), nullable=False)
    access_policy: Mapped[AccessPolicy] = mapped_column(access_policy_enum, nullable=False)
    requires_credentials: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))
    credential_env_var: Mapped[str | None] = mapped_column(String(120))
    robots_policy: Mapped[RobotsPolicy] = mapped_column(robots_policy_enum, nullable=False)
    robots_checked_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    rate_limit_per_min: Mapped[int | None] = mapped_column(Integer)
    update_cadence: Mapped[Cadence] = mapped_column(cadence_enum, nullable=False)
    typical_lag_days: Mapped[int] = mapped_column(Integer, nullable=False)
    license: Mapped[str | None] = mapped_column(Text)
    terms_url: Mapped[str | None] = mapped_column(Text)
    is_proxy: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))
    proxy_for: Mapped[str | None] = mapped_column(Text)
    methodology_notes: Mapped[str | None] = mapped_column(Text)
    verification_required: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, server_default=text("'{}'::text[]")
    )
    enabled: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))
    created_at: Mapped[dt.datetime] = utcnow_column()
    updated_at: Mapped[dt.datetime] = utcnow_column()

    series: Mapped[list[Series]] = relationship(back_populates="source")


class Segment(Base):
    """A selectable layer in the dashboard."""

    __tablename__ = "segment"
    __table_args__ = (
        CheckConstraint("min_series >= 0", name="min_series_non_negative"),
        CheckConstraint("parent_code IS NULL OR parent_code <> code", name="no_self_parent"),
        {"schema": SCHEMA_REGISTRY},
    )

    code: Mapped[str] = mapped_column(String(120), primary_key=True)
    name_ru: Mapped[str] = mapped_column(Text, nullable=False)
    kind: Mapped[str] = mapped_column(String(32), nullable=False)
    parent_code: Mapped[str | None] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.segment.code", ondelete="RESTRICT")
    )
    display_order: Mapped[int] = mapped_column(Integer, nullable=False)
    unit_class: Mapped[UnitClass] = mapped_column(unit_class_enum, nullable=False)
    min_series: Mapped[int] = mapped_column(SmallInteger, nullable=False, server_default=text("1"))
    venue_category: Mapped[VenueCategory | None] = mapped_column(venue_category_enum)
    proxy_badge_ru: Mapped[str | None] = mapped_column(Text)
    visible_in_ui: Mapped[bool] = mapped_column(nullable=False, server_default=text("true"))


class Series(Base):
    """A canonical observable time series.

    `measures` is the epistemic backbone: it bounds what assertion_class an
    observation on this series may carry (see ADR 0006 and the CHECK below).
    """

    __tablename__ = "series"
    __table_args__ = (
        UniqueConstraint("code", name="uq_series_code"),
        # direct -> any class; derived -> not 'fact'; inferred -> estimate or weaker.
        CheckConstraint(
            """
            (measures = 'direct')
            OR (measures = 'derived'
                AND assertion_class IN ('derived_metric','estimate','hypothesis','forecast'))
            OR (measures = 'inferred'
                AND assertion_class IN ('estimate','hypothesis','forecast'))
            """,
            name="claim_matches_what_is_measured",
        ),
        Index("ix_series_source_id", "source_id"),
        Index("ix_series_segment_code", "segment_code"),
        {"schema": SCHEMA_REGISTRY},
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    code: Mapped[str] = mapped_column(String(160), nullable=False)
    source_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.source.id", ondelete="RESTRICT"), nullable=False
    )
    segment_code: Mapped[str] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.segment.code", ondelete="RESTRICT"), nullable=False
    )
    name_ru: Mapped[str] = mapped_column(Text, nullable=False)
    unit: Mapped[str] = mapped_column(String(60), nullable=False)
    frequency: Mapped[Frequency] = mapped_column(frequency_enum, nullable=False)
    measures: Mapped[Measures] = mapped_column(measures_enum, nullable=False)
    assertion_class: Mapped[AssertionClass] = mapped_column(assertion_class_enum, nullable=False)
    aggregation: Mapped[Aggregation] = mapped_column(aggregation_enum, nullable=False)
    description_ru: Mapped[str | None] = mapped_column(Text)
    proxy_caveat_ru: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[dt.datetime] = utcnow_column()

    source: Mapped[Source] = relationship(back_populates="series")


class Venue(Base):
    """A venue in the observation panel.

    `capacity` is a sourced fact or it is absent: the CHECK makes an unsourced
    capacity impossible to store, which is the point of the rule.
    """

    __tablename__ = "venue"
    __table_args__ = (
        CheckConstraint(
            "capacity IS NULL OR capacity_source_url IS NOT NULL",
            name="capacity_only_if_sourced",
        ),
        CheckConstraint(
            "capacity IS NULL OR (capacity > 0 AND capacity <= 200000)",
            name="capacity_is_plausible",
        ),
        CheckConstraint(
            """
            NOT enabled
            OR relationship_type = 'first_party'
            OR (url_verified AND robots_allows_crawl AND access_reviewed_at IS NOT NULL)
            """,
            name="enabled_requires_review",
        ),
        Index("ix_venue_category", "category"),
        {"schema": SCHEMA_REGISTRY},
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    code: Mapped[str] = mapped_column(String(120), unique=True, nullable=False)
    canonical_name: Mapped[str] = mapped_column(Text, nullable=False)
    canonical_name_ru: Mapped[str] = mapped_column(Text, nullable=False)
    category: Mapped[VenueCategory] = mapped_column(venue_category_enum, nullable=False)
    city: Mapped[str] = mapped_column(String(80), nullable=False, server_default=text("'Москва'"))
    geography: Mapped[str] = mapped_column(
        String(16), nullable=False, server_default=text("'RU-MOW'")
    )
    website_url: Mapped[str | None] = mapped_column(Text)
    url_verified: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))
    ticket_providers: Mapped[list[str]] = mapped_column(
        ARRAY(Text), nullable=False, server_default=text("'{}'::text[]")
    )
    relationship_type: Mapped[str] = mapped_column(
        String(24), nullable=False, server_default=text("'panel'")
    )
    source_access_policy: Mapped[AccessPolicy] = mapped_column(access_policy_enum, nullable=False)
    robots_allows_crawl: Mapped[bool | None] = mapped_column()
    robots_checked_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    access_reviewed_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    capacity: Mapped[int | None] = mapped_column(Integer)
    capacity_source_url: Mapped[str | None] = mapped_column(Text)
    capacity_source_note: Mapped[str | None] = mapped_column(Text)
    enabled: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))
    notes_ru: Mapped[str | None] = mapped_column(Text)
    extra: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    created_at: Mapped[dt.datetime] = utcnow_column()
    updated_at: Mapped[dt.datetime] = utcnow_column()
