"""Engine and session construction."""

from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from functools import lru_cache

from sqlalchemy import Engine, create_engine, event, text
from sqlalchemy.orm import Session, sessionmaker

from barometer.settings import Settings, get_settings


def make_engine(settings: Settings | None = None) -> Engine:
    s = settings or get_settings()
    engine = create_engine(
        s.database_url,
        echo=s.db_echo,
        pool_size=s.db_pool_size,
        pool_pre_ping=True,
        future=True,
    )

    @event.listens_for(engine, "connect")
    def _set_session_defaults(dbapi_conn, _record):  # pragma: no cover - driver hook
        with dbapi_conn.cursor() as cur:
            cur.execute(f"SET statement_timeout = {int(s.db_statement_timeout_ms)}")
            cur.execute("SET TIME ZONE 'UTC'")

    return engine


@lru_cache(maxsize=1)
def get_engine() -> Engine:
    return make_engine()


@lru_cache(maxsize=1)
def get_sessionmaker() -> sessionmaker[Session]:
    return sessionmaker(bind=get_engine(), expire_on_commit=False, future=True)


@contextmanager
def session_scope() -> Iterator[Session]:
    """Transactional scope. Commits on success, rolls back on any exception."""
    session = get_sessionmaker()()
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


def timescaledb_available(engine: Engine | None = None) -> bool:
    """Whether the TimescaleDB extension can be created in this database.

    The schema works without it (ADR 0008); hypertables are an optimisation,
    not a correctness requirement.
    """
    eng = engine or get_engine()
    with eng.connect() as conn:
        row = conn.execute(
            text("SELECT 1 FROM pg_available_extensions WHERE name = 'timescaledb'")
        ).first()
    return row is not None
