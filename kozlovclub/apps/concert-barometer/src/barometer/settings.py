"""Runtime settings. Everything overridable by environment, nothing secret in code."""

from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict


def _project_root() -> Path:
    return Path(__file__).resolve().parents[2]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_prefix="BAROMETER_",
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    environment: Literal["local", "ci", "staging", "production"] = "local"
    timezone: str = "Europe/Moscow"
    geography: str = "RU-MOW"

    # --- database ---------------------------------------------------------
    database_url: str = "postgresql+psycopg://barometer:barometer@localhost:5432/barometer"
    db_echo: bool = False
    db_pool_size: int = 5
    db_statement_timeout_ms: int = 60_000

    #: TimescaleDB is preferred but optional (ADR 0008). When absent the schema
    #: falls back to plain partition-friendly tables with BRIN indexes.
    require_timescaledb: bool = False

    # --- paths ------------------------------------------------------------
    config_dir: Path = Field(default_factory=lambda: _project_root() / "config")
    snapshot_root: Path = Field(default_factory=lambda: _project_root() / "var" / "snapshots")

    # --- raw snapshot storage --------------------------------------------
    storage_backend: Literal["local", "s3"] = "local"
    s3_bucket: str | None = None
    s3_endpoint_url: str | None = None
    s3_region: str | None = None

    # --- ingest behaviour -------------------------------------------------
    http_timeout_seconds: float = 30.0
    http_user_agent: str = (
        "ConcertBarometer/0.1 (market research; contact: set BAROMETER_HTTP_CONTACT)"
    )
    http_contact: str | None = None
    respect_robots: bool = True
    allow_disabled_sources: bool = False

    # --- agents -----------------------------------------------------------
    agent_provider: Literal["anthropic", "openai"] = "anthropic"

    @field_validator("respect_robots")
    @classmethod
    def _robots_is_not_negotiable_outside_tests(cls, v: bool) -> bool:
        if not v:
            raise ValueError(
                "respect_robots cannot be disabled (non-negotiable principle 10). "
                "Tests use a fake fetcher instead."
            )
        return v

    @property
    def user_agent(self) -> str:
        if self.http_contact:
            return f"ConcertBarometer/0.1 (market research; contact: {self.http_contact})"
        return self.http_user_agent

    @property
    def sync_database_url(self) -> str:
        return self.database_url


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
