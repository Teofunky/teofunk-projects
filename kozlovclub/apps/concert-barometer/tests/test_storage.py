"""Snapshot storage is content-addressed, verified and write-once."""

from __future__ import annotations

import datetime as dt

import pytest

from barometer.storage.base import blob_key, content_hash
from barometer.storage.local import LocalSnapshotStore


def test_hash_is_sha256_hex():
    digest = content_hash(b"hello")
    assert len(digest) == 64
    assert digest == "2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824"


def test_blob_key_groups_by_source_and_day():
    key = blob_key("cbr_fx", dt.date(2026, 9, 21), "abc123", "json")
    assert key == "cbr_fx/2026/09/21/abc123.json"


def test_put_and_get_round_trip(store):
    blob = store.put(b"payload", "src/2026/09/21/deadbeef.json")
    assert blob.already_present is False
    assert store.get(blob.uri) == b"payload"
    assert store.exists(blob.uri)


def test_storing_the_same_bytes_twice_is_a_no_op(store):
    key = "src/2026/09/21/deadbeef.json"
    first = store.put(b"payload", key)
    second = store.put(b"payload", key)
    assert first.digest == second.digest
    assert second.already_present is True


def test_storing_different_bytes_under_one_key_is_refused(store):
    key = "src/2026/09/21/deadbeef.json"
    store.put(b"payload", key)
    with pytest.raises(FileExistsError, match="different content"):
        store.put(b"tampered", key)


def test_put_verified_confirms_the_round_trip(store):
    blob = store.put_verified(b"evidence", "cbr_fx", dt.date(2026, 9, 21), "json")
    assert blob.digest == content_hash(b"evidence")
    assert store.get(blob.uri) == b"evidence"


def test_paths_outside_the_root_are_refused(tmp_path):
    store = LocalSnapshotStore(tmp_path / "snapshots")
    with pytest.raises(ValueError, match="outside the snapshot root"):
        store.put(b"x", "../escape.json")


def test_missing_blob_raises(store):
    with pytest.raises(FileNotFoundError):
        store.get("file://" + str(store.root / "nope.json"))


def test_stored_evidence_is_read_only_on_disk(store):
    import os
    import stat

    blob = store.put(b"evidence", "src/2026/09/21/x.json")
    path = blob.uri.removeprefix("file://")
    mode = stat.S_IMODE(os.stat(path).st_mode)
    assert not mode & stat.S_IWUSR
