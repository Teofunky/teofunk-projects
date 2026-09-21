from __future__ import annotations

import datetime as dt
import os
import uuid
from pathlib import Path

import pytest
from sqlalchemy import text
from sqlalchemy.orm import Session, sessionmaker

from barometer.config.loader import load_registries
from barometer.config.models import AssertionClass
from barometer.db.session import make_engine
from barometer.db.sync import sync_registries
from barometer.provenance.record import FetchEnvelope, ObservationRecord
from barometer.settings import Settings
from barometer.storage.local import LocalSnapshotStore

PROJECT_ROOT = Path(__file__).resolve().parents[1]
CONFIG_DIR = PROJECT_ROOT / "config"


@pytest.fixture(scope="session")
def registries():
    return load_registries(CONFIG_DIR)


@pytest.fixture(scope="session")
def database_url() -> str:
    url = os.environ.get("BAROMETER_DATABASE_URL")
    if not url:
        pytest.skip("BAROMETER_DATABASE_URL not set; database tests skipped")
    return url


@pytest.fixture(scope="session")
def engine(database_url: str):
    eng = make_engine(Settings(database_url=database_url))
    try:
        with eng.connect() as conn:
            conn.execute(text("SELECT 1 FROM registry.source LIMIT 1"))
    except Exception as exc:
        pytest.skip(f"database not migrated ({exc.__class__.__name__}); run `make migrate`")
    return eng


#: Data tables emptied before the suite runs. Registry tables are left alone:
#: they are rebuilt from config by the synced_session fixture.
DATA_TABLES = (
    "model.forecast_evaluation",
    "model.backtest",
    "model.forecast",
    "model.index_value",
    "model.model_run",
    "feature.feature_value",
    "feature.feature_definition",
    "norm.event_diff",
    "norm.event_observation",
    "norm.information_attention",
    "norm.observation",
    "raw.snapshot_parse_error",
    "raw.snapshot",
    "ops.agent_invocation",
    "ops.data_quality_check",
    "ops.source_health",
    "ops.pipeline_run",
)


@pytest.fixture(scope="session", autouse=True)
def clean_database(engine):
    """Start the suite from an empty data set.

    Without this, anything previously written (a demo run, a manual ingest)
    leaks into assertions about row counts, and the suite passes or fails
    depending on what happened to be in the database. Append-only triggers
    block DELETE, so this truncates.

    It refuses to touch a production database. A test suite that can empty
    production is a worse problem than a flaky test.
    """
    settings = Settings(database_url=str(engine.url))
    if settings.environment == "production":
        pytest.exit("refusing to run the test suite against a production database")

    with engine.begin() as conn:
        db_name = conn.execute(text("SELECT current_database()")).scalar_one()
        conn.execute(text(f"TRUNCATE {', '.join(DATA_TABLES)} CASCADE"))
    assert db_name  # documents which database was cleared if the assertion fires
    return None


@pytest.fixture
def session(engine) -> Session:
    """A session rolled back at the end, so tests never persist anything."""
    connection = engine.connect()
    transaction = connection.begin()
    sess = sessionmaker(bind=connection, expire_on_commit=False)()
    try:
        yield sess
    finally:
        sess.close()
        transaction.rollback()
        connection.close()


@pytest.fixture
def synced_session(session: Session, registries) -> Session:
    sync_registries(session, registries=registries, config_dir=CONFIG_DIR)
    session.flush()
    return session


@pytest.fixture
def store(tmp_path: Path) -> LocalSnapshotStore:
    return LocalSnapshotStore(tmp_path / "snapshots")


@pytest.fixture
def now() -> dt.datetime:
    return dt.datetime(2026, 9, 21, 9, 0, tzinfo=dt.UTC)


@pytest.fixture
def envelope(now: dt.datetime) -> FetchEnvelope:
    return FetchEnvelope(
        source_code="cbr_key_rate",
        fetched_at=now,
        request_url="https://www.cbr.ru/dataservice/keyrate",
        legal_basis="official_api",
        geography="RU",
        collector="TestCollector",
        collector_version="1.0.0",
    )


def observation(
    series_code: str = "cbr.key_rate.daily",
    *,
    observed_at: dt.datetime | None = None,
    value: float | None = 16.0,
    unit: str = "percent_per_annum",
    assertion_class: AssertionClass = AssertionClass.FACT,
    **kwargs,
) -> ObservationRecord:
    start = observed_at or dt.datetime(2026, 9, 20, tzinfo=dt.UTC)
    return ObservationRecord(
        series_code=series_code,
        observed_at=start,
        period="day",
        period_end=start + dt.timedelta(days=1),
        value=value,
        unit=unit,
        geography="RU",
        assertion_class=assertion_class,
        **kwargs,
    )


@pytest.fixture
def make_observation():
    return observation


@pytest.fixture
def run_id() -> uuid.UUID:
    return uuid.uuid4()
