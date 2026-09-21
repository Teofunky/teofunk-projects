"""The repository promises things about itself. These tests keep the promises."""

from __future__ import annotations

from tests.conftest import PROJECT_ROOT


def test_every_agent_prompt_file_exists(registries):
    for agent in registries.agents.agents:
        path = PROJECT_ROOT / agent.prompt
        assert path.is_file(), f"{agent.code} declares {agent.prompt}, which is missing"
        assert path.read_text(encoding="utf-8").strip(), f"{agent.prompt} is empty"


def test_the_files_the_master_prompt_expects_are_present():
    expected = [
        "README.md",
        "config/agents.yaml",
        "config/sources.yaml",
        "config/index_definition.yaml",
        "config/segments.yaml",
        "config/venues.yaml",
        "db/schema.sql",
        "docs/ARCHITECTURE.md",
        "docs/STATE.md",
        "docs/IMPLEMENTATION_PLAN.md",
        "prompts/README.md",
    ]
    missing = [p for p in expected if not (PROJECT_ROOT / p).is_file()]
    assert not missing, f"missing: {missing}"


def test_there_is_at_least_one_adr_per_hard_decision():
    adrs = sorted((PROJECT_ROOT / "docs" / "adr").glob("[0-9][0-9][0-9][0-9]-*.md"))
    assert len(adrs) >= 9
    for adr in adrs:
        text = adr.read_text(encoding="utf-8")
        assert "## Context" in text, adr.name
        assert "## Decision" in text, adr.name
        assert "## Consequences" in text or "## Why not" in text, adr.name


def test_schema_dump_is_marked_generated():
    head = (PROJECT_ROOT / "db" / "schema.sql").read_text(encoding="utf-8")[:1200]
    assert "GENERATED FILE" in head
    assert "migrations/versions" in head


def test_no_credentials_are_committed_in_the_registries():
    """Only the NAME of an env var belongs in config/."""
    import re

    suspicious = re.compile(
        r"(?i)\b(api[_-]?key|token|secret|password)\s*[:=]\s*['\"]?[A-Za-z0-9._-]{16,}"
    )
    for path in (PROJECT_ROOT / "config").glob("*.yaml"):
        text = path.read_text(encoding="utf-8")
        assert not suspicious.search(text), f"possible credential in {path.name}"


#: Built with chr() on purpose: a literal dash in this file would be an offender
#: of the very rule the test enforces.
LONG_DASHES = (chr(0x2014), chr(0x2013))
TEXT_SUFFIXES = {".py", ".md", ".yaml", ".yml", ".sql", ".sh", ".toml", ".ini"}
SKIP_DIRS = {".venv", ".git", "var", "__pycache__", ".pytest_cache", ".ruff_cache"}


def test_no_long_dashes_anywhere_in_the_repository():
    """House style: commas, colons, full stops or parentheses instead."""
    offenders: list[str] = []
    for path in PROJECT_ROOT.rglob("*"):
        if not path.is_file() or path.suffix not in TEXT_SUFFIXES:
            continue
        if any(part in SKIP_DIRS for part in path.parts):
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        if any(dash in text for dash in LONG_DASHES):
            offenders.append(str(path.relative_to(PROJECT_ROOT)))
    assert not offenders, f"long dashes found in: {offenders}"
