"""Raw snapshot storage."""

from __future__ import annotations

from barometer.settings import Settings, get_settings
from barometer.storage.base import (
    IntegrityError,
    SnapshotStore,
    StoredBlob,
    blob_key,
    content_hash,
)
from barometer.storage.local import LocalSnapshotStore

__all__ = [
    "IntegrityError",
    "LocalSnapshotStore",
    "SnapshotStore",
    "StoredBlob",
    "blob_key",
    "content_hash",
    "get_snapshot_store",
]


def get_snapshot_store(settings: Settings | None = None) -> SnapshotStore:
    s = settings or get_settings()
    if s.storage_backend == "s3":
        if not s.s3_bucket:
            raise RuntimeError("storage_backend=s3 requires BAROMETER_S3_BUCKET")
        from barometer.storage.s3 import S3SnapshotStore

        return S3SnapshotStore(
            bucket=s.s3_bucket, endpoint_url=s.s3_endpoint_url, region=s.s3_region
        )
    return LocalSnapshotStore(s.snapshot_root)
