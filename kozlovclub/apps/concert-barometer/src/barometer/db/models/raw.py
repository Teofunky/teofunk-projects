"""raw.*, immutable evidence.

Nothing in this schema is ever updated or deleted by the application. A snapshot
is the receipt for every downstream number: if a snapshot cannot be produced, the
observation does not get written (principle 4).

Immutability is enforced by triggers created in migration 0001, not by convention.
"""

from __future__ import annotations

import datetime as dt
import uuid

from sqlalchemy import (
    BigInteger,
    CheckConstraint,
    ForeignKey,
    Index,
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
    text,
)
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column

from barometer.db.base import SCHEMA_RAW, SCHEMA_REGISTRY, Base, TZDateTime, utcnow_column

#: How we are legally entitled to hold this blob.
LEGAL_BASIS = ("official_api", "licensed", "public_permitted", "user_supplied", "manual_entry")


class Snapshot(Base):
    """One retrieval from one source at one moment.

    The bytes live in object storage (local dir or S3); this row holds the
    provenance envelope and the content hash that ties a stored blob to the
    observations parsed out of it.
    """

    __tablename__ = "snapshot"
    __table_args__ = (
        UniqueConstraint("idempotency_key", name="uq_snapshot_idempotency_key"),
        CheckConstraint(
            "legal_basis IN " + "(" + ", ".join(f"'{b}'" for b in LEGAL_BASIS) + ")",
            name="legal_basis_is_known",
        ),
        CheckConstraint(
            "content_hash ~ '^[0-9a-f]{64}$'",
            name="content_hash_is_sha256",
        ),
        CheckConstraint(
            "observation_period_start IS NULL "
            "OR observation_period_end IS NULL "
            "OR observation_period_end >= observation_period_start",
            name="observation_period_ordered",
        ),
        CheckConstraint(
            "storage_uri IS NOT NULL OR content_inline IS NOT NULL OR retention = 'not_retained'",
            name="bytes_are_somewhere_or_declared_absent",
        ),
        Index("ix_snapshot_source_fetched", "source_id", "fetched_at"),
        Index("ix_snapshot_content_hash", "content_hash"),
        Index("ix_snapshot_run_id", "run_id"),
        {"schema": SCHEMA_RAW},
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    source_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.source.id", ondelete="RESTRICT"), nullable=False
    )
    venue_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.venue.id", ondelete="RESTRICT")
    )

    #: Deterministic key: source + endpoint + params + observation window + fetch day.
    #: Re-running a daily pipeline is a no-op rather than a duplicate.
    idempotency_key: Mapped[str] = mapped_column(String(200), nullable=False)

    fetched_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    request_url: Mapped[str | None] = mapped_column(Text)
    request_method: Mapped[str] = mapped_column(
        String(10), nullable=False, server_default=text("'GET'")
    )
    request_params: Mapped[dict] = mapped_column(
        JSONB, nullable=False, server_default=text("'{}'::jsonb")
    )
    http_status: Mapped[int | None] = mapped_column(SmallInteger)
    content_type: Mapped[str | None] = mapped_column(String(120))

    content_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    content_bytes: Mapped[int | None] = mapped_column(BigInteger)
    storage_uri: Mapped[str | None] = mapped_column(Text)
    #: Small payloads (a single number, a short JSON) may be stored inline instead
    #: of paying for an object per observation.
    content_inline: Mapped[str | None] = mapped_column(Text)
    retention: Mapped[str] = mapped_column(
        String(24), nullable=False, server_default=text("'retained'")
    )
    legal_basis: Mapped[str] = mapped_column(String(24), nullable=False)

    #: What period the payload describes, as opposed to when we fetched it.
    observation_period_start: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    observation_period_end: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    geography: Mapped[str | None] = mapped_column(String(16))

    collector: Mapped[str] = mapped_column(String(120), nullable=False)
    collector_version: Mapped[str] = mapped_column(String(40), nullable=False)
    run_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True))
    notes: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[dt.datetime] = utcnow_column()


class SnapshotParseError(Base):
    """A snapshot we fetched but could not turn into observations.

    Kept so that a silent gap in a series always has a visible cause.
    """

    __tablename__ = "snapshot_parse_error"
    __table_args__ = (
        Index("ix_snapshot_parse_error_snapshot", "snapshot_id"),
        {"schema": SCHEMA_RAW},
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    snapshot_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_RAW}.snapshot.id", ondelete="RESTRICT"), nullable=False
    )
    stage: Mapped[str] = mapped_column(String(40), nullable=False)
    error_type: Mapped[str] = mapped_column(String(120), nullable=False)
    message: Mapped[str] = mapped_column(Text, nullable=False)
    detail: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    created_at: Mapped[dt.datetime] = utcnow_column()
