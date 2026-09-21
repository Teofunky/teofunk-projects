"""The only supported way to get data into the database.

One transaction per snapshot: the blob is stored, the ``raw.snapshot`` row is
written, and the observations parsed from it are written against that row. If
any part fails, none of it lands, so there is never an observation whose
evidence is missing or a snapshot whose blob was never written.
"""

from __future__ import annotations

import datetime as dt
import uuid
from dataclasses import dataclass, field

from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.orm import Session

from barometer.db.models import EventObservation, Observation, Series, Snapshot, Source, Venue
from barometer.provenance.record import EventRecord, FetchEnvelope, ObservationRecord
from barometer.storage.base import SnapshotStore, content_hash

#: Payloads at or below this size are kept inline on the snapshot row instead of
#: creating an object for a handful of bytes.
INLINE_THRESHOLD_BYTES = 4096


class ProvenanceError(RuntimeError):
    """Raised when a write would produce a number without usable evidence."""


@dataclass(slots=True)
class WriteResult:
    snapshot_id: uuid.UUID
    already_seen: bool
    observations_written: int = 0
    events_written: int = 0
    skipped: list[str] = field(default_factory=list)


class ProvenanceWriter:
    def __init__(self, session: Session, store: SnapshotStore) -> None:
        self.session = session
        self.store = store

    # -- lookups ----------------------------------------------------------

    def _source(self, code: str) -> Source:
        source = self.session.scalar(select(Source).where(Source.code == code))
        if source is None:
            raise ProvenanceError(
                f"source '{code}' is not in registry.source. "
                "Run `barometer db sync-registry` after editing config/sources.yaml."
            )
        return source

    def _series_map(self, codes: set[str]) -> dict[str, Series]:
        if not codes:
            return {}
        rows = self.session.scalars(select(Series).where(Series.code.in_(codes))).all()
        found = {r.code: r for r in rows}
        missing = codes - set(found)
        if missing:
            raise ProvenanceError(f"series not in registry.series: {sorted(missing)}")
        return found

    def _venue(self, code: str) -> Venue:
        venue = self.session.scalar(select(Venue).where(Venue.code == code))
        if venue is None:
            raise ProvenanceError(f"venue '{code}' is not in registry.venue")
        return venue

    # -- the write --------------------------------------------------------

    def write(
        self,
        envelope: FetchEnvelope,
        payload: bytes,
        observations: list[ObservationRecord] | None = None,
        events: list[EventRecord] | None = None,
        run_id: uuid.UUID | None = None,
        vintage_at: dt.datetime | None = None,
    ) -> WriteResult:
        observations = observations or []
        events = events or []
        source = self._source(envelope.source_code)
        digest = content_hash(payload)
        key = envelope.idempotency_key(digest)

        existing = self.session.scalar(select(Snapshot).where(Snapshot.idempotency_key == key))
        if existing is not None:
            # Same source, same request, same day, same bytes: nothing new happened.
            return WriteResult(snapshot_id=existing.id, already_seen=True)

        storage_uri: str | None = None
        inline: str | None = None
        if envelope.retention == "not_retained":
            inline = None
        elif len(payload) <= INLINE_THRESHOLD_BYTES:
            inline = payload.decode("utf-8", errors="replace")
        else:
            blob = self.store.put_verified(
                payload,
                source_code=envelope.source_code,
                fetched_on=envelope.fetched_at.astimezone(dt.UTC).date(),
                suffix=_suffix_for(envelope.content_type),
            )
            storage_uri = blob.uri

        venue_id = self._venue(envelope.venue_code).id if envelope.venue_code else None

        snapshot = Snapshot(
            source_id=source.id,
            venue_id=venue_id,
            idempotency_key=key,
            fetched_at=envelope.fetched_at,
            request_url=envelope.request_url,
            request_method=envelope.request_method,
            request_params=envelope.request_params,
            http_status=envelope.http_status,
            content_type=envelope.content_type,
            content_hash=digest,
            content_bytes=len(payload),
            storage_uri=storage_uri,
            content_inline=inline,
            retention=envelope.retention,
            legal_basis=envelope.legal_basis,
            observation_period_start=envelope.observation_period_start,
            observation_period_end=envelope.observation_period_end,
            geography=envelope.geography,
            collector=envelope.collector,
            collector_version=envelope.collector_version,
            run_id=run_id,
            notes=envelope.notes,
        )
        self.session.add(snapshot)
        self.session.flush()

        result = WriteResult(snapshot_id=snapshot.id, already_seen=False)
        vintage = vintage_at or envelope.fetched_at

        if observations:
            result.observations_written = self._write_observations(
                observations, snapshot, envelope, vintage, result
            )
        if events:
            result.events_written = self._write_events(events, snapshot)

        return result

    def _write_observations(
        self,
        records: list[ObservationRecord],
        snapshot: Snapshot,
        envelope: FetchEnvelope,
        vintage: dt.datetime,
        result: WriteResult,
    ) -> int:
        series = self._series_map({r.series_code for r in records})
        rows = []
        for rec in records:
            definition = series[rec.series_code]
            if rec.unit != definition.unit:
                raise ProvenanceError(
                    f"{rec.series_code}: adapter produced unit '{rec.unit}' but the "
                    f"registry declares '{definition.unit}'. Convert in the adapter "
                    "and flag it with quality_flag 'unit_converted'."
                )
            rows.append(
                {
                    "observed_at": rec.observed_at,
                    "series_id": definition.id,
                    "vintage_at": vintage,
                    "period": rec.period,
                    "period_end": rec.period_end,
                    "value": rec.value,
                    "missing_reason": rec.missing_reason,
                    "unit": rec.unit,
                    "geography": rec.geography,
                    "assertion_class": rec.assertion_class.value,
                    "snapshot_id": snapshot.id,
                    "retrieved_at": envelope.fetched_at,
                    "published_at": rec.published_at,
                    "quality_flags": rec.quality_flags,
                    "methodology_notes": rec.methodology_notes,
                    "transform": rec.transform,
                }
            )

        # An identical (series, period, vintage) is the same knowledge stated twice.
        # RETURNING gives an exact count of what was actually inserted; rowcount is
        # not dependable for ON CONFLICT DO NOTHING across drivers.
        stmt = (
            pg_insert(Observation)
            .values(rows)
            .on_conflict_do_nothing(index_elements=["observed_at", "series_id", "vintage_at"])
            .returning(Observation.series_id)
        )
        written = len(self.session.execute(stmt).all())
        if written < len(rows):
            result.skipped.append(
                f"{len(rows) - written} observation(s) already present at this vintage"
            )
        return written

    def _write_events(self, records: list[EventRecord], snapshot: Snapshot) -> int:
        venues = {code: self._venue(code) for code in {r.venue_code for r in records}}
        self.session.add_all(
            [
                EventObservation(
                    observed_at=rec.observed_at,
                    venue_id=venues[rec.venue_code].id,
                    snapshot_id=snapshot.id,
                    event_key=rec.event_key,
                    external_id=rec.external_id,
                    title=rec.title,
                    starts_at=rec.starts_at,
                    stage=rec.stage,
                    ticket_provider=rec.ticket_provider,
                    ticket_url=rec.ticket_url,
                    price_min=rec.price_min,
                    price_max=rec.price_max,
                    currency=rec.currency,
                    availability_label=rec.availability_label,
                    categories_listed=rec.categories_listed,
                    raw_fields=rec.raw_fields,
                )
                for rec in records
            ]
        )
        self.session.flush()
        return len(records)


def _suffix_for(content_type: str | None) -> str:
    if not content_type:
        return ".bin"
    base = content_type.split(";")[0].strip().lower()
    return {
        "application/json": ".json",
        "text/json": ".json",
        "application/xml": ".xml",
        "text/xml": ".xml",
        "text/html": ".html",
        "text/csv": ".csv",
        "text/plain": ".txt",
    }.get(base, ".bin")
