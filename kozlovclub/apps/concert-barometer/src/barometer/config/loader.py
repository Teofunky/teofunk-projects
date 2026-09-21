"""Loading and caching of the YAML registries."""

from __future__ import annotations

import functools
from pathlib import Path
from typing import Any, TypeVar

import yaml
from pydantic import BaseModel, ValidationError

from barometer.config.models import (
    AgentRegistry,
    IndexDefinition,
    Registries,
    SegmentRegistry,
    SourceRegistry,
    VenueRegistry,
)

M = TypeVar("M", bound=BaseModel)


class ConfigError(RuntimeError):
    """Raised when a registry file is missing, unparseable or invalid."""


def default_config_dir() -> Path:
    """Project-root/config, resolved from this file's location.

    Overridable with BAROMETER_CONFIG_DIR via barometer.settings.
    """
    return Path(__file__).resolve().parents[3] / "config"


def _read_yaml(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise ConfigError(f"registry file not found: {path}")
    try:
        data = yaml.safe_load(path.read_text(encoding="utf-8"))
    except yaml.YAMLError as exc:
        raise ConfigError(f"{path.name}: YAML parse error: {exc}") from exc
    if not isinstance(data, dict):
        raise ConfigError(f"{path.name}: expected a mapping at the top level")
    return data


def _parse(model: type[M], path: Path) -> M:
    try:
        return model.model_validate(_read_yaml(path))
    except ValidationError as exc:
        raise ConfigError(f"{path.name} failed validation:\n{exc}") from exc


def load_sources(config_dir: Path | None = None) -> SourceRegistry:
    return _parse(SourceRegistry, (config_dir or default_config_dir()) / "sources.yaml")


def load_segments(config_dir: Path | None = None) -> SegmentRegistry:
    return _parse(SegmentRegistry, (config_dir or default_config_dir()) / "segments.yaml")


def load_index_definition(config_dir: Path | None = None) -> IndexDefinition:
    return _parse(IndexDefinition, (config_dir or default_config_dir()) / "index_definition.yaml")


def load_venues(config_dir: Path | None = None) -> VenueRegistry:
    return _parse(VenueRegistry, (config_dir or default_config_dir()) / "venues.yaml")


def load_agents(config_dir: Path | None = None) -> AgentRegistry:
    return _parse(AgentRegistry, (config_dir or default_config_dir()) / "agents.yaml")


def load_registries(config_dir: Path | None = None) -> Registries:
    """Load and cross-validate every registry.

    Raises ConfigError listing every problem found, so a broken config is fixed in
    one pass rather than one error at a time.
    """
    root = config_dir or default_config_dir()
    try:
        return Registries(
            sources=load_sources(root),
            segments=load_segments(root),
            index=load_index_definition(root),
            venues=load_venues(root),
            agents=load_agents(root),
        )
    except ValidationError as exc:
        raise ConfigError(f"cross-registry validation failed:\n{exc}") from exc


@functools.lru_cache(maxsize=4)
def get_registries(config_dir: str | None = None) -> Registries:
    """Process-wide cached accessor. Tests should call load_registries directly."""
    return load_registries(Path(config_dir) if config_dir else None)
