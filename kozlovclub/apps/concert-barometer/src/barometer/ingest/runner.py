"""Runs one adapter, once, safely.

The runner owns everything an adapter must not decide for itself:

* whether the source may be fetched at all (enabled, robots, verification),
* rate limiting and retries,
* storing evidence and writing observations in one transaction,
* recording the run and the source's health, including failures.

A failed source degrades the index; it never corrupts it. Nothing is written
without a snapshot, and no snapshot is written for a source we are not
permitted to read.
"""

from __future__ import annotations

import datetime as dt
import uuid
from dataclasses import dataclass, field

import structlog
from sqlalchemy import select
from sqlalchemy.orm import Session

from barometer.config.models import RetryPolicy, SourceDef
from barometer.db.models import PipelineRun, SnapshotParseError, Source, SourceHealth
from barometer.ingest.adapter import (
    AdapterError,
    FetchOutcome,
    FetchPlan,
    PermanentAdapterError,
    SourceAdapter,
    Window,
)
from barometer.ingest.retry import RateLimiter, call_with_retry
from barometer.provenance.writer import ProvenanceWriter
from barometer.settings import Settings, get_settings
from barometer.storage.base import SnapshotStore

log = structlog.get_logger(__name__)

EXPECTED_FRESHNESS_HOURS = {
    "hourly": 3.0,
    "daily": 36.0,
    "weekly": 8 * 24.0,
    "monthly": 40 * 24.0,
    "quarterly": 120 * 24.0,
    "yearly": 400 * 24.0,
    "irregular": None,
}


class SourceNotPermitted(RuntimeError):
    """The source is not cleared for automated fetching. Principle 10."""


@dataclass(slots=True)
class IngestReport:
    source_code: str
    run_id: uuid.UUID
    status: str = "running"
    plans: int = 0
    fetched: int = 0
    snapshots_new: int = 0
    snapshots_seen: int = 0
    observations: int = 0
    events: int = 0
    warnings: list[str] = field(default_factory=list)
    errors: list[str] = field(default_factory=list)

    @property
    def ok(self) -> bool:
        return self.status in ("succeeded", "degraded")


def check_permitted(source: SourceDef, settings: Settings | None = None) -> None:
    """Refuse to fetch anything whose access status is unresolved.

    Raises SourceNotPermitted with the reason, which the runner records so the
    dashboard can show exactly which sources are blocked and why.
    """
    s = settings or get_settings()
    if not source.enabled and not s.allow_disabled_sources:
        raise SourceNotPermitted(
            f"source '{source.code}' is disabled in config/sources.yaml"
            + (
                f"; outstanding checks: {source.verification_required}"
                if source.verification_required
                else ""
            )
        )
    if source.robots_policy.value == "disallowed":
        raise SourceNotPermitted(f"source '{source.code}': robots.txt disallows automated access")
    if source.robots_policy.value == "unknown" and s.respect_robots:
        raise SourceNotPermitted(
            f"source '{source.code}': robots status unknown. Check it and record the "
            "result in config/sources.yaml before enabling."
        )
    if source.requires_credentials and source.credential_env_var:
        import os

        if not os.environ.get(source.credential_env_var):
            raise SourceNotPermitted(
                f"source '{source.code}' needs {source.credential_env_var}, which is unset"
            )


class IngestRunner:
    def __init__(
        self,
        session: Session,
        store: SnapshotStore,
        retry_policy: RetryPolicy,
        settings: Settings | None = None,
    ) -> None:
        self.session = session
        self.writer = ProvenanceWriter(session, store)
        self.retry_policy = retry_policy
        self.settings = settings or get_settings()

    def run(
        self,
        adapter: SourceAdapter,
        window: Window,
        dry_run: bool = False,
    ) -> IngestReport:
        run_id = uuid.uuid4()
        started = dt.datetime.now(dt.UTC)
        report = IngestReport(source_code=adapter.source_code, run_id=run_id)

        pipeline_run = PipelineRun(
            id=run_id,
            pipeline="ingest",
            stage=adapter.source_code,
            actor=f"{type(adapter).__name__}@{adapter.collector_version}",
            status="running",
            started_at=started,
            params={
                "window_start": window.start.isoformat(),
                "window_end": window.end.isoformat(),
                "dry_run": dry_run,
            },
        )
        self.session.add(pipeline_run)
        self.session.flush()

        try:
            check_permitted(adapter.source, self.settings)
        except SourceNotPermitted as exc:
            report.status = "skipped"
            report.errors.append(str(exc))
            self._finish(pipeline_run, report, "skipped", exc)
            self._update_health(
                adapter.source_code, started, ok=False, error=str(exc), status="blocked"
            )
            log.warning("ingest.blocked", source=adapter.source_code, reason=str(exc))
            return report

        limiter = RateLimiter(per_minute=adapter.source.rate_limit_per_min)

        try:
            plans = adapter.plan(window)
        except AdapterError as exc:
            report.status = "failed"
            report.errors.append(f"plan failed: {exc}")
            self._finish(pipeline_run, report, "failed", exc)
            self._update_health(adapter.source_code, started, ok=False, error=str(exc))
            return report

        report.plans = len(plans)
        if dry_run:
            report.status = "succeeded"
            report.warnings.append(f"dry run: {len(plans)} request(s) planned, none sent")
            self._finish(pipeline_run, report, "succeeded", None)
            return report

        for plan in plans:
            try:
                self._run_one(adapter, plan, limiter, run_id, report)
            except PermanentAdapterError as exc:
                report.errors.append(f"{plan.label or plan.url}: {exc}")
                log.error("ingest.permanent_error", source=adapter.source_code, error=str(exc))
            except AdapterError as exc:
                report.errors.append(f"{plan.label or plan.url}: {exc}")
                log.warning("ingest.failed", source=adapter.source_code, error=str(exc))

        if report.errors and report.observations == 0 and report.events == 0:
            report.status = "failed"
        elif report.errors:
            report.status = "degraded"
        else:
            report.status = "succeeded"

        self._finish(pipeline_run, report, report.status, None)
        self._update_health(
            adapter.source_code,
            started,
            ok=report.ok,
            error="; ".join(report.errors) or None,
            cadence=adapter.source.update_cadence.value,
        )
        return report

    def _run_one(
        self,
        adapter: SourceAdapter,
        plan: FetchPlan,
        limiter: RateLimiter,
        run_id: uuid.UUID,
        report: IngestReport,
    ) -> None:
        limiter.wait()
        outcome: FetchOutcome = call_with_retry(
            lambda: adapter.fetch(plan),
            self.retry_policy,
            label=f"{adapter.source_code}:{plan.label or plan.url}",
        )
        report.fetched += 1

        envelope = adapter.envelope(outcome)

        try:
            parsed = adapter.parse(outcome)
        except Exception as exc:
            # Store the evidence anyway: a payload we could not read is exactly
            # what a human needs to see to fix the adapter.
            written = self.writer.write(envelope, outcome.payload, run_id=run_id)
            self.session.add(
                SnapshotParseError(
                    snapshot_id=written.snapshot_id,
                    stage="parse",
                    error_type=type(exc).__name__,
                    message=str(exc)[:4000],
                    detail={"plan": plan.label or plan.url or ""},
                )
            )
            self.session.flush()
            raise PermanentAdapterError(f"parse failed: {exc}") from exc

        result = self.writer.write(
            envelope,
            outcome.payload,
            observations=parsed.observations,
            events=parsed.events,
            run_id=run_id,
        )
        if result.already_seen:
            report.snapshots_seen += 1
        else:
            report.snapshots_new += 1
        report.observations += result.observations_written
        report.events += result.events_written
        report.warnings.extend(parsed.warnings)
        report.warnings.extend(result.skipped)

    def _finish(
        self,
        run: PipelineRun,
        report: IngestReport,
        status: str,
        exc: Exception | None,
    ) -> None:
        run.status = status
        run.finished_at = dt.datetime.now(dt.UTC)
        run.records_written = report.observations + report.events
        if exc is not None:
            run.error_type = type(exc).__name__
            run.error_message = str(exc)[:4000]
        elif report.errors:
            run.error_type = "PartialFailure"
            run.error_message = "; ".join(report.errors)[:4000]
        self.session.flush()

    def _update_health(
        self,
        source_code: str,
        attempted_at: dt.datetime,
        ok: bool,
        error: str | None = None,
        status: str | None = None,
        cadence: str | None = None,
    ) -> None:
        source = self.session.scalar(select(Source).where(Source.code == source_code))
        if source is None:  # pragma: no cover - guarded upstream
            return
        health = self.session.get(SourceHealth, source.id)
        if health is None:
            health = SourceHealth(source_id=source.id)
            self.session.add(health)
        health.last_attempt_at = attempted_at
        if ok:
            health.last_success_at = attempted_at
            health.consecutive_failures = 0
            health.status = status or "healthy"
            health.last_error = None
        else:
            health.consecutive_failures = (health.consecutive_failures or 0) + 1
            health.status = status or "failing"
            health.last_error = (error or "")[:4000]
        if cadence:
            health.expected_freshness_hours = EXPECTED_FRESHNESS_HOURS.get(cadence)
        health.updated_at = dt.datetime.now(dt.UTC)
        self.session.flush()
