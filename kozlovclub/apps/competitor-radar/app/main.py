from __future__ import annotations

import asyncio
import json
from datetime import date, timedelta
from pathlib import Path
from threading import Lock
from typing import Any

from apscheduler.schedulers.background import BackgroundScheduler
from apscheduler.triggers.cron import CronTrigger
from fastapi import FastAPI, HTTPException, Query, Request
from fastapi.responses import FileResponse, HTMLResponse, PlainTextResponse, Response
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel, Field

from .analyzer import build_report, report_to_csv, report_to_markdown, save_markdown_report
from .config import ROOT, load_sources, settings
from .db import create_run, finish_run, init_db, insert_items, latest_run, list_runs, query_items
from .scraper import CompetitorScraper

app = FastAPI(title="Обзор конкурентов", version="1.0.0")
app.mount("/static", StaticFiles(directory=ROOT / "app" / "static"), name="static")
templates = Jinja2Templates(directory=ROOT / "app" / "templates")

scheduler: BackgroundScheduler | None = None
run_lock = Lock()


class RunRequest(BaseModel):
    date_from: date
    date_to: date
    competitors: list[str] = Field(default_factory=list)
    include_news: bool = True


class ReportQuery(BaseModel):
    date_from: date
    date_to: date
    competitors: list[str] = Field(default_factory=list)


def _competitor_names() -> list[str]:
    return [row["name"] for row in load_sources()["competitors"]]


def _validate_period(date_from: date, date_to: date) -> None:
    if date_from > date_to:
        raise HTTPException(400, "Дата начала не может быть позже даты окончания")
    if (date_to - date_from).days > 370:
        raise HTTPException(400, "За один запуск можно собрать не более 370 дней")


async def collect_period(
    date_from: date,
    date_to: date,
    competitors: list[str] | None = None,
    include_news: bool = True,
) -> dict[str, Any]:
    _validate_period(date_from, date_to)
    valid = set(_competitor_names())
    selected = [c for c in (competitors or []) if c in valid]
    if competitors and len(selected) != len(competitors):
        unknown = sorted(set(competitors) - valid)
        raise HTTPException(400, f"Неизвестные конкуренты: {', '.join(unknown)}")

    if not run_lock.acquire(blocking=False):
        raise HTTPException(409, "Сбор уже выполняется. Дождитесь его завершения.")

    run_id = create_run(date_from, date_to)
    try:
        scraper = CompetitorScraper()
        collected, source_results = await scraper.collect(
            date_from,
            date_to,
            selected_competitors=selected or None,
            include_news=include_news,
        )
        inserted = insert_items(run_id, collected)
        report_competitors = (selected or _competitor_names()) + ["Клуб Алексея Козлова"]
        items = query_items(date_from, date_to, report_competitors, limit=10000)
        report = build_report(items, date_from, date_to)
        path = save_markdown_report(report)
        stats = {
            "collected": len(collected),
            "inserted_or_updated": inserted,
            "sources": [result.__dict__ for result in source_results],
        }
        finish_run(run_id, "ok", stats, str(path.relative_to(ROOT)))
        return {"run_id": run_id, "report": report, "stats": stats}
    except HTTPException:
        finish_run(run_id, "error", {}, message="Ошибка параметров")
        raise
    except Exception as exc:
        finish_run(run_id, "error", {}, message=str(exc)[:1000])
        raise HTTPException(500, f"Сбор завершился ошибкой: {exc}") from exc
    finally:
        run_lock.release()


def scheduled_weekly_run() -> None:
    today = date.today()
    date_to = today - timedelta(days=1)
    date_from = date_to - timedelta(days=6)
    try:
        asyncio.run(collect_period(date_from, date_to, None, True))
    except Exception as exc:
        print(f"[scheduler] weekly run failed: {exc}")


@app.on_event("startup")
def startup() -> None:
    global scheduler
    init_db()
    if settings.scheduler_enabled:
        scheduler = BackgroundScheduler(timezone=settings.timezone)
        scheduler.add_job(
            scheduled_weekly_run,
            CronTrigger(
                day_of_week=settings.weekly_day,
                hour=settings.weekly_hour,
                minute=settings.weekly_minute,
                timezone=settings.timezone,
            ),
            id="weekly_competitor_review",
            replace_existing=True,
            max_instances=1,
            coalesce=True,
        )
        scheduler.start()


@app.on_event("shutdown")
def shutdown() -> None:
    if scheduler:
        scheduler.shutdown(wait=False)


@app.get("/", response_class=HTMLResponse)
def index(request: Request):
    today = date.today()
    default_to = today
    default_from = today - timedelta(days=7)
    return templates.TemplateResponse(
        request=request,
        name="index.html",
        context={
            "competitors": _competitor_names(),
            "default_from": default_from.isoformat(),
            "default_to": default_to.isoformat(),
            "weekly_schedule": f"{settings.weekly_day}, {settings.weekly_hour:02d}:{settings.weekly_minute:02d} ({settings.timezone})",
        },
    )


@app.get("/health")
def health() -> dict[str, Any]:
    return {"status": "ok", "latest_run": latest_run()}


@app.post("/api/run")
async def run_collection(payload: RunRequest) -> dict[str, Any]:
    return await collect_period(
        payload.date_from,
        payload.date_to,
        payload.competitors,
        payload.include_news,
    )


@app.get("/api/report")
def get_report(
    date_from: date = Query(...),
    date_to: date = Query(...),
    competitors: str = Query(""),
) -> dict[str, Any]:
    _validate_period(date_from, date_to)
    selected = [x for x in competitors.split("|") if x]
    names = selected or _competitor_names()
    items = query_items(date_from, date_to, names + ["Клуб Алексея Козлова"], limit=10000)
    return build_report(items, date_from, date_to)


@app.get("/api/runs")
def runs() -> list[dict[str, Any]]:
    return list_runs(30)


@app.get("/api/sources")
def sources() -> dict[str, Any]:
    config = load_sources()
    return {
        "competitors": config["competitors"],
        "sources": config["sources"],
        "scheduler": {
            "enabled": settings.scheduler_enabled,
            "timezone": settings.timezone,
            "day": settings.weekly_day,
            "hour": settings.weekly_hour,
            "minute": settings.weekly_minute,
        },
    }


@app.get("/api/export.md")
def export_markdown(
    date_from: date = Query(...),
    date_to: date = Query(...),
    competitors: str = Query(""),
) -> PlainTextResponse:
    selected = [x for x in competitors.split("|") if x]
    items = query_items(date_from, date_to, (selected or _competitor_names()) + ["Клуб Алексея Козлова"], limit=10000)
    report = build_report(items, date_from, date_to)
    filename = f"competitor-review_{date_from}_{date_to}.md"
    return PlainTextResponse(
        report_to_markdown(report),
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )


@app.get("/api/export.csv")
def export_csv(
    date_from: date = Query(...),
    date_to: date = Query(...),
    competitors: str = Query(""),
) -> Response:
    selected = [x for x in competitors.split("|") if x]
    items = query_items(date_from, date_to, (selected or _competitor_names()) + ["Клуб Алексея Козлова"], limit=10000)
    report = build_report(items, date_from, date_to)
    filename = f"competitor-review_{date_from}_{date_to}.csv"
    content = "\ufeff" + report_to_csv(report)
    return Response(
        content=content,
        media_type="text/csv; charset=utf-8",
        headers={"Content-Disposition": f'attachment; filename="{filename}"'},
    )


@app.get("/reports/{filename}")
def report_file(filename: str) -> FileResponse:
    safe = Path(filename).name
    path = settings.report_dir / safe
    if not path.exists() or path.suffix not in {".md", ".json"}:
        raise HTTPException(404, "Отчёт не найден")
    return FileResponse(path)
