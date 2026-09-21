"""model.*, everything a model produced. Written by code, never by an agent.

Two guarantees are enforced in the database itself (migration 0001):

1. Append-only. ``model.forecast`` has triggers rejecting UPDATE and DELETE, so
   a forecast vintage cannot be rewritten after the fact (principle 7).
2. No look-ahead. ``feature_vintage <= forecast_created_at`` and
   ``target_date >= forecast_created_at::date`` are CHECK constraints, so a
   forecast that used the future cannot be stored (principle 8).
"""

from __future__ import annotations

import datetime as dt
import uuid

from sqlalchemy import (
    CheckConstraint,
    Date,
    Float,
    ForeignKey,
    Index,
    Integer,
    PrimaryKeyConstraint,
    SmallInteger,
    String,
    Text,
    UniqueConstraint,
    text,
)
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy.orm import Mapped, mapped_column

from barometer.db.base import SCHEMA_MODEL, Base, TZDateTime, utcnow_column


class ModelRun(Base):
    """One fit of one model, with everything needed to reproduce it."""

    __tablename__ = "model_run"
    __table_args__ = (
        UniqueConstraint("model_name", "model_version", "trained_at", name="uq_model_run_identity"),
        CheckConstraint(
            "training_window_end >= training_window_start",
            name="training_window_ordered",
        ),
        CheckConstraint("feature_vintage <= trained_at", name="cannot_train_on_future_features"),
        CheckConstraint(
            "role IN ('benchmark','candidate','production','experiment')",
            name="role_is_known",
        ),
        Index("ix_model_run_name_trained", "model_name", "trained_at"),
        {"schema": SCHEMA_MODEL},
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    model_name: Mapped[str] = mapped_column(String(80), nullable=False)
    model_version: Mapped[str] = mapped_column(String(40), nullable=False)
    role: Mapped[str] = mapped_column(String(20), nullable=False)
    code_git_sha: Mapped[str | None] = mapped_column(String(64))
    feature_set_version: Mapped[str] = mapped_column(String(40), nullable=False)
    feature_vintage: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    trained_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    training_window_start: Mapped[dt.date] = mapped_column(Date, nullable=False)
    training_window_end: Mapped[dt.date] = mapped_column(Date, nullable=False)
    #: Fitted parameters, including dynamic-factor loadings. Never hand-written.
    params: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    metrics: Mapped[dict] = mapped_column(JSONB, nullable=False, server_default=text("'{}'::jsonb"))
    notes: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[dt.datetime] = utcnow_column()


class IndexValue(Base):
    """A published barometer reading, per index, per date, per vintage."""

    __tablename__ = "index_value"
    __table_args__ = (
        PrimaryKeyConstraint("as_of_date", "index_code", "vintage_at", name="pk_index_value"),
        CheckConstraint("confidence >= 0 AND confidence <= 1", name="confidence_is_a_fraction"),
        CheckConstraint(
            "value IS NOT NULL OR insufficient_data_reason IS NOT NULL",
            name="absent_index_has_a_reason",
        ),
        Index("ix_index_value_code_date", "index_code", "as_of_date"),
        {"schema": SCHEMA_MODEL},
    )

    as_of_date: Mapped[dt.date] = mapped_column(Date, nullable=False)
    index_code: Mapped[str] = mapped_column(String(80), nullable=False)
    vintage_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)

    value: Mapped[float | None] = mapped_column(Float)
    insufficient_data_reason: Mapped[str | None] = mapped_column(Text)
    seasonally_adjusted: Mapped[bool] = mapped_column(nullable=False, server_default=text("true"))
    seasonal_baseline: Mapped[float | None] = mapped_column(Float)
    deviation_from_seasonal: Mapped[float | None] = mapped_column(Float)
    wow_change: Mapped[float | None] = mapped_column(Float)
    #: Index movement year on year. This is NOT a sales percentage while
    #: config/index_definition.yaml has calibration.status = uncalibrated.
    yoy_index_change: Mapped[float | None] = mapped_column(Float)
    confidence: Mapped[float | None] = mapped_column(Float)
    clipped: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))

    #: Per-component contribution from the fitted factor loadings.
    contributions: Mapped[dict] = mapped_column(
        JSONB, nullable=False, server_default=text("'{}'::jsonb")
    )
    coverage: Mapped[dict] = mapped_column(
        JSONB, nullable=False, server_default=text("'{}'::jsonb")
    )
    model_run_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_MODEL}.model_run.id", ondelete="RESTRICT"), nullable=False
    )
    created_at: Mapped[dt.datetime] = utcnow_column()


class Forecast(Base):
    """One forecast vintage. Immutable, by trigger.

    Every row answers: on this date, with this model and these feature vintages,
    what did we say about that future date, and how sure were we?
    """

    __tablename__ = "forecast"
    __table_args__ = (
        PrimaryKeyConstraint("forecast_created_at", "id", name="pk_forecast"),
        UniqueConstraint(
            "model_run_id",
            "target_code",
            "target_date",
            "interval_level",
            "forecast_created_at",
            name="uq_forecast_identity",
        ),
        # Principle 8: a forecast may not be made from data published after it.
        CheckConstraint(
            "feature_vintage <= forecast_created_at",
            name="no_look_ahead_in_features",
        ),
        CheckConstraint(
            "target_date >= (forecast_created_at AT TIME ZONE 'UTC')::date",
            name="target_is_not_in_the_past",
        ),
        CheckConstraint("horizon_days > 0", name="horizon_is_positive"),
        CheckConstraint(
            "interval_level > 0 AND interval_level < 1",
            name="interval_level_is_a_fraction",
        ),
        CheckConstraint(
            "lower_bound IS NULL OR upper_bound IS NULL OR lower_bound <= upper_bound",
            name="interval_ordered",
        ),
        CheckConstraint(
            "point_forecast IS NULL "
            "OR lower_bound IS NULL "
            "OR (point_forecast >= lower_bound AND point_forecast <= upper_bound)",
            name="point_inside_interval",
        ),
        # Principle 5 and the calibration gate, in the database: a row may only
        # call itself a market sales forecast when it was produced under a
        # calibrated model run.
        CheckConstraint(
            "quantity IN ('index','demand_estimate','market_sales')",
            name="quantity_is_known",
        ),
        CheckConstraint(
            "quantity <> 'market_sales' OR calibration_run_id IS NOT NULL",
            name="sales_forecast_requires_calibration",
        ),
        Index("ix_forecast_target", "target_code", "target_date"),
        Index("ix_forecast_created", "forecast_created_at"),
        Index("ix_forecast_run", "model_run_id"),
        {"schema": SCHEMA_MODEL},
    )

    forecast_created_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), nullable=False, server_default=text("gen_random_uuid()")
    )

    model_run_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_MODEL}.model_run.id", ondelete="RESTRICT"), nullable=False
    )
    target_code: Mapped[str] = mapped_column(String(160), nullable=False)
    target_date: Mapped[dt.date] = mapped_column(Date, nullable=False)
    horizon_days: Mapped[int] = mapped_column(SmallInteger, nullable=False)

    quantity: Mapped[str] = mapped_column(
        String(24), nullable=False, server_default=text("'index'")
    )
    point_forecast: Mapped[float | None] = mapped_column(Float)
    lower_bound: Mapped[float | None] = mapped_column(Float)
    upper_bound: Mapped[float | None] = mapped_column(Float)
    interval_level: Mapped[float] = mapped_column(Float, nullable=False)

    model_version: Mapped[str] = mapped_column(String(40), nullable=False)
    feature_vintage: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    feature_set_version: Mapped[str] = mapped_column(String(40), nullable=False)
    calibration_run_id: Mapped[uuid.UUID | None] = mapped_column(
        ForeignKey(f"{SCHEMA_MODEL}.model_run.id", ondelete="RESTRICT")
    )
    is_backtest: Mapped[bool] = mapped_column(nullable=False, server_default=text("false"))
    backtest_fold: Mapped[int | None] = mapped_column(Integer)
    created_at: Mapped[dt.datetime] = utcnow_column()


class ForecastEvaluation(Base):
    """A forecast scored against what actually happened.

    Written only once the realised value exists, and it points at both, so the
    accuracy page is auditable rather than asserted.
    """

    __tablename__ = "forecast_evaluation"
    __table_args__ = (
        PrimaryKeyConstraint("evaluated_at", "forecast_id", name="pk_forecast_evaluation"),
        CheckConstraint(
            "realised_vintage_at >= (target_date + INTERVAL '1 day')",
            name="scored_only_after_the_target_period_closed",
        ),
        Index("ix_forecast_eval_target", "target_code", "target_date"),
        {"schema": SCHEMA_MODEL},
    )

    evaluated_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    forecast_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False)
    forecast_created_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    target_code: Mapped[str] = mapped_column(String(160), nullable=False)
    target_date: Mapped[dt.date] = mapped_column(Date, nullable=False)
    horizon_days: Mapped[int] = mapped_column(SmallInteger, nullable=False)

    realised_value: Mapped[float] = mapped_column(Float, nullable=False)
    realised_vintage_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    error: Mapped[float] = mapped_column(Float, nullable=False)
    absolute_error: Mapped[float] = mapped_column(Float, nullable=False)
    squared_error: Mapped[float] = mapped_column(Float, nullable=False)
    inside_interval: Mapped[bool | None] = mapped_column()
    benchmark_absolute_error: Mapped[float | None] = mapped_column(Float)
    #: < 1 means the model beat the seasonal-naive benchmark on this point.
    skill_vs_benchmark: Mapped[float | None] = mapped_column(Float)
    created_at: Mapped[dt.datetime] = utcnow_column()


class Backtest(Base):
    """Summary of a rolling-origin backtest, the only basis for model promotion."""

    __tablename__ = "backtest"
    __table_args__ = (
        UniqueConstraint("model_run_id", "scheme", "finished_at", name="uq_backtest_identity"),
        CheckConstraint("folds >= 1", name="backtest_has_folds"),
        CheckConstraint(
            "interval_coverage IS NULL OR (interval_coverage >= 0 AND interval_coverage <= 1)",
            name="coverage_is_a_fraction",
        ),
        {"schema": SCHEMA_MODEL},
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, server_default=text("gen_random_uuid()")
    )
    model_run_id: Mapped[uuid.UUID] = mapped_column(
        ForeignKey(f"{SCHEMA_MODEL}.model_run.id", ondelete="RESTRICT"), nullable=False
    )
    scheme: Mapped[str] = mapped_column(String(40), nullable=False)
    folds: Mapped[int] = mapped_column(Integer, nullable=False)
    initial_train_days: Mapped[int] = mapped_column(Integer, nullable=False)
    step_days: Mapped[int] = mapped_column(Integer, nullable=False)
    horizon_days: Mapped[int] = mapped_column(SmallInteger, nullable=False)
    mae: Mapped[float | None] = mapped_column(Float)
    rmse: Mapped[float | None] = mapped_column(Float)
    mase_vs_seasonal_naive: Mapped[float | None] = mapped_column(Float)
    interval_level: Mapped[float | None] = mapped_column(Float)
    interval_coverage: Mapped[float | None] = mapped_column(Float)
    per_fold: Mapped[dict] = mapped_column(
        JSONB, nullable=False, server_default=text("'{}'::jsonb")
    )
    finished_at: Mapped[dt.datetime] = mapped_column(TZDateTime, nullable=False)
    created_at: Mapped[dt.datetime] = utcnow_column()
