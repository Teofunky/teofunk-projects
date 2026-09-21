"""Every CLI command runs.

The CLI is the whole interface to the system right now. An untested command is
a command that breaks the first time somebody types it, which is exactly what
happened to `observations show --series`: a bare parameter used only in an
`IS NULL` comparison gave PostgreSQL nothing to infer a type from, and the
query failed with AmbiguousParameter. Unit-testing the SQL would not have
caught it; running the command did.
"""

from __future__ import annotations

import pytest

from barometer.cli import main

pytestmark = pytest.mark.db


def run(capsys, *argv: str) -> tuple[int, str]:
    code = main(list(argv))
    captured = capsys.readouterr()
    return code, captured.out + captured.err


# ---------------------------------------------------------------------------
# Commands that need no database
# ---------------------------------------------------------------------------


def test_config_validate(capsys):
    code, out = run(capsys, "config", "validate")
    assert code == 0
    assert "Registries valid." in out
    assert "Оценка внешнего спроса" in out, "the calibration label must be visible"


def test_config_sources_lists_every_source(capsys, registries):
    code, out = run(capsys, "config", "sources")
    assert code == 0
    for source in registries.sources.sources:
        assert source.code in out


def test_venues_review_names_what_is_outstanding(capsys):
    code, out = run(capsys, "venues", "review")
    assert code == 0
    assert "need access review" in out
    assert "robots_allows_crawl" in out


def test_config_validate_reports_a_broken_registry(capsys, tmp_path):
    (tmp_path / "sources.yaml").write_text("schema_version: 1\n", encoding="utf-8")
    code, out = run(capsys, "--config-dir", str(tmp_path), "config", "validate")
    assert code == 1
    assert "INVALID" in out


# ---------------------------------------------------------------------------
# Commands that read the database
# ---------------------------------------------------------------------------


def test_db_status(capsys, engine, monkeypatch):
    _point_cli_at(monkeypatch, engine)
    code, out = run(capsys, "db", "status")
    assert code == 0
    assert "migration revision:" in out
    assert "TimescaleDB available:" in out


def test_ingest_status_lists_sources(capsys, engine, monkeypatch, synced_session):
    _point_cli_at(monkeypatch, engine)
    code, out = run(capsys, "ingest", "status")
    assert code == 0
    assert "cbr_fx" in out


def test_observations_show_with_no_data_says_so(capsys, engine, monkeypatch):
    _point_cli_at(monkeypatch, engine)
    code, out = run(capsys, "observations", "show")
    assert code == 0
    assert "no observations yet" in out


def test_observations_show_filtered_by_series_runs(capsys, engine, monkeypatch):
    """The regression this file exists for."""
    _point_cli_at(monkeypatch, engine)
    code, out = run(capsys, "observations", "show", "--series", "cbr.usd_rub.daily")
    assert code == 0
    assert "AmbiguousParameter" not in out


def test_ingest_dry_run_sends_nothing(capsys, engine, monkeypatch, synced_session):
    _point_cli_at(monkeypatch, engine)
    code, out = run(capsys, "ingest", "all", "--days", "3", "--dry-run")
    assert code == 0
    assert "none sent" in out
    assert "awaiting access review" in out, "blocked sources must stay visible"


def test_ingest_run_refuses_an_unimplemented_source(capsys, engine, monkeypatch, synced_session):
    _point_cli_at(monkeypatch, engine)
    code, out = run(capsys, "ingest", "run", "--source", "sberindex", "--dry-run")
    assert code == 1
    assert "no adapter yet" in out


def test_ingest_run_refuses_an_unknown_source(capsys, engine, monkeypatch, synced_session):
    _point_cli_at(monkeypatch, engine)
    code, out = run(capsys, "ingest", "run", "--source", "not_a_source", "--dry-run")
    assert code == 1
    assert "not declared" in out


def _point_cli_at(monkeypatch, engine) -> None:
    """Make the CLI's cached engine and settings use the test database."""
    from barometer.db import session as session_module
    from barometer.settings import Settings, get_settings

    url = str(engine.url.render_as_string(hide_password=False))
    monkeypatch.setenv("BAROMETER_DATABASE_URL", url)
    get_settings.cache_clear()
    session_module.get_engine.cache_clear()
    session_module.get_sessionmaker.cache_clear()
    monkeypatch.setattr(session_module, "get_engine", lambda: engine)
    assert Settings().database_url == url
