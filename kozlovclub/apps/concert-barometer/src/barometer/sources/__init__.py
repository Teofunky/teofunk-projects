"""Concrete source adapters, and the registry that maps a source code to one.

An adapter appears here only once its source is declared in config/sources.yaml.
Having a class is not permission to run it: the runner still checks `enabled`,
robots status and credentials before any request is made.
"""

from __future__ import annotations

from barometer.config.models import SourceDef, SourceRegistry
from barometer.ingest.adapter import SourceAdapter
from barometer.ingest.http import HttpFetcher
from barometer.sources.base import HttpAdapterMixin, build_fetcher
from barometer.sources.cbr_fx import CbrFxAdapter
from barometer.sources.cbr_key_rate import CbrKeyRateAdapter
from barometer.sources.open_meteo import OpenMeteoAdapter
from barometer.sources.production_calendar import ProductionCalendarAdapter

ADAPTERS: dict[str, type[SourceAdapter]] = {
    CbrKeyRateAdapter.source_code: CbrKeyRateAdapter,
    CbrFxAdapter.source_code: CbrFxAdapter,
    OpenMeteoAdapter.source_code: OpenMeteoAdapter,
    ProductionCalendarAdapter.source_code: ProductionCalendarAdapter,
}

__all__ = [
    "ADAPTERS",
    "CbrFxAdapter",
    "CbrKeyRateAdapter",
    "OpenMeteoAdapter",
    "ProductionCalendarAdapter",
    "build_adapter",
    "implemented_source_codes",
    "missing_adapters",
]


class NoAdapterError(LookupError):
    """Raised when a source is declared but nothing can collect it yet."""


def build_adapter(
    source: SourceDef,
    fetcher: HttpFetcher | None = None,
) -> SourceAdapter:
    cls = ADAPTERS.get(source.code)
    if cls is None:
        raise NoAdapterError(
            f"source '{source.code}' has no adapter yet. Implemented: {sorted(ADAPTERS)}"
        )
    adapter = cls(source)
    if fetcher is not None and isinstance(adapter, HttpAdapterMixin):
        adapter.bind_fetcher(fetcher)
    return adapter


def implemented_source_codes() -> set[str]:
    return set(ADAPTERS)


def missing_adapters(registry: SourceRegistry) -> list[str]:
    """Declared sources with no adapter, so the gap is visible rather than assumed."""
    return sorted({s.code for s in registry.sources} - implemented_source_codes())


def shared_fetcher() -> HttpFetcher:
    return build_fetcher()
