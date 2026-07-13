from __future__ import annotations
import asyncio
from datetime import date,timedelta
from pathlib import Path
from threading import Lock
from typing import Any

from apscheduler.schedulers.background import BackgroundScheduler
from apscheduler.triggers.cron import CronTrigger
from fastapi import FastAPI,File,Form,HTTPException,Query,Request,UploadFile
from fastapi.responses import FileResponse,HTMLResponse,PlainTextResponse,Response
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel,Field

from .analyzer import build_report,report_to_csv,report_to_events_csv,report_to_markdown,save_markdown_report
from .config import ROOT,load_sources,settings
from .db import add_alias,create_run,delete_alias,finish_run,import_kozlov_csv,init_db,insert_items,latest_run,list_aliases,list_runs,query_items
from .delivery import send_email,send_telegram
from .scraper import CompetitorScraper

app=FastAPI(title="Конкурентный радар Клуба Козлова",version="2.0.0")
app.mount("/static",StaticFiles(directory=ROOT/"app"/"static"),name="static")
templates=Jinja2Templates(directory=ROOT/"app"/"templates")
scheduler:BackgroundScheduler|None=None
run_lock=Lock()

class RunRequest(BaseModel):
    date_from:date; date_to:date; competitors:list[str]=Field(default_factory=list); include_news:bool=True
class AliasRequest(BaseModel):
    canonical:str; alias:str

def _names(): return [x["name"] for x in load_sources()["competitors"]]
def _benchmark(): return load_sources().get("benchmark",{}).get("name","Клуб Алексея Козлова")
def _validate(a:date,b:date):
    if a>b: raise HTTPException(400,"Дата начала позже даты окончания")
    if (b-a).days>370: raise HTTPException(400,"Максимальный период одного запуска — 370 дней")
def _items_for_report(a:date,b:date,selected:list[str]|None=None):
    names=(selected or _names())+[_benchmark()]
    current=query_items(a,b,names,limit=40000)
    length=(b-a).days+1; pa=a-timedelta(days=length); pb=a-timedelta(days=1)
    previous=query_items(pa,pb,names,limit=40000)
    return current,previous

async def collect_period(a:date,b:date,competitors:list[str]|None=None,include_news:bool=True):
    _validate(a,b); valid=set(_names()); selected=[x for x in (competitors or []) if x in valid]
    if competitors and len(selected)!=len(competitors): raise HTTPException(400,"Есть неизвестные площадки")
    if not run_lock.acquire(blocking=False): raise HTTPException(409,"Сбор уже выполняется")
    run_id=create_run(a,b)
    try:
        collected,source_results=await CompetitorScraper().collect(a,b,selected or None,include_news)
        inserted=insert_items(run_id,collected)
        current,previous=_items_for_report(a,b,selected or None)
        report=build_report(current,a,b,previous)
        path=save_markdown_report(report)
        stats={"collected":len(collected),"inserted_or_updated":inserted,"sources":[x.__dict__ for x in source_results]}
        finish_run(run_id,"ok",stats,str(path.relative_to(ROOT)))
        return {"run_id":run_id,"report":report,"stats":stats}
    except Exception as exc:
        finish_run(run_id,"error",{},message=str(exc)[:1000])
        if isinstance(exc,HTTPException): raise
        raise HTTPException(500,f"Ошибка сбора: {exc}") from exc
    finally: run_lock.release()

def scheduled_weekly_run():
    end=date.today()-timedelta(days=1); start=end-timedelta(days=6)
    async def job():
        result=await collect_period(start,end,None,True)
        report=result["report"]
        if settings.telegram_bot_token and settings.telegram_chat_id:
            await send_telegram(report)
        if settings.smtp_host and settings.report_email_to:
            send_email(report,report_to_markdown(report))
    try: asyncio.run(job())
    except Exception as exc: print(f"[scheduler] {exc}")

@app.on_event("startup")
def startup():
    global scheduler
    init_db()
    if settings.scheduler_enabled:
        scheduler=BackgroundScheduler(timezone=settings.timezone)
        scheduler.add_job(scheduled_weekly_run,CronTrigger(day_of_week=settings.weekly_day,hour=settings.weekly_hour,minute=settings.weekly_minute,timezone=settings.timezone),id="weekly_radar",replace_existing=True,max_instances=1,coalesce=True)
        scheduler.start()
@app.on_event("shutdown")
def shutdown():
    if scheduler: scheduler.shutdown(wait=False)

@app.get("/",response_class=HTMLResponse)
def index(request:Request):
    today=date.today(); return templates.TemplateResponse(request=request,name="index.html",context={"competitors":_names(),"default_from":(today-timedelta(days=7)).isoformat(),"default_to":today.isoformat(),"weekly_schedule":f"{settings.weekly_day} {settings.weekly_hour:02d}:{settings.weekly_minute:02d} {settings.timezone}"})
@app.get("/health")
def health(): return {"status":"ok","latest_run":latest_run(),"version":"2.0.0"}
@app.post("/api/run")
async def run_collection(p:RunRequest): return await collect_period(p.date_from,p.date_to,p.competitors,p.include_news)
@app.get("/api/report")
def report(date_from:date=Query(...),date_to:date=Query(...),competitors:str=Query("")):
    _validate(date_from,date_to); selected=[x for x in competitors.split("|") if x]; cur,prev=_items_for_report(date_from,date_to,selected or None); return build_report(cur,date_from,date_to,prev)
@app.get("/api/runs")
def runs(): return list_runs(30)
@app.get("/api/sources")
def sources(): return {**load_sources(),"scheduler":{"enabled":settings.scheduler_enabled,"timezone":settings.timezone,"day":settings.weekly_day,"hour":settings.weekly_hour,"minute":settings.weekly_minute},"internal_ics_count":len(settings.kozlov_ics_urls)}
@app.get("/api/aliases")
def aliases(): return list_aliases()
@app.post("/api/aliases")
def create_alias(p:AliasRequest):
    try: return add_alias(p.canonical,p.alias)
    except ValueError as exc: raise HTTPException(400,str(exc))
@app.delete("/api/aliases/{alias_id}")
def remove_alias(alias_id:int): delete_alias(alias_id); return {"deleted":True}
@app.post("/api/import-kozlov-csv")
async def import_csv(file:UploadFile=File(...),date_from:date=Form(...),date_to:date=Form(...)):
    _validate(date_from,date_to); run_id=create_run(date_from,date_to)
    result=import_kozlov_csv(run_id,await file.read())
    finish_run(run_id,"ok",{"import":result},message="Внутреннее расписание импортировано")
    cur,prev=_items_for_report(date_from,date_to,None); return {"result":result,"report":build_report(cur,date_from,date_to,prev)}
@app.post("/api/deliver/{channel}")
async def deliver(channel:str,date_from:date=Query(...),date_to:date=Query(...)):
    cur,prev=_items_for_report(date_from,date_to,None); r=build_report(cur,date_from,date_to,prev)
    if channel=="telegram": return await send_telegram(r)
    if channel=="email": return send_email(r,report_to_markdown(r))
    raise HTTPException(404,"Неизвестный канал")

def _r(a,b,c):
    selected=[x for x in c.split("|") if x]; cur,prev=_items_for_report(a,b,selected or None); return build_report(cur,a,b,prev)
@app.get("/api/export.md")
def export_md(date_from:date=Query(...),date_to:date=Query(...),competitors:str=Query("")):
    return PlainTextResponse(report_to_markdown(_r(date_from,date_to,competitors)),headers={"Content-Disposition":f'attachment; filename="radar_{date_from}_{date_to}.md"'})
@app.get("/api/export-artists.csv")
def export_artists(date_from:date=Query(...),date_to:date=Query(...),competitors:str=Query("")):
    return Response("\ufeff"+report_to_csv(_r(date_from,date_to,competitors)),media_type="text/csv; charset=utf-8",headers={"Content-Disposition":f'attachment; filename="artists_{date_from}_{date_to}.csv"'})
@app.get("/api/export-events.csv")
def export_events(date_from:date=Query(...),date_to:date=Query(...),competitors:str=Query("")):
    return Response("\ufeff"+report_to_events_csv(_r(date_from,date_to,competitors)),media_type="text/csv; charset=utf-8",headers={"Content-Disposition":f'attachment; filename="events_{date_from}_{date_to}.csv"'})
@app.get("/reports/{filename}")
def report_file(filename:str):
    path=settings.report_dir/Path(filename).name
    if not path.exists(): raise HTTPException(404,"Отчёт не найден")
    return FileResponse(path)
