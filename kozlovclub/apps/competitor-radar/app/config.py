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
    database_path: Path = ROOT / os.getenv("DATABASE_PATH", "data/competitors_v2.sqlite3")
    report_dir: Path = ROOT / os.getenv("REPORT_DIR", "data/reports")
    timezone: str = os.getenv("TIMEZONE", "Europe/Moscow")
    weekly_day: str = os.getenv("WEEKLY_DAY", "mon")
    weekly_hour: int = int(os.getenv("WEEKLY_HOUR", "9"))
    weekly_minute: int = int(os.getenv("WEEKLY_MINUTE", "0"))
    timeout: float = float(os.getenv("HTTP_TIMEOUT", "35"))
    request_delay: float = float(os.getenv("REQUEST_DELAY_SECONDS", "0.8"))
    max_concurrency: int = int(os.getenv("MAX_CONCURRENCY", "4"))
    user_agent: str = os.getenv(
        "USER_AGENT",
        "KozlovClubCompetitorRadar/2.0 (+mailto:art@kozlovclub.ru)",
    )
    scheduler_enabled: bool = os.getenv("ENABLE_SCHEDULER", "true").lower() == "true"
    use_playwright: bool = os.getenv("USE_PLAYWRIGHT", "false").lower() == "true"
    strict_robots: bool = os.getenv("STRICT_ROBOTS", "true").lower() == "true"
    telegram_bot_token: str = os.getenv("TELEGRAM_BOT_TOKEN", "")
    telegram_chat_id: str = os.getenv("TELEGRAM_CHAT_ID", "")
    smtp_host: str = os.getenv("SMTP_HOST", "")
    smtp_port: int = int(os.getenv("SMTP_PORT", "587"))
    smtp_user: str = os.getenv("SMTP_USER", "")
    smtp_password: str = os.getenv("SMTP_PASSWORD", "")
    report_email_to: str = os.getenv("REPORT_EMAIL_TO", "")
    kozlov_ics_urls: tuple[str, ...] = tuple(
        value.strip() for value in os.getenv("KOZLOV_ICS_URLS", "").split(";") if value.strip()
    )


settings = Settings()
settings.database_path.parent.mkdir(parents=True, exist_ok=True)
settings.report_dir.mkdir(parents=True, exist_ok=True)


def load_sources() -> dict[str, Any]:
    return yaml.safe_load((ROOT / "config" / "sources.yaml").read_text(encoding="utf-8"))


def load_alias_seed() -> dict[str, list[str]]:
    path = ROOT / "config" / "artist_aliases.yaml"
    if not path.exists():
        return {}
    return yaml.safe_load(path.read_text(encoding="utf-8")).get("aliases", {})
