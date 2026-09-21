"""initial schema

Revision ID: 0001
Revises: 
Create Date: 2026-09-21 09:28:33.943179
"""
from __future__ import annotations

from collections.abc import Sequence

from pathlib import Path

from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision: str = '0001'
down_revision: str | None = None
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None


DDL_DIR = Path(__file__).resolve().parents[1] / "ddl"


def _run_sql(name: str) -> None:
    """Execute a hand-written DDL file that Alembic cannot autogenerate."""
    sql = (DDL_DIR / name).read_text(encoding="utf-8")
    op.execute(sa.text(sql))


def upgrade() -> None:
    _run_sql("0001_prologue.sql")
    op.create_table('feature_definition',
    sa.Column('feature_code', sa.String(length=160), nullable=False),
    sa.Column('name_ru', sa.Text(), nullable=False),
    sa.Column('family', sa.String(length=40), nullable=False),
    sa.Column('description_ru', sa.Text(), nullable=False),
    sa.Column('transform', sa.String(length=120), nullable=False),
    sa.Column('input_series', postgresql.ARRAY(sa.Text()), server_default=sa.text("'{}'::text[]"), nullable=False),
    sa.Column('calendar_exempt', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("calendar_exempt IS FALSE OR family = 'calendar'", name=op.f('ck_feature_definition_only_calendar_features_are_lag_exempt')),
    sa.PrimaryKeyConstraint('feature_code', name=op.f('pk_feature_definition')),
    schema='feature'
    )
    op.create_table('feature_value',
    sa.Column('as_of_date', sa.Date(), nullable=False),
    sa.Column('feature_code', sa.String(length=160), nullable=False),
    sa.Column('vintage_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('value', sa.Float(), nullable=True),
    sa.Column('missing_reason', sa.String(length=60), nullable=True),
    sa.Column('feature_set_version', sa.String(length=40), nullable=False),
    sa.Column('max_input_available_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('computed_from', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('quality_flags', postgresql.ARRAY(sa.Text()), server_default=sa.text("'{}'::text[]"), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('max_input_available_at <= vintage_at', name=op.f('ck_feature_value_feature_cannot_precede_its_inputs')),
    sa.CheckConstraint('value IS NOT NULL OR missing_reason IS NOT NULL', name=op.f('ck_feature_value_absent_feature_has_a_reason')),
    sa.PrimaryKeyConstraint('as_of_date', 'feature_code', 'vintage_at', name='pk_feature_value'),
    schema='feature'
    )
    op.create_index('ix_feature_value_available', 'feature_value', ['max_input_available_at'], unique=False, schema='feature')
    op.create_index('ix_feature_value_code_date', 'feature_value', ['feature_code', 'as_of_date'], unique=False, schema='feature')
    op.create_table('forecast_evaluation',
    sa.Column('evaluated_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('forecast_id', sa.UUID(), nullable=False),
    sa.Column('forecast_created_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('target_code', sa.String(length=160), nullable=False),
    sa.Column('target_date', sa.Date(), nullable=False),
    sa.Column('horizon_days', sa.SmallInteger(), nullable=False),
    sa.Column('realised_value', sa.Float(), nullable=False),
    sa.Column('realised_vintage_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('error', sa.Float(), nullable=False),
    sa.Column('absolute_error', sa.Float(), nullable=False),
    sa.Column('squared_error', sa.Float(), nullable=False),
    sa.Column('inside_interval', sa.Boolean(), nullable=True),
    sa.Column('benchmark_absolute_error', sa.Float(), nullable=True),
    sa.Column('skill_vs_benchmark', sa.Float(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("realised_vintage_at >= (target_date + INTERVAL '1 day')", name=op.f('ck_forecast_evaluation_scored_only_after_the_target_period_closed')),
    sa.PrimaryKeyConstraint('evaluated_at', 'forecast_id', name='pk_forecast_evaluation'),
    schema='model'
    )
    op.create_index('ix_forecast_eval_target', 'forecast_evaluation', ['target_code', 'target_date'], unique=False, schema='model')
    op.create_table('model_run',
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('model_name', sa.String(length=80), nullable=False),
    sa.Column('model_version', sa.String(length=40), nullable=False),
    sa.Column('role', sa.String(length=20), nullable=False),
    sa.Column('code_git_sha', sa.String(length=64), nullable=True),
    sa.Column('feature_set_version', sa.String(length=40), nullable=False),
    sa.Column('feature_vintage', sa.DateTime(timezone=True), nullable=False),
    sa.Column('trained_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('training_window_start', sa.Date(), nullable=False),
    sa.Column('training_window_end', sa.Date(), nullable=False),
    sa.Column('params', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('metrics', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('notes', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("role IN ('benchmark','candidate','production','experiment')", name=op.f('ck_model_run_role_is_known')),
    sa.CheckConstraint('feature_vintage <= trained_at', name=op.f('ck_model_run_cannot_train_on_future_features')),
    sa.CheckConstraint('training_window_end >= training_window_start', name=op.f('ck_model_run_training_window_ordered')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_model_run')),
    sa.UniqueConstraint('model_name', 'model_version', 'trained_at', name='uq_model_run_identity'),
    schema='model'
    )
    op.create_index('ix_model_run_name_trained', 'model_run', ['model_name', 'trained_at'], unique=False, schema='model')
    op.create_table('data_quality_check',
    sa.Column('id', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('check_code', sa.String(length=120), nullable=False),
    sa.Column('target_kind', sa.String(length=40), nullable=False),
    sa.Column('target_code', sa.String(length=160), nullable=False),
    sa.Column('as_of_date', sa.Date(), nullable=False),
    sa.Column('passed', sa.Boolean(), nullable=False),
    sa.Column('severity', sa.String(length=10), nullable=False),
    sa.Column('blocking', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('actor', sa.String(length=120), nullable=False),
    sa.Column('detail', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('message_ru', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("severity IN ('info','warning','critical')", name=op.f('ck_data_quality_check_severity_is_known')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_data_quality_check')),
    schema='ops'
    )
    op.create_index('ix_dq_check_failed', 'data_quality_check', ['passed', 'as_of_date'], unique=False, schema='ops')
    op.create_index('ix_dq_check_target_date', 'data_quality_check', ['target_code', 'as_of_date'], unique=False, schema='ops')
    op.create_table('pipeline_run',
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('pipeline', sa.String(length=80), nullable=False),
    sa.Column('stage', sa.String(length=80), nullable=True),
    sa.Column('actor', sa.String(length=120), nullable=False),
    sa.Column('status', sa.String(length=20), nullable=False),
    sa.Column('started_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('finished_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('params', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('records_written', sa.BigInteger(), server_default=sa.text('0'), nullable=False),
    sa.Column('error_type', sa.String(length=120), nullable=True),
    sa.Column('error_message', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("status IN ('running','succeeded','failed','degraded','skipped')", name=op.f('ck_pipeline_run_status_is_known')),
    sa.CheckConstraint('finished_at IS NULL OR finished_at >= started_at', name=op.f('ck_pipeline_run_run_duration_non_negative')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_pipeline_run')),
    schema='ops'
    )
    op.create_index('ix_pipeline_run_pipeline_started', 'pipeline_run', ['pipeline', 'started_at'], unique=False, schema='ops')
    op.create_table('schema_fingerprint',
    sa.Column('id', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('file_name', sa.String(length=80), nullable=False),
    sa.Column('content_hash', sa.String(length=64), nullable=False),
    sa.Column('loaded_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('note', sa.Text(), nullable=True),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_schema_fingerprint')),
    sa.UniqueConstraint('file_name', 'content_hash', name='uq_fingerprint_file_hash'),
    schema='ops'
    )
    op.create_table('segment',
    sa.Column('code', sa.String(length=120), nullable=False),
    sa.Column('name_ru', sa.Text(), nullable=False),
    sa.Column('kind', sa.String(length=32), nullable=False),
    sa.Column('parent_code', sa.String(length=120), nullable=True),
    sa.Column('display_order', sa.Integer(), nullable=False),
    sa.Column('unit_class', postgresql.ENUM('index', 'money_nominal', 'count', 'percent', 'physical', name='unit_class', schema='registry', create_type=False), nullable=False),
    sa.Column('min_series', sa.SmallInteger(), server_default=sa.text('1'), nullable=False),
    sa.Column('venue_category', postgresql.ENUM('jazz_club', 'music_club', 'concert_hall', 'theatre', 'standup', 'festival', 'restaurant_bar', name='venue_category', schema='registry', create_type=False), nullable=True),
    sa.Column('proxy_badge_ru', sa.Text(), nullable=True),
    sa.Column('visible_in_ui', sa.Boolean(), server_default=sa.text('true'), nullable=False),
    sa.CheckConstraint('min_series >= 0', name=op.f('ck_segment_min_series_non_negative')),
    sa.CheckConstraint('parent_code IS NULL OR parent_code <> code', name=op.f('ck_segment_no_self_parent')),
    sa.ForeignKeyConstraint(['parent_code'], ['registry.segment.code'], name=op.f('fk_segment_parent_code_segment'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('code', name=op.f('pk_segment')),
    schema='registry'
    )
    op.create_table('source',
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('code', sa.String(length=120), nullable=False),
    sa.Column('name', sa.Text(), nullable=False),
    sa.Column('name_ru', sa.Text(), nullable=False),
    sa.Column('kind', postgresql.ENUM('api', 'official_stat', 'web_page', 'licensed_feed', 'manual', 'file_upload', name='source_kind', schema='registry', create_type=False), nullable=False),
    sa.Column('publisher', sa.Text(), nullable=False),
    sa.Column('homepage_url', sa.Text(), nullable=True),
    sa.Column('endpoint_url', sa.Text(), nullable=True),
    sa.Column('geography', sa.String(length=16), nullable=False),
    sa.Column('access_policy', postgresql.ENUM('official_api', 'licensed', 'public_page', 'manual_export', 'authorised', 'pending_review', name='access_policy', schema='registry', create_type=False), nullable=False),
    sa.Column('requires_credentials', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('credential_env_var', sa.String(length=120), nullable=True),
    sa.Column('robots_policy', postgresql.ENUM('allowed', 'disallowed', 'unknown', 'not_applicable', name='robots_policy', schema='registry', create_type=False), nullable=False),
    sa.Column('robots_checked_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('rate_limit_per_min', sa.Integer(), nullable=True),
    sa.Column('update_cadence', postgresql.ENUM('hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'irregular', name='cadence', schema='registry', create_type=False), nullable=False),
    sa.Column('typical_lag_days', sa.Integer(), nullable=False),
    sa.Column('license', sa.Text(), nullable=True),
    sa.Column('terms_url', sa.Text(), nullable=True),
    sa.Column('is_proxy', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('proxy_for', sa.Text(), nullable=True),
    sa.Column('methodology_notes', sa.Text(), nullable=True),
    sa.Column('verification_required', postgresql.ARRAY(sa.Text()), server_default=sa.text("'{}'::text[]"), nullable=False),
    sa.Column('enabled', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("NOT enabled OR robots_policy IN ('allowed', 'not_applicable')", name=op.f('ck_source_enabled_requires_resolved_robots')),
    sa.CheckConstraint('NOT is_proxy OR proxy_for IS NOT NULL', name=op.f('ck_source_proxy_declares_target')),
    sa.CheckConstraint('NOT requires_credentials OR credential_env_var IS NOT NULL', name=op.f('ck_source_credentials_are_named')),
    sa.CheckConstraint('typical_lag_days >= 0', name=op.f('ck_source_lag_is_non_negative')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_source')),
    sa.UniqueConstraint('code', name=op.f('uq_source_code')),
    schema='registry'
    )
    op.create_table('venue',
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('code', sa.String(length=120), nullable=False),
    sa.Column('canonical_name', sa.Text(), nullable=False),
    sa.Column('canonical_name_ru', sa.Text(), nullable=False),
    sa.Column('category', postgresql.ENUM('jazz_club', 'music_club', 'concert_hall', 'theatre', 'standup', 'festival', 'restaurant_bar', name='venue_category', schema='registry', create_type=False), nullable=False),
    sa.Column('city', sa.String(length=80), server_default=sa.text("'Москва'"), nullable=False),
    sa.Column('geography', sa.String(length=16), server_default=sa.text("'RU-MOW'"), nullable=False),
    sa.Column('website_url', sa.Text(), nullable=True),
    sa.Column('url_verified', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('ticket_providers', postgresql.ARRAY(sa.Text()), server_default=sa.text("'{}'::text[]"), nullable=False),
    sa.Column('relationship_type', sa.String(length=24), server_default=sa.text("'panel'"), nullable=False),
    sa.Column('source_access_policy', postgresql.ENUM('official_api', 'licensed', 'public_page', 'manual_export', 'authorised', 'pending_review', name='access_policy', schema='registry', create_type=False), nullable=False),
    sa.Column('robots_allows_crawl', sa.Boolean(), nullable=True),
    sa.Column('robots_checked_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('access_reviewed_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('capacity', sa.Integer(), nullable=True),
    sa.Column('capacity_source_url', sa.Text(), nullable=True),
    sa.Column('capacity_source_note', sa.Text(), nullable=True),
    sa.Column('enabled', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('notes_ru', sa.Text(), nullable=True),
    sa.Column('extra', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("\n            NOT enabled\n            OR relationship_type = 'first_party'\n            OR (url_verified AND robots_allows_crawl AND access_reviewed_at IS NOT NULL)\n            ", name=op.f('ck_venue_enabled_requires_review')),
    sa.CheckConstraint('capacity IS NULL OR (capacity > 0 AND capacity <= 200000)', name=op.f('ck_venue_capacity_is_plausible')),
    sa.CheckConstraint('capacity IS NULL OR capacity_source_url IS NOT NULL', name=op.f('ck_venue_capacity_only_if_sourced')),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_venue')),
    sa.UniqueConstraint('code', name=op.f('uq_venue_code')),
    schema='registry'
    )
    op.create_index('ix_venue_category', 'venue', ['category'], unique=False, schema='registry')
    op.create_table('backtest',
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('model_run_id', sa.UUID(), nullable=False),
    sa.Column('scheme', sa.String(length=40), nullable=False),
    sa.Column('folds', sa.Integer(), nullable=False),
    sa.Column('initial_train_days', sa.Integer(), nullable=False),
    sa.Column('step_days', sa.Integer(), nullable=False),
    sa.Column('horizon_days', sa.SmallInteger(), nullable=False),
    sa.Column('mae', sa.Float(), nullable=True),
    sa.Column('rmse', sa.Float(), nullable=True),
    sa.Column('mase_vs_seasonal_naive', sa.Float(), nullable=True),
    sa.Column('interval_level', sa.Float(), nullable=True),
    sa.Column('interval_coverage', sa.Float(), nullable=True),
    sa.Column('per_fold', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('finished_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('folds >= 1', name=op.f('ck_backtest_backtest_has_folds')),
    sa.CheckConstraint('interval_coverage IS NULL OR (interval_coverage >= 0 AND interval_coverage <= 1)', name=op.f('ck_backtest_coverage_is_a_fraction')),
    sa.ForeignKeyConstraint(['model_run_id'], ['model.model_run.id'], name=op.f('fk_backtest_model_run_id_model_run'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_backtest')),
    sa.UniqueConstraint('model_run_id', 'scheme', 'finished_at', name='uq_backtest_identity'),
    schema='model'
    )
    op.create_table('forecast',
    sa.Column('forecast_created_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('model_run_id', sa.UUID(), nullable=False),
    sa.Column('target_code', sa.String(length=160), nullable=False),
    sa.Column('target_date', sa.Date(), nullable=False),
    sa.Column('horizon_days', sa.SmallInteger(), nullable=False),
    sa.Column('quantity', sa.String(length=24), server_default=sa.text("'index'"), nullable=False),
    sa.Column('point_forecast', sa.Float(), nullable=True),
    sa.Column('lower_bound', sa.Float(), nullable=True),
    sa.Column('upper_bound', sa.Float(), nullable=True),
    sa.Column('interval_level', sa.Float(), nullable=False),
    sa.Column('model_version', sa.String(length=40), nullable=False),
    sa.Column('feature_vintage', sa.DateTime(timezone=True), nullable=False),
    sa.Column('feature_set_version', sa.String(length=40), nullable=False),
    sa.Column('calibration_run_id', sa.UUID(), nullable=True),
    sa.Column('is_backtest', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('backtest_fold', sa.Integer(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("quantity <> 'market_sales' OR calibration_run_id IS NOT NULL", name=op.f('ck_forecast_sales_forecast_requires_calibration')),
    sa.CheckConstraint("quantity IN ('index','demand_estimate','market_sales')", name=op.f('ck_forecast_quantity_is_known')),
    sa.CheckConstraint("target_date >= (forecast_created_at AT TIME ZONE 'UTC')::date", name=op.f('ck_forecast_target_is_not_in_the_past')),
    sa.CheckConstraint('feature_vintage <= forecast_created_at', name=op.f('ck_forecast_no_look_ahead_in_features')),
    sa.CheckConstraint('horizon_days > 0', name=op.f('ck_forecast_horizon_is_positive')),
    sa.CheckConstraint('interval_level > 0 AND interval_level < 1', name=op.f('ck_forecast_interval_level_is_a_fraction')),
    sa.CheckConstraint('lower_bound IS NULL OR upper_bound IS NULL OR lower_bound <= upper_bound', name=op.f('ck_forecast_interval_ordered')),
    sa.CheckConstraint('point_forecast IS NULL OR lower_bound IS NULL OR (point_forecast >= lower_bound AND point_forecast <= upper_bound)', name=op.f('ck_forecast_point_inside_interval')),
    sa.ForeignKeyConstraint(['calibration_run_id'], ['model.model_run.id'], name=op.f('fk_forecast_calibration_run_id_model_run'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['model_run_id'], ['model.model_run.id'], name=op.f('fk_forecast_model_run_id_model_run'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('forecast_created_at', 'id', name='pk_forecast'),
    sa.UniqueConstraint('model_run_id', 'target_code', 'target_date', 'interval_level', 'forecast_created_at', name='uq_forecast_identity'),
    schema='model'
    )
    op.create_index('ix_forecast_created', 'forecast', ['forecast_created_at'], unique=False, schema='model')
    op.create_index('ix_forecast_run', 'forecast', ['model_run_id'], unique=False, schema='model')
    op.create_index('ix_forecast_target', 'forecast', ['target_code', 'target_date'], unique=False, schema='model')
    op.create_table('index_value',
    sa.Column('as_of_date', sa.Date(), nullable=False),
    sa.Column('index_code', sa.String(length=80), nullable=False),
    sa.Column('vintage_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('value', sa.Float(), nullable=True),
    sa.Column('insufficient_data_reason', sa.Text(), nullable=True),
    sa.Column('seasonally_adjusted', sa.Boolean(), server_default=sa.text('true'), nullable=False),
    sa.Column('seasonal_baseline', sa.Float(), nullable=True),
    sa.Column('deviation_from_seasonal', sa.Float(), nullable=True),
    sa.Column('wow_change', sa.Float(), nullable=True),
    sa.Column('yoy_index_change', sa.Float(), nullable=True),
    sa.Column('confidence', sa.Float(), nullable=True),
    sa.Column('clipped', sa.Boolean(), server_default=sa.text('false'), nullable=False),
    sa.Column('contributions', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('coverage', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('model_run_id', sa.UUID(), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('confidence >= 0 AND confidence <= 1', name=op.f('ck_index_value_confidence_is_a_fraction')),
    sa.CheckConstraint('value IS NOT NULL OR insufficient_data_reason IS NOT NULL', name=op.f('ck_index_value_absent_index_has_a_reason')),
    sa.ForeignKeyConstraint(['model_run_id'], ['model.model_run.id'], name=op.f('fk_index_value_model_run_id_model_run'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('as_of_date', 'index_code', 'vintage_at', name='pk_index_value'),
    schema='model'
    )
    op.create_index('ix_index_value_code_date', 'index_value', ['index_code', 'as_of_date'], unique=False, schema='model')
    op.create_table('agent_invocation',
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('run_id', sa.UUID(), nullable=True),
    sa.Column('agent_code', sa.String(length=80), nullable=False),
    sa.Column('provider', sa.String(length=40), nullable=False),
    sa.Column('model', sa.String(length=120), nullable=True),
    sa.Column('attempt', sa.Integer(), server_default=sa.text('1'), nullable=False),
    sa.Column('status', sa.String(length=30), nullable=False),
    sa.Column('started_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('finished_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('input_tokens', sa.Integer(), nullable=True),
    sa.Column('output_tokens', sa.Integer(), nullable=True),
    sa.Column('contract_violations', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'[]'::jsonb"), nullable=False),
    sa.Column('error_message', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("status IN ('succeeded','failed','rejected_by_contract','timed_out')", name=op.f('ck_agent_invocation_agent_status_is_known')),
    sa.ForeignKeyConstraint(['run_id'], ['ops.pipeline_run.id'], name=op.f('fk_agent_invocation_run_id_pipeline_run'), ondelete='SET NULL'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_agent_invocation')),
    schema='ops'
    )
    op.create_index('ix_agent_invocation_agent_started', 'agent_invocation', ['agent_code', 'started_at'], unique=False, schema='ops')
    op.create_table('source_health',
    sa.Column('source_id', sa.UUID(), nullable=False),
    sa.Column('status', sa.String(length=20), server_default=sa.text("'never_run'"), nullable=False),
    sa.Column('last_attempt_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('last_success_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('last_observation_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('freshness_hours', sa.Float(), nullable=True),
    sa.Column('expected_freshness_hours', sa.Float(), nullable=True),
    sa.Column('consecutive_failures', sa.Integer(), server_default=sa.text('0'), nullable=False),
    sa.Column('last_error', sa.Text(), nullable=True),
    sa.Column('robots_last_checked_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('robots_result', sa.String(length=40), nullable=True),
    sa.Column('updated_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("status IN ('healthy','stale','failing','blocked','disabled','never_run')", name=op.f('ck_source_health_health_status_is_known')),
    sa.CheckConstraint('consecutive_failures >= 0', name=op.f('ck_source_health_failures_non_negative')),
    sa.ForeignKeyConstraint(['source_id'], ['registry.source.id'], name=op.f('fk_source_health_source_id_source'), ondelete='CASCADE'),
    sa.PrimaryKeyConstraint('source_id', name=op.f('pk_source_health')),
    schema='ops'
    )
    op.create_table('snapshot',
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('source_id', sa.UUID(), nullable=False),
    sa.Column('venue_id', sa.UUID(), nullable=True),
    sa.Column('idempotency_key', sa.String(length=200), nullable=False),
    sa.Column('fetched_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('request_url', sa.Text(), nullable=True),
    sa.Column('request_method', sa.String(length=10), server_default=sa.text("'GET'"), nullable=False),
    sa.Column('request_params', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('http_status', sa.SmallInteger(), nullable=True),
    sa.Column('content_type', sa.String(length=120), nullable=True),
    sa.Column('content_hash', sa.String(length=64), nullable=False),
    sa.Column('content_bytes', sa.BigInteger(), nullable=True),
    sa.Column('storage_uri', sa.Text(), nullable=True),
    sa.Column('content_inline', sa.Text(), nullable=True),
    sa.Column('retention', sa.String(length=24), server_default=sa.text("'retained'"), nullable=False),
    sa.Column('legal_basis', sa.String(length=24), nullable=False),
    sa.Column('observation_period_start', sa.DateTime(timezone=True), nullable=True),
    sa.Column('observation_period_end', sa.DateTime(timezone=True), nullable=True),
    sa.Column('geography', sa.String(length=16), nullable=True),
    sa.Column('collector', sa.String(length=120), nullable=False),
    sa.Column('collector_version', sa.String(length=40), nullable=False),
    sa.Column('run_id', sa.UUID(), nullable=True),
    sa.Column('notes', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("content_hash ~ '^[0-9a-f]{64}$'", name=op.f('ck_snapshot_content_hash_is_sha256')),
    sa.CheckConstraint("legal_basis IN ('official_api', 'licensed', 'public_permitted', 'user_supplied', 'manual_entry')", name=op.f('ck_snapshot_legal_basis_is_known')),
    sa.CheckConstraint("storage_uri IS NOT NULL OR content_inline IS NOT NULL OR retention = 'not_retained'", name=op.f('ck_snapshot_bytes_are_somewhere_or_declared_absent')),
    sa.CheckConstraint('observation_period_start IS NULL OR observation_period_end IS NULL OR observation_period_end >= observation_period_start', name=op.f('ck_snapshot_observation_period_ordered')),
    sa.ForeignKeyConstraint(['source_id'], ['registry.source.id'], name=op.f('fk_snapshot_source_id_source'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['venue_id'], ['registry.venue.id'], name=op.f('fk_snapshot_venue_id_venue'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_snapshot')),
    sa.UniqueConstraint('idempotency_key', name='uq_snapshot_idempotency_key'),
    schema='raw'
    )
    op.create_index('ix_snapshot_content_hash', 'snapshot', ['content_hash'], unique=False, schema='raw')
    op.create_index('ix_snapshot_run_id', 'snapshot', ['run_id'], unique=False, schema='raw')
    op.create_index('ix_snapshot_source_fetched', 'snapshot', ['source_id', 'fetched_at'], unique=False, schema='raw')
    op.create_table('series',
    sa.Column('id', sa.UUID(), server_default=sa.text('gen_random_uuid()'), nullable=False),
    sa.Column('code', sa.String(length=160), nullable=False),
    sa.Column('source_id', sa.UUID(), nullable=False),
    sa.Column('segment_code', sa.String(length=120), nullable=False),
    sa.Column('name_ru', sa.Text(), nullable=False),
    sa.Column('unit', sa.String(length=60), nullable=False),
    sa.Column('frequency', postgresql.ENUM('daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'hourly', 'irregular', name='frequency', schema='registry', create_type=False), nullable=False),
    sa.Column('measures', postgresql.ENUM('direct', 'derived', 'inferred', name='measures', schema='registry', create_type=False), nullable=False),
    sa.Column('assertion_class', postgresql.ENUM('fact', 'derived_metric', 'estimate', 'hypothesis', 'forecast', name='assertion_class', schema='registry', create_type=False), nullable=False),
    sa.Column('aggregation', postgresql.ENUM('sum', 'mean', 'last', 'median', 'index', name='aggregation', schema='registry', create_type=False), nullable=False),
    sa.Column('description_ru', sa.Text(), nullable=True),
    sa.Column('proxy_caveat_ru', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("\n            (measures = 'direct')\n            OR (measures = 'derived'\n                AND assertion_class IN ('derived_metric','estimate','hypothesis','forecast'))\n            OR (measures = 'inferred'\n                AND assertion_class IN ('estimate','hypothesis','forecast'))\n            ", name=op.f('ck_series_claim_matches_what_is_measured')),
    sa.ForeignKeyConstraint(['segment_code'], ['registry.segment.code'], name=op.f('fk_series_segment_code_segment'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['source_id'], ['registry.source.id'], name=op.f('fk_series_source_id_source'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_series')),
    sa.UniqueConstraint('code', name='uq_series_code'),
    schema='registry'
    )
    op.create_index('ix_series_segment_code', 'series', ['segment_code'], unique=False, schema='registry')
    op.create_index('ix_series_source_id', 'series', ['source_id'], unique=False, schema='registry')
    op.create_table('event_diff',
    sa.Column('detected_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('id', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('venue_id', sa.UUID(), nullable=False),
    sa.Column('event_key', sa.String(length=200), nullable=False),
    sa.Column('change_type', sa.String(length=40), nullable=False),
    sa.Column('snapshot_before_id', sa.UUID(), nullable=True),
    sa.Column('snapshot_after_id', sa.UUID(), nullable=False),
    sa.Column('delta', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('ambiguity_note', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("change_type IN ('listed','delisted','price_up','price_down','availability_changed','rescheduled','stage_changed')", name=op.f('ck_event_diff_change_type_is_known')),
    sa.ForeignKeyConstraint(['snapshot_after_id'], ['raw.snapshot.id'], name=op.f('fk_event_diff_snapshot_after_id_snapshot'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['snapshot_before_id'], ['raw.snapshot.id'], name=op.f('fk_event_diff_snapshot_before_id_snapshot'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['venue_id'], ['registry.venue.id'], name=op.f('fk_event_diff_venue_id_venue'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('detected_at', 'id', name='pk_event_diff'),
    schema='norm'
    )
    op.create_index('ix_event_diff_venue', 'event_diff', ['venue_id', 'detected_at'], unique=False, schema='norm')
    op.create_table('event_observation',
    sa.Column('observed_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('id', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('venue_id', sa.UUID(), nullable=False),
    sa.Column('snapshot_id', sa.UUID(), nullable=False),
    sa.Column('event_key', sa.String(length=200), nullable=False),
    sa.Column('external_id', sa.String(length=200), nullable=True),
    sa.Column('title', sa.Text(), nullable=False),
    sa.Column('starts_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('stage', sa.String(length=120), nullable=True),
    sa.Column('ticket_provider', sa.String(length=80), nullable=True),
    sa.Column('ticket_url', sa.Text(), nullable=True),
    sa.Column('price_min', sa.Numeric(precision=12, scale=2), nullable=True),
    sa.Column('price_max', sa.Numeric(precision=12, scale=2), nullable=True),
    sa.Column('currency', sa.String(length=3), server_default=sa.text("'RUB'"), nullable=False),
    sa.Column('availability_label', sa.String(length=120), nullable=True),
    sa.Column('categories_listed', sa.BigInteger(), nullable=True),
    sa.Column('raw_fields', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint('price_min IS NULL OR price_max IS NULL OR price_max >= price_min', name=op.f('ck_event_observation_price_range_ordered')),
    sa.CheckConstraint('price_min IS NULL OR price_min >= 0', name=op.f('ck_event_observation_price_non_negative')),
    sa.ForeignKeyConstraint(['snapshot_id'], ['raw.snapshot.id'], name=op.f('fk_event_observation_snapshot_id_snapshot'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['venue_id'], ['registry.venue.id'], name=op.f('fk_event_observation_venue_id_venue'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('observed_at', 'id', name='pk_event_observation'),
    schema='norm'
    )
    op.create_index('ix_event_obs_key', 'event_observation', ['venue_id', 'event_key', 'observed_at'], unique=False, schema='norm')
    op.create_index('ix_event_obs_snapshot', 'event_observation', ['snapshot_id'], unique=False, schema='norm')
    op.create_index('ix_event_obs_venue_starts', 'event_observation', ['venue_id', 'starts_at'], unique=False, schema='norm')
    op.create_table('information_attention',
    sa.Column('as_of_date', sa.Date(), nullable=False),
    sa.Column('topic_key', sa.String(length=160), nullable=False),
    sa.Column('topic_label_ru', sa.Text(), nullable=False),
    sa.Column('attention_score', sa.Float(), nullable=False),
    sa.Column('measurement_basis', sa.String(length=120), nullable=False),
    sa.Column('verification_status', sa.String(length=20), nullable=False),
    sa.Column('assertion_class', postgresql.ENUM('fact', 'derived_metric', 'estimate', 'hypothesis', 'forecast', name='assertion_class', schema='registry', create_type=False), nullable=False),
    sa.Column('snapshot_id', sa.UUID(), nullable=False),
    sa.Column('notes', sa.Text(), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("assertion_class IN ('estimate','hypothesis')", name=op.f('ck_information_attention_attention_is_never_a_fact_about_the_world')),
    sa.CheckConstraint("verification_status IN ('unverified','disputed','confirmed','refuted')", name=op.f('ck_information_attention_verification_status_is_known')),
    sa.ForeignKeyConstraint(['snapshot_id'], ['raw.snapshot.id'], name=op.f('fk_information_attention_snapshot_id_snapshot'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('as_of_date', 'topic_key', name='pk_information_attention'),
    schema='norm'
    )
    op.create_table('observation',
    sa.Column('observed_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('series_id', sa.UUID(), nullable=False),
    sa.Column('vintage_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('period', sa.String(length=10), nullable=False),
    sa.Column('period_end', sa.DateTime(timezone=True), nullable=False),
    sa.Column('value', sa.Float(), nullable=True),
    sa.Column('missing_reason', sa.String(length=60), nullable=True),
    sa.Column('unit', sa.String(length=60), nullable=False),
    sa.Column('geography', sa.String(length=16), nullable=False),
    sa.Column('assertion_class', postgresql.ENUM('fact', 'derived_metric', 'estimate', 'hypothesis', 'forecast', name='assertion_class', schema='registry', create_type=False), nullable=False),
    sa.Column('snapshot_id', sa.UUID(), nullable=False),
    sa.Column('retrieved_at', sa.DateTime(timezone=True), nullable=False),
    sa.Column('published_at', sa.DateTime(timezone=True), nullable=True),
    sa.Column('quality_flags', postgresql.ARRAY(sa.Text()), server_default=sa.text("'{}'::text[]"), nullable=False),
    sa.Column('methodology_notes', sa.Text(), nullable=True),
    sa.Column('transform', sa.String(length=80), nullable=True),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.CheckConstraint("period IN ('hour', 'day', 'week', 'month', 'quarter', 'year', 'point')", name=op.f('ck_observation_period_is_known')),
    sa.CheckConstraint('period_end > observed_at', name=op.f('ck_observation_period_is_positive_length')),
    sa.CheckConstraint('retrieved_at <= vintage_at', name=op.f('ck_observation_vintage_is_not_before_retrieval')),
    sa.CheckConstraint('value IS NOT NULL OR missing_reason IS NOT NULL', name=op.f('ck_observation_absent_value_has_a_reason')),
    sa.ForeignKeyConstraint(['series_id'], ['registry.series.id'], name=op.f('fk_observation_series_id_series'), ondelete='RESTRICT'),
    sa.ForeignKeyConstraint(['snapshot_id'], ['raw.snapshot.id'], name=op.f('fk_observation_snapshot_id_snapshot'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('observed_at', 'series_id', 'vintage_at', name='pk_observation'),
    schema='norm'
    )
    op.create_index('ix_observation_series_observed', 'observation', ['series_id', 'observed_at'], unique=False, schema='norm')
    op.create_index('ix_observation_snapshot', 'observation', ['snapshot_id'], unique=False, schema='norm')
    op.create_index('ix_observation_vintage', 'observation', ['vintage_at'], unique=False, schema='norm')
    op.create_table('snapshot_parse_error',
    sa.Column('id', sa.BigInteger(), autoincrement=True, nullable=False),
    sa.Column('snapshot_id', sa.UUID(), nullable=False),
    sa.Column('stage', sa.String(length=40), nullable=False),
    sa.Column('error_type', sa.String(length=120), nullable=False),
    sa.Column('message', sa.Text(), nullable=False),
    sa.Column('detail', postgresql.JSONB(astext_type=sa.Text()), server_default=sa.text("'{}'::jsonb"), nullable=False),
    sa.Column('created_at', sa.DateTime(timezone=True), server_default=sa.text('now()'), nullable=False),
    sa.ForeignKeyConstraint(['snapshot_id'], ['raw.snapshot.id'], name=op.f('fk_snapshot_parse_error_snapshot_id_snapshot'), ondelete='RESTRICT'),
    sa.PrimaryKeyConstraint('id', name=op.f('pk_snapshot_parse_error')),
    schema='raw'
    )
    op.create_index('ix_snapshot_parse_error_snapshot', 'snapshot_parse_error', ['snapshot_id'], unique=False, schema='raw')


    _run_sql("0001_epilogue.sql")


def downgrade() -> None:
    # Views, triggers, functions and tables all live inside our own schemas, so
    # dropping the schemas is both exhaustive and honest about what is removed.
    for schema in ("model", "feature", "norm", "raw", "ops", "registry"):
        op.execute(sa.text(f'DROP SCHEMA IF EXISTS "{schema}" CASCADE'))
    for role in ("barometer_agent", "barometer_quant", "barometer_readonly"):
        op.execute(
            sa.text(
                f"DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{role}')"
                f" THEN DROP OWNED BY {role}; DROP ROLE {role}; END IF; END $$"
            )
        )
