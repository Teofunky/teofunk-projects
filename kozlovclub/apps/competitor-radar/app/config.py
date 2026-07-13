from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import yaml

ROOT = Path(__file__).resolve().parents[1]


def _load_dotenv() -> None:
    path = ROOT / ".env"
    if not path.exists():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


_load_dotenv()


@dataclass(frozen=True)
class Settings:
    host: str = os.getenv("APP_HOST", "127.0.0.1")
    port: int = int(os.getenv("APP_PORT", "8765"))
    database_path: Path = ROOT / os.getenv("DATABASE_PATH", "data/competitors.sqlite3")
    report_dir: Path = ROOT / os.getenv("REPORT_DIR", "data/reports")
    timezone: str = os.getenv("TIMEZONE", "Europe/Moscow")
    weekly_day: str = os.getenv("WEEKLY_DAY", "mon")
    weekly_hour: int = int(os.getenv("WEEKLY_HOUR", "9"))
    weekly_minute: int = int(os.getenv("WEEKLY_MINUTE", "0"))
    timeout: float = float(os.getenv("HTTP_TIMEOUT", "25"))
    request_delay: float = float(os.getenv("REQUEST_DELAY_SECONDS", "1.0"))
    user_agent: str = os.getenv(
        "USER_AGENT",
        "KozlovClubCompetitorReview/1.0 (+mailto:art@kozlovclub.ru)",
    )
    scheduler_enabled: bool = os.getenv("ENABLE_SCHEDULER", "true").lower() == "true"
    use_playwright: bool = os.getenv("USE_PLAYWRIGHT", "false").lower() == "true"


settings = Settings()
settings.database_path.parent.mkdir(parents=True, exist_ok=True)
settings.report_dir.mkdir(parents=True, exist_ok=True)


def load_sources() -> dict[str, Any]:
    path = ROOT / "config" / "sources.yaml"
    return yaml.safe_load(path.read_text(encoding="utf-8"))
