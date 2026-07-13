from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import datetime
from typing import Any


@dataclass
class CollectedItem:
    competitor: str
    source_name: str
    source_type: str
    source_url: str
    item_url: str
    title: str
    description: str = ""
    starts_at: datetime | None = None
    published_at: datetime | None = None
    price_min: int | None = None
    venue: str = ""
    item_type: str = "event"
    categories: list[str] = field(default_factory=list)
    is_import: bool = False
    raw_hash: str = ""
    collected_at: datetime = field(default_factory=datetime.utcnow)

    def to_dict(self) -> dict[str, Any]:
        result = asdict(self)
        for key in ("starts_at", "published_at", "collected_at"):
            if result[key] is not None:
                result[key] = result[key].isoformat()
        return result
