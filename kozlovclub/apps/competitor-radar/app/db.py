from __future__ import annotations

import json
import sqlite3
from contextlib import contextmanager
from datetime import date, datetime
from pathlib import Path
from typing import Any, Iterable

from .config import settings
from .models import CollectedItem


SCHEMA = """
PRAGMA journal_mode=WAL;
CREATE TABLE IF NOT EXISTS runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    date_from TEXT NOT NULL,
    date_to TEXT NOT NULL,
    status TEXT NOT NULL,
    message TEXT DEFAULT '',
    source_stats_json TEXT DEFAULT '{}',
    report_path TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    competitor TEXT NOT NULL,
    source_name TEXT NOT NULL,
    source_type TEXT NOT NULL,
    source_url TEXT NOT NULL,
    item_url TEXT DEFAULT '',
    title TEXT NOT NULL,
    description TEXT DEFAULT '',
    starts_at TEXT,
    published_at TEXT,
    price_min INTEGER,
    venue TEXT DEFAULT '',
    item_type TEXT NOT NULL,
    categories_json TEXT DEFAULT '[]',
    is_import INTEGER DEFAULT 0,
    raw_hash TEXT NOT NULL UNIQUE,
    collected_at TEXT NOT NULL,
    run_id INTEGER,
    FOREIGN KEY(run_id) REFERENCES runs(id)
);
CREATE INDEX IF NOT EXISTS idx_items_competitor ON items(competitor);
CREATE INDEX IF NOT EXISTS idx_items_starts_at ON items(starts_at);
CREATE INDEX IF NOT EXISTS idx_items_published_at ON items(published_at);
CREATE INDEX IF NOT EXISTS idx_items_type ON items(item_type);
"""


@contextmanager
def connect(path: Path | None = None):
    db_path = path or settings.database_path
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
        conn.commit()
    finally:
        conn.close()


def init_db() -> None:
    with connect() as conn:
        conn.executescript(SCHEMA)


def create_run(date_from: date, date_to: date) -> int:
    with connect() as conn:
        cur = conn.execute(
            "INSERT INTO runs(started_at,date_from,date_to,status) VALUES(?,?,?,?)",
            (datetime.utcnow().isoformat(), date_from.isoformat(), date_to.isoformat(), "running"),
        )
        return int(cur.lastrowid)


def finish_run(
    run_id: int,
    status: str,
    source_stats: dict[str, Any],
    report_path: str = "",
    message: str = "",
) -> None:
    with connect() as conn:
        conn.execute(
            """UPDATE runs SET finished_at=?,status=?,message=?,source_stats_json=?,report_path=?
               WHERE id=?""",
            (
                datetime.utcnow().isoformat(),
                status,
                message,
                json.dumps(source_stats, ensure_ascii=False),
                report_path,
                run_id,
            ),
        )


def insert_items(run_id: int, items: Iterable[CollectedItem]) -> int:
    inserted = 0
    with connect() as conn:
        for item in items:
            try:
                conn.execute(
                    """INSERT INTO items(
                        competitor,source_name,source_type,source_url,item_url,title,description,
                        starts_at,published_at,price_min,venue,item_type,categories_json,is_import,
                        raw_hash,collected_at,run_id
                    ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON CONFLICT(raw_hash) DO UPDATE SET
                        item_url=excluded.item_url, description=excluded.description,
                        price_min=COALESCE(excluded.price_min, items.price_min),
                        categories_json=excluded.categories_json, is_import=excluded.is_import,
                        collected_at=excluded.collected_at, run_id=excluded.run_id""",
                    (
                        item.competitor,
                        item.source_name,
                        item.source_type,
                        item.source_url,
                        item.item_url,
                        item.title,
                        item.description,
                        item.starts_at.isoformat() if item.starts_at else None,
                        item.published_at.isoformat() if item.published_at else None,
                        item.price_min,
                        item.venue,
                        item.item_type,
                        json.dumps(item.categories, ensure_ascii=False),
                        int(item.is_import),
                        item.raw_hash,
                        item.collected_at.isoformat(),
                        run_id,
                    ),
                )
                inserted += 1
            except sqlite3.IntegrityError:
                continue
    return inserted


def _row_to_dict(row: sqlite3.Row) -> dict[str, Any]:
    result = dict(row)
    if "categories_json" in result:
        result["categories"] = json.loads(result.pop("categories_json") or "[]")
    if "source_stats_json" in result:
        result["source_stats"] = json.loads(result.pop("source_stats_json") or "{}")
    if "is_import" in result:
        result["is_import"] = bool(result["is_import"])
    return result


def query_items(
    date_from: date,
    date_to: date,
    competitors: list[str] | None = None,
    item_type: str | None = None,
    limit: int = 2000,
) -> list[dict[str, Any]]:
    clauses = [
        "((starts_at IS NOT NULL AND date(starts_at) BETWEEN ? AND ?) OR "
        " (published_at IS NOT NULL AND date(published_at) BETWEEN ? AND ?))"
    ]
    params: list[Any] = [date_from.isoformat(), date_to.isoformat(), date_from.isoformat(), date_to.isoformat()]
    if competitors:
        placeholders = ",".join("?" for _ in competitors)
        clauses.append(f"competitor IN ({placeholders})")
        params.extend(competitors)
    if item_type:
        clauses.append("item_type=?")
        params.append(item_type)
    params.append(limit)
    sql = f"""SELECT * FROM items WHERE {' AND '.join(clauses)}
              ORDER BY COALESCE(starts_at,published_at) ASC LIMIT ?"""
    with connect() as conn:
        return [_row_to_dict(row) for row in conn.execute(sql, params).fetchall()]


def latest_run() -> dict[str, Any] | None:
    with connect() as conn:
        row = conn.execute("SELECT * FROM runs ORDER BY id DESC LIMIT 1").fetchone()
        return _row_to_dict(row) if row else None


def list_runs(limit: int = 20) -> list[dict[str, Any]]:
    with connect() as conn:
        rows = conn.execute("SELECT * FROM runs ORDER BY id DESC LIMIT ?", (limit,)).fetchall()
        return [_row_to_dict(row) for row in rows]
