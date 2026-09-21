"""Retry and rate-limit policy, driven by config/agents.yaml defaults."""

from __future__ import annotations

import random
import time
from collections.abc import Callable
from dataclasses import dataclass
from typing import TypeVar

import structlog

from barometer.config.models import RetryPolicy
from barometer.ingest.adapter import PermanentAdapterError, TransientAdapterError

T = TypeVar("T")
log = structlog.get_logger(__name__)


@dataclass(slots=True)
class RateLimiter:
    """Simple per-source spacing. One source, one limiter, one process.

    Deliberately conservative: a public page we are merely permitted to read is
    not an invitation to hammer it.
    """

    per_minute: int | None
    _last_call: float = 0.0

    def wait(self) -> None:
        if not self.per_minute:
            return
        min_gap = 60.0 / float(self.per_minute)
        elapsed = time.monotonic() - self._last_call
        if self._last_call and elapsed < min_gap:
            time.sleep(min_gap - elapsed)
        self._last_call = time.monotonic()


def call_with_retry(
    fn: Callable[[], T],
    policy: RetryPolicy,
    *,
    label: str = "",
    sleep: Callable[[float], None] = time.sleep,
    jitter: Callable[[], float] = random.random,
) -> T:
    """Run ``fn``, retrying only what the policy says is transient.

    A PermanentAdapterError is never retried: repeating a request that is
    forbidden or malformed is both useless and rude to the source.
    """
    last: Exception | None = None
    for attempt in range(1, policy.max_attempts + 1):
        try:
            return fn()
        except PermanentAdapterError:
            raise
        except TransientAdapterError as exc:
            last = exc
            if attempt == policy.max_attempts:
                break
            delay = _delay_for(policy, attempt, jitter)
            log.warning(
                "ingest.retry",
                label=label,
                attempt=attempt,
                max_attempts=policy.max_attempts,
                delay_seconds=round(delay, 2),
                error=str(exc),
            )
            sleep(delay)
    assert last is not None
    raise last


def _delay_for(policy: RetryPolicy, attempt: int, jitter: Callable[[], float]) -> float:
    if policy.backoff == "constant":
        base = policy.initial_delay_seconds
    elif policy.backoff == "linear":
        base = policy.initial_delay_seconds * attempt
    else:
        base = policy.initial_delay_seconds * (2 ** (attempt - 1))
    base = min(base, policy.max_delay_seconds)
    # Full jitter: several sources recovering from the same outage should not
    # retry in lockstep.
    return base * (0.5 + 0.5 * jitter())
