from datetime import date
from app.analyzer import build_report
from app.config import load_sources
from app.db import init_db
from app.parsers import PARSERS, parse_datetimes


def event(club,title,day,artists,genres=None,source_type="official"):
    return {"competitor":club,"source_name":"test","source_type":source_type,"source_url":"https://example.com","item_url":"https://example.com/e","title":title,"description":"","starts_at":f"2026-07-{day:02d}T20:00:00","published_at":None,"price_min":1500,"venue":club,"stage":"","item_type":"event","categories":[],"genres":genres or ["джаз"],"artists":artists,"program_entities":[],"is_import":False,"is_free":False,"confidence":1.0}


def test_all_configured_parsers_have_adapters():
    handled={"wordpress_events","rss"}
    for source in load_sources()["sources"]:
        parser=source.get("parser","auto")
        assert parser in PARSERS or parser in handled, source["name"]


def test_multi_date_louis_parser():
    dates=parse_datetimes("2, 9 и 16 июля | 20:00",date(2026,7,1),date(2026,7,31))
    assert [x.day for x in dates]==[2,9,16]


def test_artist_registry_and_same_day_comparison():
    init_db()
    rows=[
        event("Клуб Алексея Козлова","Квартет Ивана Иванова",15,["Иван Иванов"]),
        event("Эссе","Иван Иванов Quartet",15,["Иван Иванов"]),
        event("Louis","Иван Иванов Trio",20,["Иван Иванов"]),
        event("Gamma More","Пётр Петров",18,["Пётр Петров"]),
    ]
    report=build_report(rows,date(2026,7,1),date(2026,7,31),[])
    ivan=next(x for x in report["artists"] if x["artist"]=="Иван Иванов")
    assert ivan["kozlov_dates"]==["2026-07-15"]
    assert set(ivan["clubs"])=={"Louis","Эссе"}
    assert len(ivan["same_day"])==1
    assert any(x["gap_days"]==5 for x in ivan["near"])
    assert report["totals"]["same_day_overlaps"]==1
    day=next(x for x in report["calendar"] if x["date"]=="2026-07-15")
    assert "Иван Иванов" in day["same_artists"]
    assert day["risk"]>=50
