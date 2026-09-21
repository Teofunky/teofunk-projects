"""Content-addressed storage for raw snapshots.

Blobs are addressed by SHA-256 of their content, so re-fetching an unchanged
page costs one row and no new bytes, and the hash in ``raw.snapshot`` is a real
integrity check rather than a decoration.
"""

from __future__ import annotations

import hashlib
from abc import ABC, abstractmethod
from dataclasses import dataclass
from datetime import date


def content_hash(data: bytes) -> str:
    """SHA-256, lowercase hex. The identity of a snapshot's bytes."""
    return hashlib.sha256(data).hexdigest()


def blob_key(source_code: str, fetched_on: date, digest: str, suffix: str = "") -> str:
    """Storage key: source and day for browsability, hash for identity."""
    safe_suffix = suffix if suffix.startswith(".") or not suffix else f".{suffix}"
    return f"{source_code}/{fetched_on:%Y/%m/%d}/{digest}{safe_suffix}"


@dataclass(frozen=True, slots=True)
class StoredBlob:
    uri: str
    digest: str
    size_bytes: int
    already_present: bool


class SnapshotStore(ABC):
    """Where raw evidence lives."""

    @abstractmethod
    def put(self, data: bytes, key: str, content_type: str | None = None) -> StoredBlob:
        """Store bytes at ``key``. Idempotent: storing identical bytes twice is a no-op."""

    @abstractmethod
    def get(self, uri: str) -> bytes:
        """Retrieve previously stored bytes. Raises FileNotFoundError if absent."""

    @abstractmethod
    def exists(self, uri: str) -> bool: ...

    def put_verified(
        self, data: bytes, source_code: str, fetched_on: date, suffix: str = ""
    ) -> StoredBlob:
        """Store and confirm the round trip, so a silent write failure cannot pass."""
        digest = content_hash(data)
        blob = self.put(data, blob_key(source_code, fetched_on, digest, suffix))
        if blob.digest != digest:
            raise IntegrityError(
                f"stored digest {blob.digest} does not match content digest {digest}"
            )
        return blob


class IntegrityError(RuntimeError):
    """Raised when stored bytes do not match the hash they were filed under."""
