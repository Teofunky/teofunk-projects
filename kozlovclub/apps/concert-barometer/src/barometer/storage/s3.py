"""S3-compatible snapshot store. Used in staging and production.

Kept import-light: boto3 is an optional dependency, so a local install without
the `storage` extra still works.
"""

from __future__ import annotations

from typing import Any

from barometer.storage.base import SnapshotStore, StoredBlob, content_hash


class S3SnapshotStore(SnapshotStore):
    def __init__(
        self,
        bucket: str,
        client: Any | None = None,
        endpoint_url: str | None = None,
        region: str | None = None,
        prefix: str = "snapshots/",
    ) -> None:
        if client is None:  # pragma: no cover - requires boto3 and a live endpoint
            try:
                import boto3
            except ImportError as exc:
                raise RuntimeError(
                    "S3 storage needs the 'storage' extra: pip install -e '.[storage]'"
                ) from exc
            client = boto3.client("s3", endpoint_url=endpoint_url, region_name=region)
        self.client = client
        self.bucket = bucket
        self.prefix = prefix.rstrip("/") + "/" if prefix else ""

    def _key(self, key_or_uri: str) -> str:
        if key_or_uri.startswith("s3://"):
            _, _, rest = key_or_uri.partition("s3://")
            _, _, key = rest.partition("/")
            return key
        return f"{self.prefix}{key_or_uri}"

    def _uri(self, key: str) -> str:
        return f"s3://{self.bucket}/{key}"

    def put(self, data: bytes, key: str, content_type: str | None = None) -> StoredBlob:
        full_key = self._key(key)
        digest = content_hash(data)
        if self.exists(self._uri(full_key)):
            return StoredBlob(
                uri=self._uri(full_key),
                digest=digest,
                size_bytes=len(data),
                already_present=True,
            )
        extra: dict[str, Any] = {"Metadata": {"sha256": digest}}
        if content_type:
            extra["ContentType"] = content_type
        self.client.put_object(Bucket=self.bucket, Key=full_key, Body=data, **extra)
        return StoredBlob(
            uri=self._uri(full_key), digest=digest, size_bytes=len(data), already_present=False
        )

    def get(self, uri: str) -> bytes:
        try:
            obj = self.client.get_object(Bucket=self.bucket, Key=self._key(uri))
        except Exception as exc:
            raise FileNotFoundError(uri) from exc
        return obj["Body"].read()

    def exists(self, uri: str) -> bool:
        try:
            self.client.head_object(Bucket=self.bucket, Key=self._key(uri))
        except Exception:
            return False
        return True
