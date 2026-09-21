"""The registries load, cross-validate, and refuse to encode a violated principle."""

from __future__ import annotations

import copy

import pytest
import yaml
from pydantic import ValidationError

from barometer.config.loader import ConfigError, load_registries
from barometer.config.models import (
    AgentDef,
    AssertionClass,
    Measures,
    SeriesDef,
    SourceDef,
    SourceRegistry,
    VenueDef,
)
from tests.conftest import CONFIG_DIR


def test_registries_load_and_cross_validate(registries):
    assert registries.sources.sources
    assert registries.index.components
    assert registries.agents.agents


def test_every_index_input_is_a_declared_series(registries):
    declared = set(registries.sources.all_series)
    for component in registries.index.components:
        for inp in component.inputs:
            assert inp.series in declared, f"{component.code} -> {inp.series}"


def test_every_series_belongs_to_a_declared_segment(registries):
    segments = set(registries.segments.by_code)
    for code, series in registries.sources.all_series.items():
        assert series.segment in segments, code


def test_thirteen_agents_are_declared_and_all_scheduled(registries):
    assert len(registries.agents.agents) == 13
    scheduled = {
        code for stage in registries.agents.orchestration.stages for code in stage.agent_codes
    }
    for agent in registries.agents.agents:
        if agent.code == "chief_analyst":
            continue
        assert agent.code in scheduled, f"{agent.code} is declared but never runs"


# ---------------------------------------------------------------------------
# Principle 1: LLMs do not invent the barometer
# ---------------------------------------------------------------------------


def test_no_agent_may_write_the_model_layer(registries):
    for agent in registries.agents.agents:
        assert "db.write_model" not in agent.allowed_tools
        assert "quant.write" not in agent.allowed_tools


def test_agent_granting_itself_model_writes_is_rejected():
    with pytest.raises(ValidationError, match="principle 1"):
        AgentDef(
            code="rogue",
            name_ru="Мошенник",
            prompt="prompts/rogue.md",
            role="collector",
            allowed_tools=["db.read", "db.write_model"],
            evals=["evals/rogue/x.yaml"],
        )


def test_every_agent_has_at_least_one_eval(registries):
    for agent in registries.agents.agents:
        assert agent.evals, f"{agent.code} has no eval"


# ---------------------------------------------------------------------------
# Principle 3 and 5: an inference is never a fact
# ---------------------------------------------------------------------------


def test_inferred_series_cannot_claim_fact():
    with pytest.raises(ValidationError, match="may claim at most"):
        SeriesDef(
            code="venue.made_up_sales.daily",
            name_ru="Продажи конкурентов",
            unit="count",
            frequency="daily",
            assertion_class=AssertionClass.FACT,
            segment="competition_pressure",
            measures=Measures.INFERRED,
        )


def test_derived_series_cannot_claim_fact():
    with pytest.raises(ValidationError, match="may claim at most"):
        SeriesDef(
            code="x.derived.daily",
            name_ru="Производная",
            unit="index",
            frequency="daily",
            assertion_class=AssertionClass.FACT,
            segment="weather",
            measures=Measures.DERIVED,
        )


def test_direct_series_on_a_proxy_source_may_still_be_a_fact(registries):
    """Yandex reporting N impressions is an observed fact even though search
    only proxies demand. The proxy caveat belongs on the interpretation."""
    wordstat = registries.sources.by_code["yandex_wordstat"]
    assert wordstat.is_proxy
    basket = next(s for s in wordstat.series if s.code.endswith("concert_basket.msk.weekly"))
    assert basket.measures is Measures.DIRECT
    assert basket.assertion_class is AssertionClass.FACT


def test_competition_pressure_series_is_inferred_and_never_a_fact(registries):
    series = registries.sources.all_series["venue.inventory_pressure.msk.daily"]
    assert series.measures is Measures.INFERRED
    assert series.assertion_class is AssertionClass.ESTIMATE


def test_proxy_source_must_explain_what_it_does_not_observe():
    with pytest.raises(ValidationError, match="methodology_notes"):
        SourceDef(
            code="sloppy_proxy",
            name="Sloppy",
            name_ru="Небрежный",
            kind="web_page",
            publisher="x",
            geography="RU-MOW",
            access_policy="public_page",
            robots_policy="allowed",
            update_cadence="daily",
            typical_lag_days=0,
            is_proxy=True,
            proxy_for="sales",
        )


# ---------------------------------------------------------------------------
# Principle 10: access must be resolved before fetching
# ---------------------------------------------------------------------------


def test_source_cannot_be_enabled_with_unknown_robots_status():
    with pytest.raises(ValidationError, match="Resolve access before enabling"):
        SourceDef(
            code="eager",
            name="Eager",
            name_ru="Нетерпеливый",
            kind="web_page",
            publisher="x",
            geography="RU-MOW",
            access_policy="public_page",
            robots_policy="unknown",
            update_cadence="daily",
            typical_lag_days=0,
            enabled=True,
        )


def test_source_cannot_be_enabled_with_outstanding_verification():
    with pytest.raises(ValidationError, match="verification_required"):
        SourceDef(
            code="unchecked",
            name="Unchecked",
            name_ru="Непроверенный",
            kind="api",
            publisher="x",
            geography="RU-MOW",
            access_policy="official_api",
            robots_policy="allowed",
            update_cadence="daily",
            typical_lag_days=0,
            enabled=True,
            verification_required=["confirm the endpoint"],
        )


def test_no_enabled_source_in_the_shipped_config_has_unresolved_access(registries):
    for source in registries.sources.sources:
        if source.enabled:
            assert source.robots_policy.value in ("allowed", "not_applicable"), source.code
            assert not source.verification_required, source.code


def test_competitor_scraping_is_off_until_reviewed(registries):
    """The panel ships disabled. Enabling it is a human decision, per venue."""
    assert not registries.sources.by_code["venue_public_pages"].enabled
    for venue in registries.venues.panel:
        assert not venue.enabled, venue.code


# ---------------------------------------------------------------------------
# "capacity only if sourced"
# ---------------------------------------------------------------------------


def test_capacity_without_a_source_is_rejected():
    with pytest.raises(ValidationError, match="capacity is set without"):
        VenueDef(
            code="guessy",
            canonical_name="Guessy",
            canonical_name_ru="Наугад",
            category="jazz_club",
            source_access_policy="pending_review",
            capacity=250,
        )


def test_no_venue_in_the_shipped_config_has_an_unsourced_capacity(registries):
    for venue in registries.venues.venues:
        assert venue.capacity is None or venue.capacity_source_url is not None


# ---------------------------------------------------------------------------
# Principles 7 and 8: forecast integrity cannot be configured away
# ---------------------------------------------------------------------------


def test_forecast_integrity_flags_cannot_be_relaxed(registries):
    integrity = registries.index.forecast.integrity
    assert integrity.overwrite_allowed is False
    assert integrity.feature_publication_lag_enforced is True
    assert integrity.store_every_vintage is True


def test_relaxing_overwrite_in_yaml_is_rejected(tmp_path):
    raw = yaml.safe_load((CONFIG_DIR / "index_definition.yaml").read_text(encoding="utf-8"))
    broken = copy.deepcopy(raw)
    broken["forecast"]["integrity"]["overwrite_allowed"] = True

    target = tmp_path / "config"
    target.mkdir()
    for name in ("sources.yaml", "segments.yaml", "venues.yaml", "agents.yaml"):
        (target / name).write_text((CONFIG_DIR / name).read_text(encoding="utf-8"), "utf-8")
    (target / "index_definition.yaml").write_text(yaml.safe_dump(broken, allow_unicode=True))

    with pytest.raises(ConfigError, match="overwrite_allowed"):
        load_registries(target)


def test_ensemble_always_carries_a_benchmark(registries):
    benchmarks = [m for m in registries.index.forecast.ensemble if m.role == "benchmark"]
    assert benchmarks
    assert all(m.must_always_run for m in benchmarks)


# ---------------------------------------------------------------------------
# Calibration gate
# ---------------------------------------------------------------------------


def test_uncalibrated_index_may_not_be_called_a_sales_forecast(registries):
    calibration = registries.index.calibration
    assert calibration.status == "uncalibrated"
    assert calibration.sales_language_allowed is False
    assert registries.publishable_label_for_demand() == "Оценка внешнего спроса"
    assert calibration.labels["uncalibrated"].forbidden_ru == "Прогноз продаж рынка"


# ---------------------------------------------------------------------------
# Units
# ---------------------------------------------------------------------------


def test_money_and_counts_and_indices_never_share_an_axis(registries):
    seg = registries.segments
    assert not seg.may_share_axis("money_nominal", "index")
    assert not seg.may_share_axis("count", "index")
    assert not seg.may_share_axis("money_nominal", "count")
    assert seg.may_share_axis("index", "index")


def test_compare_mode_forces_index_normalisation(registries):
    compare = next(v for v in registries.segments.view_modes if v.code == "compare")
    assert compare.forces_index_100 is True
    assert compare.max_layers == 5


# ---------------------------------------------------------------------------
# Structural checks
# ---------------------------------------------------------------------------


def test_duplicate_source_codes_are_rejected(registries):
    raw = yaml.safe_load((CONFIG_DIR / "sources.yaml").read_text(encoding="utf-8"))
    raw["sources"].append(copy.deepcopy(raw["sources"][0]))
    with pytest.raises(ValidationError, match="duplicate source code"):
        SourceRegistry.model_validate(raw)


def test_segment_hierarchy_has_no_cycles(registries):
    for segment in registries.segments.segments:
        seen, node = set(), segment.code
        while node is not None:
            assert node not in seen
            seen.add(node)
            node = registries.segments.by_code[node].parent
