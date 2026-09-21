"""Typed, self-validating models for the YAML registries in config/.

The registries are the contract between "what we are allowed to observe" and
"what the system computes". Validation here is not cosmetic: several of the
non-negotiable principles are enforced as model validators and cannot be
bypassed by editing YAML.

Enforced here
-------------
* P4  every series declares unit, frequency, geography and an assertion class
* P5  a proxy source can never carry a series with assertion_class 'fact'
      above the proxy ceiling, and competitor inventory is never 'sales'
* P10 a source is only fetchable when robots/ToS status has been resolved
* "capacity only if sourced" for venues
* index components may only reference series that some source declares
"""

from __future__ import annotations

from enum import StrEnum
from typing import Annotated, Any, Literal

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    HttpUrl,
    ValidationInfo,
    model_validator,
)

Code = Annotated[str, Field(pattern=r"^[a-z0-9][a-z0-9_.]*$", max_length=120)]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)


# ---------------------------------------------------------------------------
# Enumerations
# ---------------------------------------------------------------------------


class AssertionClass(StrEnum):
    """Principle 3: every value in the system is one of these, explicitly."""

    FACT = "fact"
    DERIVED_METRIC = "derived_metric"
    ESTIMATE = "estimate"
    HYPOTHESIS = "hypothesis"
    FORECAST = "forecast"

    @property
    def rank(self) -> int:
        order = {
            "fact": 0,
            "derived_metric": 1,
            "estimate": 2,
            "hypothesis": 3,
            "forecast": 4,
        }
        return order[self.value]


class SourceKind(StrEnum):
    API = "api"
    OFFICIAL_STAT = "official_stat"
    WEB_PAGE = "web_page"
    LICENSED_FEED = "licensed_feed"
    MANUAL = "manual"
    FILE_UPLOAD = "file_upload"


class AccessPolicy(StrEnum):
    OFFICIAL_API = "official_api"
    LICENSED = "licensed"
    PUBLIC_PAGE = "public_page"
    MANUAL_EXPORT = "manual_export"
    AUTHORISED = "authorised"
    PENDING_REVIEW = "pending_review"


class RobotsPolicy(StrEnum):
    ALLOWED = "allowed"
    DISALLOWED = "disallowed"
    UNKNOWN = "unknown"
    NOT_APPLICABLE = "not_applicable"


class Frequency(StrEnum):
    DAILY = "daily"
    WEEKLY = "weekly"
    MONTHLY = "monthly"
    QUARTERLY = "quarterly"
    YEARLY = "yearly"
    HOURLY = "hourly"
    IRREGULAR = "irregular"


class Cadence(StrEnum):
    HOURLY = "hourly"
    DAILY = "daily"
    WEEKLY = "weekly"
    MONTHLY = "monthly"
    QUARTERLY = "quarterly"
    YEARLY = "yearly"
    IRREGULAR = "irregular"


class Aggregation(StrEnum):
    SUM = "sum"
    MEAN = "mean"
    LAST = "last"
    MEDIAN = "median"
    INDEX = "index"


class Measures(StrEnum):
    """How a series relates to what the source actually reported.

    This, not the source's proxy status, is what bounds the assertion class.
    "Yandex reported N impressions for this phrase last week" is a FACT even
    though search is a proxy for demand. "Demand pressure inferred from listed
    inventory" is not a fact on any source.
    """

    #: The source publishes this number; we transcribe it.
    DIRECT = "direct"
    #: Deterministic function of direct observations (a ratio, a median, an index).
    DERIVED = "derived"
    #: An inference about the concept the source only proxies.
    INFERRED = "inferred"

    @property
    def weakest_allowed_class(self) -> AssertionClass:
        return {
            "direct": AssertionClass.FACT,
            "derived": AssertionClass.DERIVED_METRIC,
            "inferred": AssertionClass.ESTIMATE,
        }[self.value]


class VenueCategory(StrEnum):
    JAZZ_CLUB = "jazz_club"
    MUSIC_CLUB = "music_club"
    CONCERT_HALL = "concert_hall"
    THEATRE = "theatre"
    STANDUP = "standup"
    FESTIVAL = "festival"
    RESTAURANT_BAR = "restaurant_bar"


class UnitClass(StrEnum):
    INDEX = "index"
    MONEY_NOMINAL = "money_nominal"
    COUNT = "count"
    PERCENT = "percent"
    PHYSICAL = "physical"


# ---------------------------------------------------------------------------
# sources.yaml
# ---------------------------------------------------------------------------


class SeriesDef(StrictModel):
    code: Code
    name_ru: str
    unit: str
    frequency: Frequency
    assertion_class: AssertionClass
    segment: Code
    measures: Measures = Measures.DIRECT
    aggregation: Aggregation = Aggregation.LAST
    description_ru: str | None = None
    proxy_caveat_ru: str | None = None

    @model_validator(mode="after")
    def _claim_matches_what_is_measured(self) -> SeriesDef:
        """Principle 3 and 5: an inference is never a fact, on any source."""
        floor = self.measures.weakest_allowed_class
        if self.assertion_class.rank < floor.rank:
            raise ValueError(
                f"series {self.code}: measures='{self.measures}' but claims "
                f"assertion_class='{self.assertion_class}'. A '{self.measures}' series "
                f"may claim at most '{floor}'."
            )
        return self


class SourceDef(StrictModel):
    code: Code
    name: str
    name_ru: str
    kind: SourceKind
    publisher: str
    homepage_url: HttpUrl | None = None
    endpoint_url: str | None = None
    geography: str
    access_policy: AccessPolicy
    requires_credentials: bool = False
    credential_env_var: str | None = None
    robots_policy: RobotsPolicy
    rate_limit_per_min: int | None = Field(default=None, ge=1, le=600)
    update_cadence: Cadence
    typical_lag_days: int = Field(ge=0, le=3650)
    license: str | None = None
    terms_url: HttpUrl | None = None
    is_proxy: bool = False
    proxy_for: str | None = None
    enabled: bool = False
    verification_required: list[str] = Field(default_factory=list)
    methodology_notes: str | None = None
    series: list[SeriesDef] = Field(default_factory=list)

    @model_validator(mode="after")
    def _credentials_are_named_not_embedded(self) -> SourceDef:
        if self.requires_credentials and not self.credential_env_var:
            raise ValueError(f"source {self.code}: requires_credentials needs credential_env_var")
        return self

    @model_validator(mode="after")
    def _proxy_must_explain_itself(self) -> SourceDef:
        """Principle 5. A proxy must name what it stands in for and how it is read."""
        if not self.is_proxy:
            return self
        if not self.proxy_for:
            raise ValueError(f"source {self.code}: is_proxy requires proxy_for")
        if not self.methodology_notes:
            raise ValueError(
                f"source {self.code}: a proxy source requires methodology_notes "
                "stating what it does and does not observe"
            )
        return self

    @model_validator(mode="after")
    def _cannot_enable_until_access_is_resolved(self) -> SourceDef:
        """Principle 10. Unknown robots status blocks automated fetching."""
        if not self.enabled:
            return self
        if self.robots_policy in (RobotsPolicy.UNKNOWN, RobotsPolicy.DISALLOWED):
            raise ValueError(
                f"source {self.code}: enabled=true with robots_policy="
                f"'{self.robots_policy}'. Resolve access before enabling."
            )
        if self.verification_required:
            raise ValueError(
                f"source {self.code}: enabled=true while verification_required is "
                f"non-empty: {self.verification_required}"
            )
        if self.access_policy is AccessPolicy.PENDING_REVIEW:
            raise ValueError(f"source {self.code}: enabled=true with access_policy=pending_review")
        return self

    @property
    def is_fetchable(self) -> bool:
        return self.enabled and self.robots_policy in (
            RobotsPolicy.ALLOWED,
            RobotsPolicy.NOT_APPLICABLE,
        )


class SourceRegistry(StrictModel):
    schema_version: Literal[1]
    default_geography: str
    default_timezone: str
    sources: list[SourceDef]

    @model_validator(mode="after")
    def _codes_unique(self) -> SourceRegistry:
        _require_unique((s.code for s in self.sources), "source code")
        _require_unique(
            (ser.code for s in self.sources for ser in s.series),
            "series code",
        )
        return self

    @property
    def by_code(self) -> dict[str, SourceDef]:
        return {s.code: s for s in self.sources}

    @property
    def all_series(self) -> dict[str, SeriesDef]:
        return {ser.code: ser for s in self.sources for ser in s.series}

    def source_of_series(self, series_code: str) -> SourceDef:
        for s in self.sources:
            for ser in s.series:
                if ser.code == series_code:
                    return s
        raise KeyError(series_code)


# ---------------------------------------------------------------------------
# segments.yaml
# ---------------------------------------------------------------------------


class UnitClassDef(StrictModel):
    code: UnitClass
    name_ru: str
    axis_label_ru: str
    never_share_axis_with: list[UnitClass] = Field(default_factory=list)


class SegmentDef(StrictModel):
    code: Code
    name_ru: str
    kind: Literal["composite", "category", "driver", "internal"]
    parent: Code | None = None
    display_order: int
    unit_class: UnitClass
    min_series: int = Field(ge=0)
    source_of_value: str | None = None
    index_code: Code | None = None
    venue_category: VenueCategory | None = None
    label_gate: str | None = None
    requires_interval: bool = False
    proxy_badge_ru: str | None = None
    visible_in_ui: bool = True


class ViewModeDef(StrictModel):
    code: Code
    name_ru: str
    min_layers: int | None = None
    max_layers: int | None = None
    forces_index_100: bool = False
    requires: str | None = None
    requires_deseasonalised: bool = False
    requires_interval: bool = False
    lags_days: list[int] | None = None
    horizons_days: list[int] | None = None
    note_ru: str | None = None


class PresetsDef(StrictModel):
    storage: str
    shareable: bool


class SegmentRegistry(StrictModel):
    schema_version: Literal[1]
    unit_classes: list[UnitClassDef]
    segments: list[SegmentDef]
    view_modes: list[ViewModeDef]
    presets: PresetsDef

    @model_validator(mode="after")
    def _structure_is_sound(self) -> SegmentRegistry:
        _require_unique((s.code for s in self.segments), "segment code")
        known = {s.code for s in self.segments}
        for s in self.segments:
            if s.parent is not None and s.parent not in known:
                raise ValueError(f"segment {s.code}: unknown parent '{s.parent}'")
        self._reject_cycles()
        return self

    def _reject_cycles(self) -> None:
        parents = {s.code: s.parent for s in self.segments}
        for start in parents:
            seen: set[str] = set()
            node: str | None = start
            while node is not None:
                if node in seen:
                    raise ValueError(f"segment hierarchy contains a cycle at '{start}'")
                seen.add(node)
                node = parents.get(node)

    @property
    def by_code(self) -> dict[str, SegmentDef]:
        return {s.code: s for s in self.segments}

    def may_share_axis(self, a: UnitClass, b: UnitClass) -> bool:
        """The rule that keeps rubles, counts and indices off one axis."""
        if a == b:
            return True
        table = {u.code: set(u.never_share_axis_with) for u in self.unit_classes}
        return b not in table.get(a, set()) and a not in table.get(b, set())


# ---------------------------------------------------------------------------
# index_definition.yaml
# ---------------------------------------------------------------------------


class ComponentInput(StrictModel):
    series: Code
    transform: str
    deflator: Code | None = None
    interpolation_flag: AssertionClass | None = None


class ComponentDef(StrictModel):
    code: Code
    name_ru: str
    description_ru: str
    inputs: list[ComponentInput]
    direction: Literal["positive", "negative", "mixed"]
    min_sources_for_publication: int = Field(ge=1)
    caveats_ru: list[str] = Field(default_factory=list)

    @model_validator(mode="after")
    def _publication_threshold_is_reachable(self) -> ComponentDef:
        if self.min_sources_for_publication > len(self.inputs):
            raise ValueError(
                f"component {self.code}: min_sources_for_publication="
                f"{self.min_sources_for_publication} exceeds the {len(self.inputs)} "
                "declared inputs"
            )
        return self


class BaseWindow(StrictModel):
    start: str
    end: str


class Normalisation(StrictModel):
    method: str
    base_value: float
    base_window: BaseWindow
    rebase_policy: str


class ClipDef(StrictModel):
    min: float
    max: float

    @model_validator(mode="after")
    def _ordered(self) -> ClipDef:
        if self.min >= self.max:
            raise ValueError("clip.min must be < clip.max")
        return self


class IndexHeader(StrictModel):
    code: Code
    name_ru: str
    description_ru: str
    normalisation: Normalisation
    clip: ClipDef


class ReestimationDef(StrictModel):
    schedule: str
    requires_backtest_improvement: bool
    promotion_rule: str


class PersistenceDef(StrictModel):
    store_in: str
    include_loadings: bool
    include_estimation_window: bool


class WeightingDef(StrictModel):
    """Principle 1 & 2 live here: a method, never a table of numbers."""

    method: str
    fallback_method: str
    fallback_condition: str
    reestimation: ReestimationDef
    persistence: PersistenceDef

    @model_validator(mode="after")
    def _no_hand_written_weights(self) -> WeightingDef:
        forbidden = {"weights", "manual_weights", "component_weights"}
        present = forbidden.intersection(self.model_dump().keys())
        if present:
            raise ValueError(f"weighting: hand-written weights are forbidden ({present})")
        return self


class SeasonalityDef(StrictModel):
    method: str
    period_days: int
    secondary_period_days: int
    holiday_regressors_from: Code
    robust: bool


class WowDef(StrictModel):
    window_days: int
    comparison: str


class DeviationDef(StrictModel):
    baseline: str
    baseline_window_years: int


class MomentumDef(StrictModel):
    wow: WowDef
    deviation_from_seasonal_norm: DeviationDef


class ConfidenceFactor(StrictModel):
    code: Code
    description_ru: str
    weight_method: str | None = None


class PublicationGate(StrictModel):
    min_confidence_to_show_headline: float = Field(ge=0.0, le=1.0)
    below_gate_behaviour: str


class ConfidenceDef(StrictModel):
    factors: list[ConfidenceFactor]
    publication_gate: PublicationGate


class CalibrationLabel(StrictModel):
    ru: str
    forbidden_ru: str | None = None
    requires_interval: bool = False


class CalibrationDef(StrictModel):
    """The gate that decides whether the UI may say «продажи»."""

    status: Literal["uncalibrated", "calibrated"]
    required_for_sales_language: list[str]
    labels: dict[Literal["uncalibrated", "calibrated"], CalibrationLabel]

    @property
    def demand_label_ru(self) -> str:
        return self.labels[self.status].ru

    @property
    def sales_language_allowed(self) -> bool:
        return self.status == "calibrated"


class EnsembleMember(StrictModel):
    code: Code
    role: Literal["benchmark", "candidate"]
    must_always_run: bool = False


class CombinationDef(StrictModel):
    method: str
    estimated_on: str


class BacktestDef(StrictModel):
    scheme: str
    initial_train_days: int = Field(ge=30)
    step_days: int = Field(ge=1)
    min_folds: int = Field(ge=1)


class ForecastIntegrityDef(StrictModel):
    feature_publication_lag_enforced: bool
    calendar_features_exempt: bool
    store_every_vintage: bool
    overwrite_allowed: bool

    @model_validator(mode="after")
    def _integrity_is_not_optional(self) -> ForecastIntegrityDef:
        if self.overwrite_allowed:
            raise ValueError("forecast.integrity.overwrite_allowed must be false (principle 7)")
        if not self.feature_publication_lag_enforced:
            raise ValueError(
                "forecast.integrity.feature_publication_lag_enforced must be true (principle 8)"
            )
        if not self.store_every_vintage:
            raise ValueError("forecast.integrity.store_every_vintage must be true (principle 7)")
        return self


class ForecastDef(StrictModel):
    horizons_days: list[int]
    interval_levels: list[float]
    ensemble: list[EnsembleMember]
    combination: CombinationDef
    backtest: BacktestDef
    integrity: ForecastIntegrityDef

    @model_validator(mode="after")
    def _needs_a_benchmark(self) -> ForecastDef:
        benchmarks = [m for m in self.ensemble if m.role == "benchmark"]
        if not benchmarks:
            raise ValueError("forecast.ensemble must contain a benchmark model")
        if not all(m.must_always_run for m in benchmarks):
            raise ValueError("every benchmark must have must_always_run=true")
        if sorted(self.horizons_days) != self.horizons_days or self.horizons_days[0] < 1:
            raise ValueError("forecast.horizons_days must be ascending positive integers")
        if not all(0 < lvl < 1 for lvl in self.interval_levels):
            raise ValueError("forecast.interval_levels must be strictly between 0 and 1")
        return self


class IndexDefinition(StrictModel):
    schema_version: Literal[1]
    index: IndexHeader
    components: list[ComponentDef]
    weighting: WeightingDef
    seasonality: SeasonalityDef
    momentum: MomentumDef
    confidence: ConfidenceDef
    calibration: CalibrationDef
    forecast: ForecastDef

    @model_validator(mode="after")
    def _component_codes_unique(self) -> IndexDefinition:
        _require_unique((c.code for c in self.components), "component code")
        return self


# ---------------------------------------------------------------------------
# venues.yaml
# ---------------------------------------------------------------------------


class VenueCategoryDef(StrictModel):
    code: VenueCategory
    letter: str = Field(min_length=1, max_length=1)
    name_ru: str


class VenueDef(StrictModel):
    code: Code
    canonical_name: str
    canonical_name_ru: str
    category: VenueCategory
    website_url: HttpUrl | None = None
    url_verified: bool = False
    ticket_providers: list[str] = Field(default_factory=list)
    relationship: Literal["panel", "first_party"] = "panel"
    source_access_policy: AccessPolicy
    robots_allows_crawl: bool | None = None
    access_reviewed_at: str | None = None
    capacity: int | None = Field(default=None, ge=1, le=200_000)
    capacity_source_url: HttpUrl | None = None
    enabled: bool = False
    notes_ru: str | None = None

    @model_validator(mode="after")
    def _capacity_only_if_sourced(self) -> VenueDef:
        if self.capacity is not None and self.capacity_source_url is None:
            raise ValueError(
                f"venue {self.code}: capacity is set without capacity_source_url. "
                "Capacity is a sourced fact or it is absent."
            )
        return self

    @model_validator(mode="after")
    def _enable_requires_review(self) -> VenueDef:
        if not self.enabled:
            return self
        if self.relationship == "first_party":
            return self
        if not self.url_verified:
            raise ValueError(f"venue {self.code}: enabled=true but url_verified=false")
        if self.robots_allows_crawl is not True:
            raise ValueError(f"venue {self.code}: enabled=true but robots_allows_crawl is not true")
        if not self.access_reviewed_at:
            raise ValueError(f"venue {self.code}: enabled=true but access_reviewed_at is empty")
        return self


class ReviewWorkflow(StrictModel):
    steps: list[str]
    recorded_in: str
    command: str


class VenueRegistry(StrictModel):
    schema_version: Literal[1]
    city: str
    default_geography: str
    categories: list[VenueCategoryDef]
    venues: list[VenueDef]
    review_workflow: ReviewWorkflow

    @model_validator(mode="after")
    def _codes_unique(self) -> VenueRegistry:
        _require_unique((v.code for v in self.venues), "venue code")
        _require_unique((c.code for c in self.categories), "venue category")
        return self

    @property
    def panel(self) -> list[VenueDef]:
        return [v for v in self.venues if v.relationship == "panel"]


# ---------------------------------------------------------------------------
# agents.yaml
# ---------------------------------------------------------------------------


class RetryPolicy(StrictModel):
    max_attempts: int = Field(ge=1, le=10)
    backoff: Literal["exponential", "linear", "constant"]
    initial_delay_seconds: float = Field(gt=0)
    max_delay_seconds: float = Field(gt=0)
    retry_on: list[str]
    no_retry_on: list[str]
    on_final_failure: str

    @model_validator(mode="after")
    def _no_overlap(self) -> RetryPolicy:
        overlap = set(self.retry_on) & set(self.no_retry_on)
        if overlap:
            raise ValueError(f"retry policy: {overlap} appear in both retry_on and no_retry_on")
        if self.max_delay_seconds < self.initial_delay_seconds:
            raise ValueError("retry policy: max_delay_seconds < initial_delay_seconds")
        return self


class TimeoutPolicy(StrictModel):
    soft_seconds: float = Field(gt=0)
    hard_seconds: float = Field(gt=0)

    @model_validator(mode="after")
    def _ordered(self) -> TimeoutPolicy:
        if self.hard_seconds < self.soft_seconds:
            raise ValueError("hard_seconds must be >= soft_seconds")
        return self


class OutputContract(StrictModel):
    require_assertion_class: bool
    require_provenance: bool
    reject_unsourced_claims: bool
    max_output_tokens: int = Field(ge=256)


class AgentDefaults(StrictModel):
    retry: RetryPolicy
    timeouts: TimeoutPolicy
    output_contract: OutputContract
    guardrails: list[str] = Field(default_factory=list)


class ProvenanceRequirements(StrictModel):
    required_fields: list[str] = Field(default_factory=list)
    forced_assertion_class_max: AssertionClass | None = None


class AgentDef(StrictModel):
    code: Code
    name_ru: str
    prompt: str
    role: str
    allowed_tools: list[str]
    forbidden_tools: list[str] = Field(default_factory=list)
    sources: list[Code] = Field(default_factory=list)
    input_schema: str | None = None
    output_schema: str | None = None
    provenance_requirements: ProvenanceRequirements | Literal["inherits_from_children"] | None = (
        None
    )
    guardrails: list[str] = Field(default_factory=list)
    blocking: bool = False
    language: str | None = None
    checklist: list[str] = Field(default_factory=list)
    evals: list[str] = Field(default_factory=list)

    #: No agent may ever write to the model layer. This is belt to the DB's braces.
    MODEL_WRITE_TOOLS: frozenset[str] = frozenset({"db.write_model", "quant.write"})

    @model_validator(mode="after")
    def _agents_never_write_the_index(self) -> AgentDef:
        """Principle 1: LLMs do not invent the numerical barometer."""
        illegal = set(self.allowed_tools) & set(self.MODEL_WRITE_TOOLS)
        if illegal:
            raise ValueError(
                f"agent {self.code}: allowed_tools contains {illegal}. "
                "No agent may write to the model layer (non-negotiable principle 1)."
            )
        both = set(self.allowed_tools) & set(self.forbidden_tools)
        if both:
            raise ValueError(f"agent {self.code}: {both} are both allowed and forbidden")
        if not self.evals:
            raise ValueError(f"agent {self.code}: at least one eval is required")
        return self


class ProviderDef(StrictModel):
    adapter: str
    model_env_var: str
    api_key_env_var: str
    supports: list[str]


class ProvidersDef(StrictModel):
    default: str
    anthropic: ProviderDef
    openai: ProviderDef

    @model_validator(mode="after")
    def _default_exists(self) -> ProvidersDef:
        if self.default not in {"anthropic", "openai"}:
            raise ValueError(f"providers.default '{self.default}' is not a configured provider")
        return self


class StageDef(StrictModel):
    name: str
    parallel: list[Code] = Field(default_factory=list)
    serial: list[Code] = Field(default_factory=list)
    runner: str | None = None
    failure_policy: str

    @model_validator(mode="after")
    def _stage_has_work(self) -> StageDef:
        if not (self.parallel or self.serial or self.runner):
            raise ValueError(f"stage {self.name}: declares no agents and no runner")
        return self

    @property
    def agent_codes(self) -> list[str]:
        return [*self.parallel, *self.serial]


class OrchestrationDef(StrictModel):
    pipeline: str
    stages: list[StageDef]


class AgentRegistry(StrictModel):
    schema_version: Literal[1]
    providers: ProvidersDef
    defaults: AgentDefaults
    agents: list[AgentDef]
    orchestration: OrchestrationDef

    @model_validator(mode="after")
    def _registry_is_coherent(self) -> AgentRegistry:
        _require_unique((a.code for a in self.agents), "agent code")
        known = {a.code for a in self.agents}
        for stage in self.orchestration.stages:
            unknown = set(stage.agent_codes) - known
            if unknown:
                raise ValueError(f"orchestration stage '{stage.name}': unknown agents {unknown}")
        scheduled = {c for st in self.orchestration.stages for c in st.agent_codes}
        orphans = known - scheduled - {"chief_analyst"}
        if orphans:
            raise ValueError(f"agents declared but never scheduled: {sorted(orphans)}")
        return self

    @property
    def by_code(self) -> dict[str, AgentDef]:
        return {a.code: a for a in self.agents}


# ---------------------------------------------------------------------------
# Cross-file validation
# ---------------------------------------------------------------------------


class Registries(BaseModel):
    """All four registries, validated together.

    Individually valid files can still be jointly wrong: an index component that
    references a series nobody produces, a source pointing at a segment that does
    not exist. Those errors surface here, at load time, not at 03:00 in a pipeline.
    """

    model_config = ConfigDict(frozen=True)

    sources: SourceRegistry
    segments: SegmentRegistry
    index: IndexDefinition
    venues: VenueRegistry
    agents: AgentRegistry

    @model_validator(mode="after")
    def _cross_references_resolve(self) -> Registries:
        problems: list[str] = []

        segment_codes = set(self.segments.by_code)
        for src in self.sources.sources:
            for ser in src.series:
                if ser.segment not in segment_codes:
                    problems.append(
                        f"series {ser.code} (source {src.code}) references unknown "
                        f"segment '{ser.segment}'"
                    )

        series_codes = set(self.sources.all_series)
        for comp in self.index.components:
            for inp in comp.inputs:
                if inp.series not in series_codes:
                    problems.append(
                        f"index component {comp.code} references undeclared series '{inp.series}'"
                    )
                if inp.deflator and inp.deflator not in series_codes:
                    problems.append(
                        f"index component {comp.code} references undeclared deflator "
                        f"'{inp.deflator}'"
                    )
        if self.index.seasonality.holiday_regressors_from not in series_codes:
            problems.append(
                "index.seasonality.holiday_regressors_from references undeclared series "
                f"'{self.index.seasonality.holiday_regressors_from}'"
            )

        source_codes = set(self.sources.by_code)
        for agent in self.agents.agents:
            unknown = set(agent.sources) - source_codes
            if unknown:
                problems.append(f"agent {agent.code} references unknown sources {sorted(unknown)}")

        venue_categories = {c.code for c in self.venues.categories}
        for seg in self.segments.segments:
            if seg.venue_category and seg.venue_category not in venue_categories:
                problems.append(
                    f"segment {seg.code} references unknown venue category '{seg.venue_category}'"
                )

        # Principle 5, restated across files: anything flowing into the
        # competition_pressure component must come from a proxy-aware source.
        for comp in self.index.components:
            if comp.code != "competition_pressure":
                continue
            for inp in comp.inputs:
                if inp.series not in series_codes:
                    continue
                src = self.sources.source_of_series(inp.series)
                if not src.is_proxy:
                    problems.append(
                        f"competition_pressure input '{inp.series}' comes from "
                        f"non-proxy source {src.code}; verify it is not being read as sales"
                    )

        if problems:
            raise ValueError(
                "registry cross-validation failed:\n  - " + "\n  - ".join(sorted(problems))
            )
        return self

    def publishable_label_for_demand(self) -> str:
        """What the UI is allowed to call the demand forecast today."""
        return self.index.calibration.demand_label_ru


def _require_unique(values: Any, what: str) -> None:
    seen: set[str] = set()
    dupes: set[str] = set()
    for v in values:
        if v in seen:
            dupes.add(v)
        seen.add(v)
    if dupes:
        raise ValueError(f"duplicate {what}: {sorted(dupes)}")


def _unused(_: ValidationInfo) -> None:  # pragma: no cover - import anchor
    return None
