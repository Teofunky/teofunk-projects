"""observed_date: the Moscow calendar date of every observation

Revision ID: 0002
Revises: 0001
Create Date: 2026-09-21

An observation stored at Moscow midnight casts to the PREVIOUS day in a UTC
session, so every daily grouping and every join to as_of_date was off by one.
See migrations/ddl/0002_observed_date.sql for the full reasoning.
"""

from __future__ import annotations

from collections.abc import Sequence
from pathlib import Path

import sqlalchemy as sa
from alembic import op

revision: str = "0002"
down_revision: str | None = "0001"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

DDL_DIR = Path(__file__).resolve().parents[1] / "ddl"


def _run_sql(name: str) -> None:
    op.execute(sa.text((DDL_DIR / name).read_text(encoding="utf-8")))


def upgrade() -> None:
    _run_sql("0002_observed_date.sql")


def downgrade() -> None:
    op.execute(
        sa.text(
            """
            DROP VIEW IF EXISTS norm.observation_provenance;
            DROP VIEW IF EXISTS norm.observation_current;
            DROP TRIGGER IF EXISTS trg_observation_observed_date ON norm.observation;
            DROP TRIGGER IF EXISTS trg_event_observation_observed_date
                ON norm.event_observation;
            DROP FUNCTION IF EXISTS norm.set_observed_date();
            DROP INDEX IF EXISTS norm.ix_observation_series_date;
            DROP INDEX IF EXISTS norm.ix_event_obs_venue_date;
            ALTER TABLE norm.observation DROP COLUMN IF EXISTS observed_date;
            ALTER TABLE norm.event_observation DROP COLUMN IF EXISTS observed_date;
            """
        )
    )
    # Rebuild the 0001 versions of the views so a downgrade leaves a working schema.
    _run_sql("0002_downgrade_views.sql")
