"""The only HTTP client adapters use.

It exists so that identifying ourselves, timing out, backing off and classifying
failures happen once and the same way for every source, rather than per adapter.

Failure classification is the important part: a 429 or a 503 is worth retrying,
a 403 or a 404 is not. Retrying a refusal is both useless and rude to a
publisher whose page we are only permitted to read.
"""

from __future__ import annotations

import datetime as dt
from dataclasses import dataclass

import httpx
import structlog

from barometer.ingest.adapter import (
    FetchOutcome,
    FetchPlan,
    PermanentAdapterError,
    TransientAdapterError,
)
from barometer.settings import Settings, get_settings

log = structlog.get_logger(__name__)

#: Worth another attempt: the server said "not now", not "no".
TRANSIENT_STATUS = frozenset({408, 425, 429, 500, 502, 503, 504, 507, 509})

#: Not worth another attempt: the answer will not change by asking again.
PERMANENT_STATUS = frozenset({400, 401, 402, 403, 404, 405, 406, 410, 451})

#: Refuse to hold a payload larger than this in memory. A source that suddenly
#: returns hundreds of megabytes has changed in a way a human should look at.
MAX_PAYLOAD_BYTES = 64 * 1024 * 1024


@dataclass(slots=True)
class HttpFetcher:
    settings: Settings
    client: httpx.Client | None = None

    @classmethod
    def build(cls, settings: Settings | None = None) -> HttpFetcher:
        s = settings or get_settings()
        client = httpx.Client(
            timeout=httpx.Timeout(s.http_timeout_seconds),
            follow_redirects=True,
            headers={
                "User-Agent": s.user_agent,
                "Accept-Encoding": "gzip, deflate",
            },
        )
        return cls(settings=s, client=client)

    def close(self) -> None:
        if self.client is not None:
            self.client.close()

    def __enter__(self) -> HttpFetcher:
        return self

    def __exit__(self, *exc_info: object) -> None:
        self.close()

    def fetch(self, plan: FetchPlan) -> FetchOutcome:
        if not plan.url:
            raise PermanentAdapterError("fetch plan has no URL")
        if self.client is None:  # pragma: no cover - guarded by build()
            raise RuntimeError("HttpFetcher has no client; use HttpFetcher.build()")

        fetched_at = dt.datetime.now(dt.UTC)
        try:
            response = self.client.request(
                plan.method,
                plan.url,
                params=plan.params or None,
                headers=plan.headers or None,
            )
        except httpx.TimeoutException as exc:
            raise TransientAdapterError(f"timeout fetching {plan.url}: {exc}") from exc
        except httpx.TransportError as exc:
            # DNS failures, refused connections, TLS problems and proxy denials
            # all land here. They can be temporary, so they are transient.
            raise TransientAdapterError(f"transport error fetching {plan.url}: {exc}") from exc

        self._raise_for_status(response, plan)

        payload = response.content
        if len(payload) > MAX_PAYLOAD_BYTES:
            raise PermanentAdapterError(
                f"{plan.url} returned {len(payload)} bytes, over the "
                f"{MAX_PAYLOAD_BYTES} byte limit. Check whether the source changed."
            )
        if not payload:
            raise TransientAdapterError(f"{plan.url} returned an empty body")

        return FetchOutcome(
            plan=plan,
            payload=payload,
            fetched_at=fetched_at,
            http_status=response.status_code,
            content_type=response.headers.get("content-type"),
        )

    @staticmethod
    def _raise_for_status(response: httpx.Response, plan: FetchPlan) -> None:
        status = response.status_code
        if status < 400:
            return
        detail = f"{status} from {plan.url}"
        if status in PERMANENT_STATUS:
            raise PermanentAdapterError(detail)
        if status in TRANSIENT_STATUS:
            raise TransientAdapterError(detail)
        # Unclassified 4xx is treated as permanent, unclassified 5xx as transient:
        # the safer assumption in each direction.
        if 400 <= status < 500:
            raise PermanentAdapterError(detail)
        raise TransientAdapterError(detail)
