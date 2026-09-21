"""All ORM models. Importing this module registers every table on Base.metadata."""

from barometer.db.models.features import FeatureDefinition, FeatureValue
from barometer.db.models.model_output import (
    Backtest,
    Forecast,
    ForecastEvaluation,
    IndexValue,
    ModelRun,
)
from barometer.db.models.normalized import (
    EventDiff,
    EventObservation,
    InformationAttention,
    Observation,
)
from barometer.db.models.ops import (
    AgentInvocation,
    DataQualityCheck,
    PipelineRun,
    SchemaFingerprint,
    SourceHealth,
)
from barometer.db.models.raw import Snapshot, SnapshotParseError
from barometer.db.models.registry import Segment, Series, Source, Venue

__all__ = [
    "AgentInvocation",
    "Backtest",
    "DataQualityCheck",
    "EventDiff",
    "EventObservation",
    "FeatureDefinition",
    "FeatureValue",
    "Forecast",
    "ForecastEvaluation",
    "IndexValue",
    "InformationAttention",
    "ModelRun",
    "Observation",
    "PipelineRun",
    "SchemaFingerprint",
    "Segment",
    "Series",
    "Snapshot",
    "SnapshotParseError",
    "Source",
    "SourceHealth",
    "Venue",
]
