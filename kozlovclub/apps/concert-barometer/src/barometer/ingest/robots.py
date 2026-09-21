"""robots.txt checking, for principle 10.

Used before fetching any page under `access_policy: public_page`. Official APIs
and licensed feeds are governed by their terms, not by robots.txt, and are
marked `not_applicable` in the registry.

The rule here is conservative on purpose: if robots.txt cannot be read, access
is treated as NOT granted. A source we cannot ask is a source we do not crawl.
"""

from __future__ import annotations

import datetime as dt
from dataclasses import dataclass
from urllib.parse import urljoin, urlparse
from urllib.robotparser import RobotFileParser

import httpx
import structlog

from barometer.settings import Settings, get_settings

log = structlog.get_logger(__name__)


@dataclass(frozen=True, slots=True)
class RobotsVerdict:
    allowed: bool
    reason: str
    checked_at: dt.datetime
    robots_url: str
    crawl_delay_seconds: float | None = None

    @property
    def summary(self) -> str:
        return f"{'allowed' if self.allowed else 'disallowed'}: {self.reason}"


def robots_url_for(url: str) -> str:
    parsed = urlparse(url)
    if not parsed.scheme or not parsed.netloc:
        raise ValueError(f"cannot derive robots.txt location from {url!r}")
    return urljoin(f"{parsed.scheme}://{parsed.netloc}", "/robots.txt")


def check_robots(
    url: str,
    settings: Settings | None = None,
    client: httpx.Client | None = None,
) -> RobotsVerdict:
    """Ask the site whether we may fetch ``url``.

    A 404 means there is no robots.txt, which by convention grants access.
    Any other failure leaves the question unanswered, and unanswered means no.
    """
    s = settings or get_settings()
    now = dt.datetime.now(dt.UTC)
    robots_location = robots_url_for(url)
    owns_client = client is None
    http = client or httpx.Client(
        timeout=httpx.Timeout(s.http_timeout_seconds),
        follow_redirects=True,
        headers={"User-Agent": s.user_agent},
    )

    try:
        response = http.get(robots_location)
    except httpx.HTTPError as exc:
        return RobotsVerdict(
            allowed=False,
            reason=f"could not read {robots_location}: {exc}",
            checked_at=now,
            robots_url=robots_location,
        )
    finally:
        if owns_client:
            http.close()

    if response.status_code == 404:
        return RobotsVerdict(
            allowed=True,
            reason="no robots.txt published, which conventionally permits access",
            checked_at=now,
            robots_url=robots_location,
        )
    if response.status_code >= 400:
        return RobotsVerdict(
            allowed=False,
            reason=f"{robots_location} returned {response.status_code}",
            checked_at=now,
            robots_url=robots_location,
        )

    parser = RobotFileParser()
    parser.parse(response.text.splitlines())
    agent = s.user_agent
    allowed = bool(parser.can_fetch(agent, url))
    delay = parser.crawl_delay(agent)

    return RobotsVerdict(
        allowed=allowed,
        reason=("robots.txt permits this path" if allowed else "robots.txt disallows this path"),
        checked_at=now,
        robots_url=robots_location,
        crawl_delay_seconds=float(delay) if delay is not None else None,
    )
