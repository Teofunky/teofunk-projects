"""Filesystem snapshot store. The default for local development and CI."""

from __future__ import annotations

import contextlib
import os
import tempfile
from pathlib import Path

from barometer.storage.base import SnapshotStore, StoredBlob, content_hash

URI_SCHEME = "file://"


class LocalSnapshotStore(SnapshotStore):
    def __init__(self, root: Path) -> None:
        self.root = Path(root).resolve()
        self.root.mkdir(parents=True, exist_ok=True)

    def _path(self, key_or_uri: str) -> Path:
        key = key_or_uri[len(URI_SCHEME) :] if key_or_uri.startswith(URI_SCHEME) else key_or_uri
        path = (self.root / key).resolve() if not Path(key).is_absolute() else Path(key).resolve()
        if not path.is_relative_to(self.root):
            raise ValueError(f"refusing to touch a path outside the snapshot root: {path}")
        return path

    def put(self, data: bytes, key: str, content_type: str | None = None) -> StoredBlob:
        path = self._path(key)
        digest = content_hash(data)
        if path.exists():
            # Content-addressed: same key means same bytes. Verify rather than trust.
            existing = path.read_bytes()
            if content_hash(existing) != digest:
                raise FileExistsError(
                    f"{path} exists with different content than the hash it is filed under"
                )
            return StoredBlob(
                uri=f"{URI_SCHEME}{path}",
                digest=digest,
                size_bytes=len(existing),
                already_present=True,
            )

        path.parent.mkdir(parents=True, exist_ok=True)
        # Write to a temp file and rename, so a crash never leaves a half-written
        # blob that a later run would treat as valid evidence.
        fd, tmp_name = tempfile.mkstemp(dir=path.parent, suffix=".partial")
        try:
            with os.fdopen(fd, "wb") as fh:
                fh.write(data)
                fh.flush()
                os.fsync(fh.fileno())
            os.replace(tmp_name, path)
        except BaseException:
            Path(tmp_name).unlink(missing_ok=True)
            raise
        # Evidence is read-only on disk too. Not every filesystem allows it.
        with contextlib.suppress(OSError):
            path.chmod(0o444)
        return StoredBlob(
            uri=f"{URI_SCHEME}{path}",
            digest=digest,
            size_bytes=len(data),
            already_present=False,
        )

    def get(self, uri: str) -> bytes:
        path = self._path(uri)
        if not path.is_file():
            raise FileNotFoundError(uri)
        return path.read_bytes()

    def exists(self, uri: str) -> bool:
        try:
            return self._path(uri).is_file()
        except ValueError:
            return False
