from __future__ import annotations

import asyncio
from datetime import date, timedelta

from app.main import collect_period


if __name__ == "__main__":
    date_to = date.today() - timedelta(days=1)
    date_from = date_to - timedelta(days=6)
    result = asyncio.run(collect_period(date_from, date_to, None, True))
    print(result["stats"])
