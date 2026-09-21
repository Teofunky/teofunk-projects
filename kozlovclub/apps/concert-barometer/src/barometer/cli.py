"""Command line entry point: `barometer <group> <command>`."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

import structlog

from barometer.config.loader import ConfigError, load_registries
from barometer.settings import get_settings

log = structlog.get_logger(__name__)


def _configure_logging(verbose: bool) -> None:
    structlog.configure(
        processors=[
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso", utc=True),
            structlog.dev.ConsoleRenderer(colors=sys.stderr.isatty()),
        ],
        wrapper_class=structlog.make_filtering_bound_logger(10 if verbose else 20),
    )


# ---------------------------------------------------------------------------
# config
# ---------------------------------------------------------------------------


def cmd_config_validate(args: argparse.Namespace) -> int:
    try:
        regs = load_registries(Path(args.config_dir) if args.config_dir else None)
    except ConfigError as exc:
        print(f"INVALID\n{exc}", file=sys.stderr)
        return 1

    fetchable = [s.code for s in regs.sources.sources if s.is_fetchable]
    blocked = [s.code for s in regs.sources.sources if not s.is_fetchable]
    print("Registries valid.")
    print(f"  sources  : {len(regs.sources.sources)}  ({len(fetchable)} fetchable now)")
    print(f"  series   : {len(regs.sources.all_series)}")
    print(f"  segments : {len(regs.segments.segments)}")
    print(f"  venues   : {len(regs.venues.venues)}  ({len(regs.venues.panel)} in panel)")
    print(f"  agents   : {len(regs.agents.agents)}")
    print(f"  index    : {regs.index.index.code}, {len(regs.index.components)} components")
    print(f"  calibration status : {regs.index.calibration.status}")
    print(f"  demand is labelled : «{regs.publishable_label_for_demand()}»")
    if blocked:
        print("\nBlocked from automated fetching until access is resolved:")
        for code in blocked:
            src = regs.sources.by_code[code]
            reason = "disabled" if not src.enabled else f"robots_policy={src.robots_policy.value}"
            print(f"  - {code}: {reason}")
    return 0


def cmd_config_sources(args: argparse.Namespace) -> int:
    regs = load_registries(Path(args.config_dir) if args.config_dir else None)
    rows = [
        (
            s.code,
            "yes" if s.enabled else "no",
            "proxy" if s.is_proxy else "direct",
            s.robots_policy.value,
            str(len(s.series)),
        )
        for s in regs.sources.sources
    ]
    header = ("source", "enabled", "kind", "robots", "series")
    widths = [max(len(h), *(len(r[i]) for r in rows)) for i, h in enumerate(header)]
    print("  ".join(h.ljust(w) for h, w in zip(header, widths, strict=True)))
    print("  ".join("-" * w for w in widths))
    for r in rows:
        print("  ".join(c.ljust(w) for c, w in zip(r, widths, strict=True)))
    return 0


# ---------------------------------------------------------------------------
# db
# ---------------------------------------------------------------------------


def cmd_db_sync_registry(args: argparse.Namespace) -> int:
    from barometer.db.session import session_scope
    from barometer.db.sync import sync_registries

    config_dir = Path(args.config_dir) if args.config_dir else None
    with session_scope() as session:
        report = sync_registries(session, config_dir=config_dir)
    print(report.summary())
    for item in report.retired:
        print(f"  retired (kept for provenance): {item}")
    return 0


def cmd_db_status(args: argparse.Namespace) -> int:
    from sqlalchemy import text

    from barometer.db.session import get_engine, timescaledb_available

    engine = get_engine()
    with engine.connect() as conn:
        version = conn.execute(text("SELECT current_setting('server_version')")).scalar()
        revision = conn.execute(
            text("SELECT version_num FROM public.alembic_version")
        ).scalar_one_or_none()
        counts = conn.execute(
            text(
                """
                SELECT 'sources', count(*) FROM registry.source
                UNION ALL SELECT 'series', count(*) FROM registry.series
                UNION ALL SELECT 'venues', count(*) FROM registry.venue
                UNION ALL SELECT 'snapshots', count(*) FROM raw.snapshot
                UNION ALL SELECT 'observations', count(*) FROM norm.observation
                """
            )
        ).all()
    print(f"PostgreSQL {version}")
    print(f"migration revision: {revision or 'none'}")
    timescale = "yes" if timescaledb_available(engine) else "no (BRIN fallback)"
    print(f"TimescaleDB available: {timescale}")
    for name, n in counts:
        print(f"  {name:<14} {n}")
    return 0


# ---------------------------------------------------------------------------
# venues
# ---------------------------------------------------------------------------


def cmd_venues_review(args: argparse.Namespace) -> int:
    regs = load_registries(Path(args.config_dir) if args.config_dir else None)
    pending = [
        v
        for v in regs.venues.panel
        if not (v.url_verified and v.robots_allows_crawl and v.access_reviewed_at)
    ]
    print(f"{len(pending)} of {len(regs.venues.panel)} panel venues still need access review.\n")
    for step in regs.venues.review_workflow.steps:
        print(f"  * {step}")
    print()
    for v in pending:
        missing = []
        if not v.url_verified:
            missing.append("url_verified")
        if v.robots_allows_crawl is None:
            missing.append("robots_allows_crawl")
        if not v.access_reviewed_at:
            missing.append("access_reviewed_at")
        url = v.website_url or "(no URL on file)"
        print(f"  {v.code:<24} {url}")
        print(f"  {'':<24} missing: {', '.join(missing)}")
    return 0


# ---------------------------------------------------------------------------
# ingest
# ---------------------------------------------------------------------------


def _run_ingest(args: argparse.Namespace, source_codes: list[str]) -> int:
    import datetime as dt

    from barometer.db.session import session_scope
    from barometer.ingest.adapter import Window
    from barometer.ingest.runner import IngestRunner
    from barometer.sources import NoAdapterError, build_adapter, shared_fetcher
    from barometer.storage import get_snapshot_store

    regs = load_registries(Path(args.config_dir) if args.config_dir else None)
    settings = get_settings()
    window = Window.last_days(args.days)
    exit_code = 0

    fetcher = shared_fetcher()
    try:
        for code in source_codes:
            source = regs.sources.by_code.get(code)
            if source is None:
                print(f"{code}: not declared in config/sources.yaml", file=sys.stderr)
                exit_code = 1
                continue
            try:
                adapter = build_adapter(source, fetcher)
            except NoAdapterError as exc:
                print(f"{code}: {exc}", file=sys.stderr)
                exit_code = 1
                continue

            with session_scope() as session:
                runner = IngestRunner(
                    session,
                    get_snapshot_store(settings),
                    regs.agents.defaults.retry,
                    settings,
                )
                report = runner.run(adapter, window, dry_run=args.dry_run)

            marker = {"succeeded": "ok", "degraded": "partial", "skipped": "skip"}.get(
                report.status, "FAIL"
            )
            print(
                f"[{marker:>7}] {code:<24} "
                f"plans={report.plans} snapshots={report.snapshots_new}"
                f"(+{report.snapshots_seen} seen) observations={report.observations}"
            )
            for warning in report.warnings[:5]:
                print(f"          warning: {warning}")
            if len(report.warnings) > 5:
                print(f"          ... and {len(report.warnings) - 5} more warnings")
            for error in report.errors:
                print(f"          error:   {error}", file=sys.stderr)
            if report.status in ("failed",):
                exit_code = 1
    finally:
        fetcher.close()

    if not args.dry_run:
        print(
            f"\nwindow: {window.start.date()} .. {window.end.date()} "
            f"(now {dt.datetime.now(dt.UTC).isoformat(timespec='seconds')})"
        )
    return exit_code


def cmd_ingest_run(args: argparse.Namespace) -> int:
    return _run_ingest(args, [args.source])


def cmd_ingest_all(args: argparse.Namespace) -> int:
    from barometer.sources import implemented_source_codes, missing_adapters

    regs = load_registries(Path(args.config_dir) if args.config_dir else None)
    implemented = implemented_source_codes()
    runnable = [s.code for s in regs.sources.sources if s.is_fetchable and s.code in implemented]
    skipped = [s.code for s in regs.sources.sources if s.is_fetchable and s.code not in implemented]

    if not runnable:
        print("no source is both cleared for access and implemented", file=sys.stderr)
        return 1

    code = _run_ingest(args, runnable)
    if skipped:
        print("\ncleared for access but no adapter yet: " + ", ".join(skipped))
    pending = [c for c in missing_adapters(regs.sources) if c not in skipped]
    if pending:
        print("awaiting access review before an adapter is useful: " + ", ".join(pending))
    return code


def cmd_ingest_status(args: argparse.Namespace) -> int:
    from sqlalchemy import text

    from barometer.db.session import get_engine

    with get_engine().connect() as conn:
        rows = conn.execute(
            text(
                """
                SELECT source_code, enabled, status, freshness_verdict,
                       last_success_at, consecutive_failures
                FROM ops.source_freshness
                ORDER BY enabled DESC, source_code
                """
            )
        ).all()
    header = ("source", "enabled", "health", "freshness", "last success", "fails")
    print("  ".join(header[i].ljust(w) for i, w in enumerate((26, 7, 10, 10, 26, 5))))
    print("  ".join("-" * w for w in (26, 7, 10, 10, 26, 5)))
    for r in rows:
        last = r.last_success_at.isoformat(timespec="seconds") if r.last_success_at else "never"
        print(
            f"{r.source_code:<26}  {'yes' if r.enabled else 'no':<7}  "
            f"{(r.status or '-'):<10}  {(r.freshness_verdict or '-'):<10}  "
            f"{last:<26}  {r.consecutive_failures or 0:<5}"
        )
    return 0


def cmd_observations_show(args: argparse.Namespace) -> int:
    """Print observations with their evidence, which is the point of the system."""
    from sqlalchemy import text

    from barometer.db.session import get_engine

    with get_engine().connect() as conn:
        rows = conn.execute(
            text(
                """
                SELECT series_code, observed_date, value, missing_reason, unit,
                       assertion_class, quality_flags, source_code, content_hash,
                       request_url, available_at
                FROM norm.observation_provenance
                WHERE (:series IS NULL OR series_code = :series)
                ORDER BY observed_date DESC, series_code
                LIMIT :limit
                """
            ),
            {"series": args.series, "limit": args.limit},
        ).all()

    if not rows:
        print("no observations yet; run `barometer ingest all` first")
        return 0

    for r in rows:
        value = f"{r.value:g}" if r.value is not None else f"absent ({r.missing_reason})"
        flags = f" [{','.join(r.quality_flags)}]" if r.quality_flags else ""
        print(f"{r.observed_date}  {r.series_code:<36} {value:>16} {r.unit}{flags}")
        print(
            f"{'':>12}  {r.assertion_class:<10} src={r.source_code} "
            f"sha256={r.content_hash[:12]} available={r.available_at.isoformat(timespec='minutes')}"
        )
    print(f"\n{len(rows)} row(s). Every number above resolves to a stored raw snapshot.")
    return 0


# ---------------------------------------------------------------------------


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="barometer", description="Концертный барометр")
    parser.add_argument("-v", "--verbose", action="store_true")
    parser.add_argument("--config-dir", default=None, help="override config/ location")
    sub = parser.add_subparsers(dest="group", required=True)

    config = sub.add_parser("config", help="registry files").add_subparsers(
        dest="command", required=True
    )
    config.add_parser("validate", help="load and cross-validate every registry").set_defaults(
        func=cmd_config_validate
    )
    config.add_parser("sources", help="list declared sources").set_defaults(func=cmd_config_sources)

    db = sub.add_parser("db", help="database").add_subparsers(dest="command", required=True)
    db.add_parser("sync-registry", help="project config/*.yaml into registry.*").set_defaults(
        func=cmd_db_sync_registry
    )
    db.add_parser("status", help="migration revision and row counts").set_defaults(
        func=cmd_db_status
    )

    venues = sub.add_parser("venues", help="competitor panel").add_subparsers(
        dest="command", required=True
    )
    venues.add_parser("review", help="what still needs an access review").set_defaults(
        func=cmd_venues_review
    )

    ingest = sub.add_parser("ingest", help="collect from sources").add_subparsers(
        dest="command", required=True
    )
    run = ingest.add_parser("run", help="collect one source")
    run.add_argument("--source", required=True, help="source code from config/sources.yaml")
    run.add_argument("--days", type=int, default=14, help="window size in days (default 14)")
    run.add_argument("--dry-run", action="store_true", help="plan requests without sending any")
    run.set_defaults(func=cmd_ingest_run)

    run_all = ingest.add_parser("all", help="collect every cleared, implemented source")
    run_all.add_argument("--days", type=int, default=14, help="window size in days (default 14)")
    run_all.add_argument("--dry-run", action="store_true", help="plan requests without sending any")
    run_all.set_defaults(func=cmd_ingest_all)

    ingest.add_parser("status", help="freshness and health per source").set_defaults(
        func=cmd_ingest_status
    )

    obs = sub.add_parser("observations", help="stored observations").add_subparsers(
        dest="command", required=True
    )
    show = obs.add_parser("show", help="recent observations with their evidence")
    show.add_argument("--series", default=None, help="limit to one series code")
    show.add_argument("--limit", type=int, default=20)
    show.set_defaults(func=cmd_observations_show)

    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    _configure_logging(args.verbose)
    get_settings()
    try:
        return int(args.func(args))
    except ConfigError as exc:
        print(f"config error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
