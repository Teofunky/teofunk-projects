"""Project config/*.yaml into registry.* tables.

Idempotent and additive. Rows are inserted or updated; nothing is deleted,
because an observation may reference a series that has since been retired from
the YAML and deleting it would orphan real evidence. Retired entries are marked
``enabled = false`` instead.
"""

from __future__ import annotations

import hashlib
from dataclasses import dataclass, field
from pathlib import Path

import structlog
from sqlalchemy import select
from sqlalchemy.orm import Session

from barometer.config.loader import default_config_dir, load_registries
from barometer.config.models import Registries
from barometer.db.models import SchemaFingerprint, Segment, Series, Source, Venue

log = structlog.get_logger(__name__)

REGISTRY_FILES = (
    "sources.yaml",
    "segments.yaml",
    "index_definition.yaml",
    "venues.yaml",
    "agents.yaml",
)


@dataclass(slots=True)
class SyncReport:
    sources_created: int = 0
    sources_updated: int = 0
    segments_created: int = 0
    segments_updated: int = 0
    series_created: int = 0
    series_updated: int = 0
    venues_created: int = 0
    venues_updated: int = 0
    retired: list[str] = field(default_factory=list)

    def summary(self) -> str:
        return (
            f"sources +{self.sources_created}/~{self.sources_updated}, "
            f"segments +{self.segments_created}/~{self.segments_updated}, "
            f"series +{self.series_created}/~{self.series_updated}, "
            f"venues +{self.venues_created}/~{self.venues_updated}"
            + (f", retired {len(self.retired)}" if self.retired else "")
        )


def sync_registries(
    session: Session,
    registries: Registries | None = None,
    config_dir: Path | None = None,
) -> SyncReport:
    root = config_dir or default_config_dir()
    regs = registries or load_registries(root)
    report = SyncReport()

    _sync_segments(session, regs, report)
    session.flush()
    _sync_sources_and_series(session, regs, report)
    session.flush()
    _sync_venues(session, regs, report)
    _record_fingerprints(session, root)
    session.flush()

    log.info("registry.synced", summary=report.summary())
    return report


def _sync_segments(session: Session, regs: Registries, report: SyncReport) -> None:
    existing = {s.code: s for s in session.scalars(select(Segment)).all()}
    # Parents before children so the self-referencing FK is always satisfiable.
    ordered = sorted(regs.segments.segments, key=lambda s: (s.parent is not None, s.display_order))
    for definition in ordered:
        row = existing.get(definition.code)
        if row is None:
            row = Segment(code=definition.code)
            session.add(row)
            report.segments_created += 1
        else:
            report.segments_updated += 1
        row.name_ru = definition.name_ru
        row.kind = definition.kind
        row.parent_code = definition.parent
        row.display_order = definition.display_order
        row.unit_class = definition.unit_class
        row.min_series = definition.min_series
        row.venue_category = definition.venue_category
        row.proxy_badge_ru = definition.proxy_badge_ru
        row.visible_in_ui = definition.visible_in_ui


def _sync_sources_and_series(session: Session, regs: Registries, report: SyncReport) -> None:
    existing_sources = {s.code: s for s in session.scalars(select(Source)).all()}
    existing_series = {s.code: s for s in session.scalars(select(Series)).all()}
    declared_series: set[str] = set()

    for definition in regs.sources.sources:
        row = existing_sources.get(definition.code)
        if row is None:
            row = Source(code=definition.code)
            session.add(row)
            report.sources_created += 1
        else:
            report.sources_updated += 1
        row.name = definition.name
        row.name_ru = definition.name_ru
        row.kind = definition.kind
        row.publisher = definition.publisher
        row.homepage_url = str(definition.homepage_url) if definition.homepage_url else None
        row.endpoint_url = definition.endpoint_url
        row.geography = definition.geography
        row.access_policy = definition.access_policy
        row.requires_credentials = definition.requires_credentials
        row.credential_env_var = definition.credential_env_var
        row.robots_policy = definition.robots_policy
        row.rate_limit_per_min = definition.rate_limit_per_min
        row.update_cadence = definition.update_cadence
        row.typical_lag_days = definition.typical_lag_days
        row.license = definition.license
        row.terms_url = str(definition.terms_url) if definition.terms_url else None
        row.is_proxy = definition.is_proxy
        row.proxy_for = definition.proxy_for
        row.methodology_notes = definition.methodology_notes
        row.verification_required = list(definition.verification_required)
        row.enabled = definition.enabled
        session.flush()

        for ser in definition.series:
            declared_series.add(ser.code)
            srow = existing_series.get(ser.code)
            if srow is None:
                srow = Series(code=ser.code)
                session.add(srow)
                report.series_created += 1
            else:
                report.series_updated += 1
            srow.source_id = row.id
            srow.segment_code = ser.segment
            srow.name_ru = ser.name_ru
            srow.unit = ser.unit
            srow.frequency = ser.frequency
            srow.measures = ser.measures
            srow.assertion_class = ser.assertion_class
            srow.aggregation = ser.aggregation
            srow.description_ru = ser.description_ru
            srow.proxy_caveat_ru = ser.proxy_caveat_ru

    # A series removed from YAML keeps its rows; it is simply no longer collected.
    for code in set(existing_series) - declared_series:
        report.retired.append(f"series:{code}")


def _sync_venues(session: Session, regs: Registries, report: SyncReport) -> None:
    import datetime as dt

    existing = {v.code: v for v in session.scalars(select(Venue)).all()}
    for definition in regs.venues.venues:
        row = existing.get(definition.code)
        if row is None:
            row = Venue(code=definition.code)
            session.add(row)
            report.venues_created += 1
        else:
            report.venues_updated += 1
        row.canonical_name = definition.canonical_name
        row.canonical_name_ru = definition.canonical_name_ru
        row.category = definition.category
        row.city = regs.venues.city
        row.geography = regs.venues.default_geography
        row.website_url = str(definition.website_url) if definition.website_url else None
        row.url_verified = definition.url_verified
        row.ticket_providers = list(definition.ticket_providers)
        row.relationship_type = definition.relationship
        row.source_access_policy = definition.source_access_policy
        row.robots_allows_crawl = definition.robots_allows_crawl
        row.access_reviewed_at = (
            dt.datetime.fromisoformat(definition.access_reviewed_at)
            if definition.access_reviewed_at
            else None
        )
        row.capacity = definition.capacity
        row.capacity_source_url = (
            str(definition.capacity_source_url) if definition.capacity_source_url else None
        )
        row.enabled = definition.enabled
        row.notes_ru = definition.notes_ru


def _record_fingerprints(session: Session, config_dir: Path) -> None:
    """Hash each registry file so a later silent edit is visible in ops."""
    for name in REGISTRY_FILES:
        path = config_dir / name
        if not path.is_file():
            continue
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        already = session.scalar(
            select(SchemaFingerprint).where(
                SchemaFingerprint.file_name == name,
                SchemaFingerprint.content_hash == digest,
            )
        )
        if already is None:
            session.add(SchemaFingerprint(file_name=name, content_hash=digest))
