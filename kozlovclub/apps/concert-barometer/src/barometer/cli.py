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
