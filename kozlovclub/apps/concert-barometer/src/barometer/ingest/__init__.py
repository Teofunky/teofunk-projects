"""Ingestion: adapters, retry policy and the runner that enforces the rules."""

from barometer.ingest.adapter import (
    AdapterError,
    FetchOutcome,
    FetchPlan,
    ParseOutcome,
    PermanentAdapterError,
    SourceAdapter,
    TransientAdapterError,
    Window,
)
from barometer.ingest.runner import (
    IngestReport,
    IngestRunner,
    SourceNotPermitted,
    check_permitted,
)

__all__ = [
    "AdapterError",
    "FetchOutcome",
    "FetchPlan",
    "IngestReport",
    "IngestRunner",
    "ParseOutcome",
    "PermanentAdapterError",
    "SourceAdapter",
    "SourceNotPermitted",
    "TransientAdapterError",
    "Window",
    "check_permitted",
]
