from __future__ import annotations

import csv
import io
import json
import sqlite3
from contextlib import contextmanager
from datetime import date, datetime
from pathlib import Path
from typing import Any, Iterable

from .config import load_alias_seed, settings
from .models import CollectedItem

SCHEMA = """
PRAGMA journal_mode=WAL;
PRAGMA foreign_keys=ON;
CREATE TABLE IF NOT EXISTS runs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, started_at TEXT NOT NULL, finished_at TEXT,
 date_from TEXT NOT NULL, date_to TEXT NOT NULL, status TEXT NOT NULL,
 message TEXT DEFAULT '', source_stats_json TEXT DEFAULT '{}', report_path TEXT DEFAULT ''
);
CREATE TABLE IF NOT EXISTS items (
 id INTEGER PRIMARY KEY AUTOINCREMENT, competitor TEXT NOT NULL, source_name TEXT NOT NULL,
 source_type TEXT NOT NULL, source_url TEXT NOT NULL, item_url TEXT DEFAULT '', title TEXT NOT NULL,
 description TEXT DEFAULT '', starts_at TEXT, published_at TEXT, price_min INTEGER, venue TEXT DEFAULT '',
 stage TEXT DEFAULT '', item_type TEXT NOT NULL, categories_json TEXT DEFAULT '[]', genres_json TEXT DEFAULT '[]',
 artists_json TEXT DEFAULT '[]', program_entities_json TEXT DEFAULT '[]', is_import INTEGER DEFAULT 0,
 is_free INTEGER DEFAULT 0, confidence REAL DEFAULT 0.5, parser_name TEXT DEFAULT '', raw_hash TEXT NOT NULL UNIQUE,
 collected_at TEXT NOT NULL, first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, run_id INTEGER,
 FOREIGN KEY(run_id) REFERENCES runs(id)
);
CREATE INDEX IF NOT EXISTS idx_items_competitor ON items(competitor);
CREATE INDEX IF NOT EXISTS idx_items_starts_at ON items(starts_at);
CREATE INDEX IF NOT EXISTS idx_items_published_at ON items(published_at);
CREATE INDEX IF NOT EXISTS idx_items_type ON items(item_type);
CREATE TABLE IF NOT EXISTS artist_aliases (
 id INTEGER PRIMARY KEY AUTOINCREMENT, canonical TEXT NOT NULL, alias TEXT NOT NULL UNIQUE,
 created_at TEXT NOT NULL, source TEXT DEFAULT 'manual'
);
CREATE INDEX IF NOT EXISTS idx_alias_canonical ON artist_aliases(canonical);
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
        now = datetime.utcnow().isoformat()
        for canonical, variants in load_alias_seed().items():
            for alias in [canonical, *variants]:
                conn.execute(
                    "INSERT OR IGNORE INTO artist_aliases(canonical,alias,created_at,source) VALUES(?,?,?,?)",
                    (canonical, alias, now, "seed"),
                )


def create_run(date_from: date, date_to: date) -> int:
    with connect() as conn:
        cur = conn.execute(
            "INSERT INTO runs(started_at,date_from,date_to,status) VALUES(?,?,?,?)",
            (datetime.utcnow().isoformat(), date_from.isoformat(), date_to.isoformat(), "running"),
        )
        return int(cur.lastrowid)


def finish_run(run_id: int, status: str, source_stats: dict[str, Any], report_path: str = "", message: str = "") -> None:
    with connect() as conn:
        conn.execute(
            "UPDATE runs SET finished_at=?,status=?,message=?,source_stats_json=?,report_path=? WHERE id=?",
            (datetime.utcnow().isoformat(), status, message, json.dumps(source_stats, ensure_ascii=False), report_path, run_id),
        )


def insert_items(run_id: int, items: Iterable[CollectedItem]) -> int:
    count = 0
    now = datetime.utcnow().isoformat()
    with connect() as conn:
        for item in items:
            conn.execute(
                """INSERT INTO items(
                competitor,source_name,source_type,source_url,item_url,title,description,starts_at,published_at,
                price_min,venue,stage,item_type,categories_json,genres_json,artists_json,program_entities_json,
                is_import,is_free,confidence,parser_name,raw_hash,collected_at,first_seen_at,last_seen_at,run_id
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(raw_hash) DO UPDATE SET
                item_url=excluded.item_url,description=excluded.description,price_min=COALESCE(excluded.price_min,items.price_min),
                venue=COALESCE(NULLIF(excluded.venue,''),items.venue),stage=COALESCE(NULLIF(excluded.stage,''),items.stage),
                categories_json=excluded.categories_json,genres_json=excluded.genres_json,artists_json=excluded.artists_json,
                program_entities_json=excluded.program_entities_json,is_import=excluded.is_import,is_free=excluded.is_free,
                confidence=MAX(excluded.confidence,items.confidence),parser_name=excluded.parser_name,
                collected_at=excluded.collected_at,last_seen_at=excluded.last_seen_at,run_id=excluded.run_id""",
                (
                    item.competitor,item.source_name,item.source_type,item.source_url,item.item_url,item.title,item.description,
                    item.starts_at.isoformat() if item.starts_at else None,
                    item.published_at.isoformat() if item.published_at else None,item.price_min,item.venue,item.stage,item.item_type,
                    json.dumps(item.categories,ensure_ascii=False),json.dumps(item.genres,ensure_ascii=False),
                    json.dumps(item.artists,ensure_ascii=False),json.dumps(item.program_entities,ensure_ascii=False),
                    int(item.is_import),int(item.is_free),float(item.confidence),item.parser_name,item.raw_hash,
                    item.collected_at.isoformat(),now,now,run_id,
                ),
            )
            count += 1
    return count


def _row_to_dict(row: sqlite3.Row) -> dict[str, Any]:
    result = dict(row)
    for raw, clean in (("categories_json","categories"),("genres_json","genres"),("artists_json","artists"),("program_entities_json","program_entities"),("source_stats_json","source_stats")):
        if raw in result:
            try: result[clean] = json.loads(result.pop(raw) or ("{}" if clean == "source_stats" else "[]"))
            except json.JSONDecodeError: result[clean] = {} if clean == "source_stats" else []
    for key in ("is_import","is_free"):
        if key in result: result[key] = bool(result[key])
    return result


def query_items(date_from: date, date_to: date, competitors: list[str] | None = None, item_type: str | None = None, limit: int = 30000) -> list[dict[str, Any]]:
    clauses = ["((starts_at IS NOT NULL AND date(starts_at) BETWEEN ? AND ?) OR (published_at IS NOT NULL AND date(published_at) BETWEEN ? AND ?))"]
    params: list[Any] = [date_from.isoformat(),date_to.isoformat(),date_from.isoformat(),date_to.isoformat()]
    if competitors:
        clauses.append(f"competitor IN ({','.join('?' for _ in competitors)})")
        params.extend(competitors)
    if item_type:
        clauses.append("item_type=?"); params.append(item_type)
    params.append(limit)
    with connect() as conn:
        rows = conn.execute(f"SELECT * FROM items WHERE {' AND '.join(clauses)} ORDER BY COALESCE(starts_at,published_at),competitor LIMIT ?",params).fetchall()
    return [_row_to_dict(row) for row in rows]


def list_aliases() -> list[dict[str, Any]]:
    with connect() as conn:
        rows = conn.execute("SELECT id,canonical,alias,source FROM artist_aliases ORDER BY canonical,alias").fetchall()
    return [dict(r) for r in rows]


def add_alias(canonical: str, alias: str, source: str = "manual") -> dict[str, Any]:
    canonical, alias = canonical.strip(), alias.strip()
    if not canonical or not alias: raise ValueError("Пустое имя артиста")
    with connect() as conn:
        conn.execute("INSERT INTO artist_aliases(canonical,alias,created_at,source) VALUES(?,?,?,?) ON CONFLICT(alias) DO UPDATE SET canonical=excluded.canonical,source=excluded.source",(canonical,alias,datetime.utcnow().isoformat(),source))
        row = conn.execute("SELECT id,canonical,alias,source FROM artist_aliases WHERE alias=?",(alias,)).fetchone()
    return dict(row)


def delete_alias(alias_id: int) -> None:
    with connect() as conn: conn.execute("DELETE FROM artist_aliases WHERE id=? AND source!='seed'",(alias_id,))


def import_kozlov_csv(run_id: int, content: bytes, source_name: str = "Внутреннее расписание Клуба Козлова") -> dict[str, Any]:
    text = content.decode("utf-8-sig", errors="replace")
    dialect = csv.Sniffer().sniff(text[:3000], delimiters=",;\t") if text.strip() else csv.excel
    reader = csv.DictReader(io.StringIO(text), dialect=dialect)
    from .artists import extract_artists
    from .classification import classify, extract_price
    from .models import CollectedItem
    import hashlib
    accepted: list[CollectedItem] = []; errors: list[str] = []
    for n,row in enumerate(reader,2):
        title = (row.get("title") or row.get("artist") or row.get("Артист") or row.get("Название") or "").strip()
        raw_date = (row.get("date") or row.get("Дата") or row.get("starts_at") or "").strip()
        raw_time = (row.get("time") or row.get("Время") or "20:00").strip()
        if not title or not raw_date: errors.append(f"Строка {n}: нет названия или даты"); continue
        parsed = None
        for fmt in ("%Y-%m-%d %H:%M","%d.%m.%Y %H:%M","%Y-%m-%d","%d.%m.%Y"):
            try: parsed = datetime.strptime(f"{raw_date} {raw_time}".strip(),fmt); break
            except ValueError: pass
        if not parsed:
            try: parsed = datetime.fromisoformat(raw_date)
            except ValueError: errors.append(f"Строка {n}: неверная дата {raw_date}"); continue
        artists,_ = extract_artists(title, row.get("description") or "")
        cats,is_import,genres = classify(f"{title} {row.get('description') or ''}")
        raw_hash = hashlib.sha256(f"internal|{parsed.isoformat()}|{title}".encode()).hexdigest()
        accepted.append(CollectedItem(
            competitor="Клуб Алексея Козлова",source_name=source_name,source_type="internal",source_url="internal://kozlov-calendar",
            item_url="",title=title,description=row.get("description") or "",starts_at=parsed,price_min=extract_price(row.get("price") or ""),
            venue="Клуб Алексея Козлова",stage=(row.get("stage") or row.get("Сцена") or "").strip(),item_type="event",
            categories=cats,genres=genres,artists=artists,is_import=is_import,confidence=1.0,parser_name="csv_import",raw_hash=raw_hash,
        ))
    insert_items(run_id, accepted)
    return {"imported":len(accepted),"errors":errors[:50]}


def latest_run() -> dict[str, Any] | None:
    with connect() as conn: row=conn.execute("SELECT * FROM runs ORDER BY id DESC LIMIT 1").fetchone()
    return _row_to_dict(row) if row else None


def list_runs(limit: int = 20) -> list[dict[str, Any]]:
    with connect() as conn: rows=conn.execute("SELECT * FROM runs ORDER BY id DESC LIMIT ?",(limit,)).fetchall()
    return [_row_to_dict(r) for r in rows]
