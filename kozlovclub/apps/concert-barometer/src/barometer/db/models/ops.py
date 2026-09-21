"""ops.*, how the machine is running, and whether today's numbers can be trusted."""

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
    Integer,
    String,
    Text,
    UniqueConstraint,
    text,
)
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column

from barometer.db.base import SCHEMA_OPS, SCHEMA_REGISTRY, Base, TZDateTime, utcnow_column


class PipelineRun(Base):
    __tablename__ = "pipeline_run"
    __table_args__ = (
        CheckConstraint(
            "status IN ('running','succeeded','failed','degraded','skipped')",
            name="status_is_known",
        ),
        CheckConstraint(
            "finished_at IS NULL OR finished_at >= started_at",
            name="run_duration_non_negative",
        ),
        Index("ix_pipeline_run_pipeline_started", "pipeline", "started_at"),
        {"schema": SCHEMA_OPS},
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    pipeline: Mapped[str] = mapped_column(String(80), nullable=False)
    stage: Mapped[str | None] = mapped_column(String(80))
    actor: Mapped[str] = mapped_column(String(120), nullable=False)
    status: Mapped[str] = mapped_column(String(20), nullable=False)
    started_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    finished_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    params: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    records_written: Mapped[int] = mapped_column(
        BigInteger, nullable=False, server_default=text("0")
    )
    error_type: Mapped[str | None] = mapped_column(String(120))
    error_message: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[dt.datetime] = utcnow_column()


class SourceHealth(Base):
    """One row per source: is it fresh, is it reachable, is access still resolved."""

    __tablename__ = "source_health"
    __table_args__ = (
        CheckConstraint("consecutive_failures >= 0", name="failures_non_negative"),
        CheckConstraint(
            "status IN ('healthy','stale','failing','blocked','disabled','never_run')",
            name="health_status_is_known",
        ),
        {"schema": SCHEMA_OPS},
    )

    source_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_REGISTRY}.source.id", ondelete="CASCADE"), primary_key=True
    )
    status: Mapped[str] = mapped_column(
        String(20), nullable=False, server_default=text("'never_run'")
    )
    last_attempt_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    last_success_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    last_observation_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    freshness_hours: Mapped[float | None] = mapped_column(Float)
    expected_freshness_hours: Mapped[float | None] = mapped_column(Float)
    consecutive_failures: Mapped[int] = mapped_column(
        Integer, nullable=False, server_default=text("0")
    )
    last_error: Mapped[str | None] = mapped_column(Text)
    robots_last_checked_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    robots_result: Mapped[str | None] = mapped_column(String(40))
    updated_at: Mapped[dt.datetime] = utcnow_column()


class DataQualityCheck(Base):
    """A named check, its verdict, and what it looked at.

    The fact checker and the red team write here. A failed blocking check stops
    publication of the affected series rather than being noted and ignored.
    """

    __tablename__ = "data_quality_check"
    __table_args__ = (
        CheckConstraint(
            "severity IN ('info','warning','critical')",
            name="severity_is_known",
        ),
        Index("ix_dq_check_target_date", "target_code", "as_of_date"),
        Index("ix_dq_check_failed", "passed", "as_of_date"),
        {"schema": SCHEMA_OPS},
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    check_code: Mapped[str] = mapped_column(String(120), nullable=False)
    target_kind: Mapped[str] = mapped_column(String(40), nullable=False)
    target_code: Mapped[str] = mapped_column(String(160), nullable=False)
    as_of_date: Mapped[dt.date] = mapped_column(Date, nullable=False)
    passed: Mapped[bool] = mapped_column(nullable=False)
    severity: Mapped[str] = mapped_column(String(10), nullable=False)
    blocking: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))
    actor: Mapped[str] = mapped_column(String(120), nullable=False)
    detail: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    message_ru: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[dt.datetime] = utcnow_column()


class AgentInvocation(Base):
    """Audit trail of every agent call. Transient state, permanent record."""

    __tablename__ = "agent_invocation"
    __table_args__ = (
        CheckConstraint(
            "status IN ('succeeded','failed','rejected_by_contract','timed_out')",
            name="agent_status_is_known",
        ),
        Index("ix_agent_invocation_agent_started", "agent_code", "started_at"),
        {"schema": SCHEMA_OPS},
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    run_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey(f"{SCHEMA_OPS}.pipeline_run.id", ondelete="SET NULL")
    )
    agent_code: Mapped[str] = mapped_column(String(80), nullable=False)
    provider: Mapped[str] = mapped_column(String(40), nullable=False)
    model: Mapped[str | None] = mapped_column(String(120))
    attempt: Mapped[int] = mapped_column(Integer, nullable=False, server_default=text("1"))
    status: Mapped[str] = mapped_column(String(30), nullable=False)
    started_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    finished_at: Mapped[dt.datetime | None] = mapped_column(TZDateTime)
    input_tokens: Mapped[int | None] = mapped_column(Integer)
    output_tokens: Mapped[int | None] = mapped_column(Integer)
    #: Populated when the output failed the provenance/assertion contract.
    contract_violations: Mapped[dict] = mapped_column(
        JSONB, nullable=False, server_default=text("'[]'::jsonb")
    )
    error_message: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[dt.datetime] = utcnow_column()


class SchemaFingerprint(Base):
    """Hash of each registry file as loaded, so a silent config edit is detectable."""

    __tablename__ = "schema_fingerprint"
    __table_args__ = (
        UniqueConstraint("file_name", "content_hash", name="uq_fingerprint_file_hash"),
        {"schema": SCHEMA_OPS},
    )

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    file_name: Mapped[str] = mapped_column(String(80), nullable=False)
    content_hash: Mapped[str] = mapped_column(String(64), nullable=False)
    loaded_at: Mapped[dt.datetime] = utcnow_column()
    note: Mapped[str | None] = mapped_column(Text)
