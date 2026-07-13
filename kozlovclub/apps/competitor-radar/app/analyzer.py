from __future__ import annotations

import csv, io, json, re
from collections import Counter, defaultdict
from datetime import date, datetime, timedelta
from pathlib import Path
from statistics import median
from typing import Any

from .artists import canonicalize_artists, extract_artists, merge_alias_maps
from .config import load_alias_seed, load_sources, settings
from .db import list_aliases

BENCHMARK = "Клуб Алексея Козлова"
PRIORITY = {"internal":0,"benchmark_internal":0,"official":1,"benchmark":1,"benchmark_aggregator":2,"aggregator":2,"benchmark_social":3,"social":3,"news":4}
IMPORTANT = {"привоз","фестиваль","премьера","релиз","юбилей","коллаборация","партнёрство","акция/скидка","sold out","дополнительный концерт","инфоповод"}

def _key(v:str)->str:
    return re.sub(r"\s+"," ",re.sub(r"[^a-zа-яё0-9]+"," ",v.lower())).strip()

def _day(item:dict[str,Any])->str:
    return (item.get("starts_at") or item.get("published_at") or "")[:10]

def dedupe_items(items:list[dict[str,Any]])->list[dict[str,Any]]:
    groups:dict[tuple,list[dict[str,Any]]]=defaultdict(list)
    for x in items:
        groups[(x["competitor"],x["item_type"],_day(x),_key(x["title"])[:140])].append(x)
    out=[]
    for rows in groups.values():
        rows.sort(key=lambda x:(PRIORITY.get(x.get("source_type",""),9),-float(x.get("confidence") or 0)))
        base=dict(rows[0])
        base["sources"]=[{"name":r["source_name"],"type":r["source_type"],"url":r.get("item_url") or r.get("source_url")} for r in rows]
        for field in ("categories","genres","artists","program_entities"):
            base[field]=sorted({v for r in rows for v in r.get(field,[])})
        base["price_min"]=min([r["price_min"] for r in rows if r.get("price_min")],default=None)
        base["is_import"]=any(r.get("is_import") for r in rows)
        base["is_free"]=any(r.get("is_free") for r in rows)
        out.append(base)
    return sorted(out,key=lambda x:(x.get("starts_at") or x.get("published_at") or "",x["competitor"]))

def _prepare(items:list[dict[str,Any]])->list[dict[str,Any]]:
    aliases=merge_alias_maps(load_alias_seed(),list_aliases())
    out=dedupe_items(items)
    for x in out:
        raw=x.get("artists") or []
        if not raw and x["item_type"]=="event": raw,_=extract_artists(x["title"],x.get("description", ""))
        x["artists_raw"]=raw
        x["artists"]=canonicalize_artists(raw,aliases)
    return out

def _artist_registry(events:list[dict[str,Any]], benchmark:str)->list[dict[str,Any]]:
    reg:dict[str,list[dict[str,Any]]]=defaultdict(list)
    for event in events:
        for artist in event.get("artists",[]): reg[artist].append(event)
    rows=[]
    for artist,appearances in reg.items():
        koz=[x for x in appearances if x["competitor"]==benchmark]
        comp=[x for x in appearances if x["competitor"]!=benchmark]
        clubs=sorted({x["competitor"] for x in comp})
        same=[]; near=[]; nearest=None
        for k in koz:
            kd=datetime.fromisoformat(k["starts_at"]).date() if k.get("starts_at") else None
            if not kd: continue
            for c in comp:
                cd=datetime.fromisoformat(c["starts_at"]).date() if c.get("starts_at") else None
                if not cd: continue
                gap=abs((cd-kd).days)
                nearest=gap if nearest is None else min(nearest,gap)
                pair={"artist":artist,"kozlov":k,"competitor":c,"gap_days":gap,"same_day":gap==0}
                if gap==0: same.append(pair)
                elif gap<=14: near.append(pair)
        rows.append({
            "artist":artist,"appearances":len(appearances),"clubs":clubs,"club_count":len(clubs),
            "kozlov_dates":sorted({_day(x) for x in koz}),"competitor_dates":sorted({_day(x) for x in comp}),
            "kozlov_events":koz,"competitor_events":comp,"same_day":same,"near":sorted(near,key=lambda x:x["gap_days"]),
            "nearest_gap_days":nearest,"has_kozlov":bool(koz),"has_competitors":bool(comp),
        })
    return sorted(rows,key=lambda x:(not bool(x["same_day"]),not (x["has_kozlov"] and x["has_competitors"]),-(x["appearances"]),x["artist"]))

def _overlaps(registry:list[dict[str,Any]])->list[dict[str,Any]]:
    out=[]
    for row in registry:
        out.extend(row["same_day"]); out.extend(row["near"])
    unique={}
    for x in out:
        k=(x["artist"],x["kozlov"].get("starts_at"),x["competitor"].get("starts_at"),x["competitor"]["competitor"])
        unique[k]=x
    return sorted(unique.values(),key=lambda x:(not x["same_day"],x["gap_days"],x["artist"]))

def _calendar(events:list[dict[str,Any]],benchmark:str)->list[dict[str,Any]]:
    days:dict[str,list[dict[str,Any]]]=defaultdict(list)
    for e in events:
        if e.get("starts_at"): days[_day(e)].append(e)
    rows=[]
    for day,evs in sorted(days.items()):
        koz=[e for e in evs if e["competitor"]==benchmark]; comp=[e for e in evs if e["competitor"]!=benchmark]
        if not koz and not comp: continue
        genre_intersection=sorted(set(v for e in koz for v in e.get("genres",[])) & set(v for e in comp for v in e.get("genres",[])))
        artists_intersection=sorted(set(v for e in koz for v in e.get("artists",[])) & set(v for e in comp for v in e.get("artists",[])))
        risk=min(100,50*len(artists_intersection)+10*len(genre_intersection)+min(30,5*len(comp))) if koz and comp else 0
        rows.append({"date":day,"kozlov":koz,"competitors":comp,"same_artists":artists_intersection,"same_genres":genre_intersection,"risk":risk})
    return rows

def _comp_cards(events,news,names,benchmark,previous_events):
    cards=[]
    for name in names:
        ev=[x for x in events if x["competitor"]==name]; nw=[x for x in news if x["competitor"]==name]
        prev=[x for x in previous_events if x["competitor"]==name]
        artists=sorted({a for x in ev for a in x.get("artists",[])})
        cats=Counter(c for x in ev+nw for c in x.get("categories",[])); genres=Counter(g for x in ev for g in x.get("genres",[]))
        prices=[x["price_min"] for x in ev if x.get("price_min")]
        koz_artists={a for x in events if x["competitor"]==benchmark for a in x.get("artists",[])}
        shared=sorted(set(artists)&koz_artists)
        delta=len(ev)-len(prev); pct=round(delta/len(prev)*100) if prev else (100 if ev else 0)
        score=min(100,len(ev)*2+len(shared)*8+sum(1 for x in ev if x.get("is_import"))*12+sum(cats[k] for k in IMPORTANT)*5+len(nw)*2)
        cards.append({
            "name":name,"event_count":len(ev),"previous_event_count":len(prev),"delta":delta,"delta_percent":pct,
            "news_count":len(nw),"artist_count":len(artists),"artists":artists,"shared_artists":shared,
            "imports":sum(1 for x in ev if x.get("is_import")),"festivals":cats.get("фестиваль",0),
            "special_formats":sum(v for k,v in cats.items() if k in {"джем","бранч","ночной формат","при свечах","трибьют","шоу/кросс-жанр"}),
            "price_min":min(prices) if prices else None,"price_median":round(median(prices)) if prices else None,
            "category_counts":dict(cats.most_common()),"genre_counts":dict(genres.most_common()),"pressure_score":score,
            "notable":sorted([x for x in ev+nw if set(x.get("categories",[]))&IMPORTANT],key=lambda x:x.get("starts_at") or x.get("published_at") or "",reverse=True)[:12]
        })
    return sorted(cards,key=lambda x:x["pressure_score"],reverse=True)

def _executive(cards,overlaps,calendar,news,registry):
    facts=[]; risks=[]; opportunities=[]; actions=[]
    if cards:
        top=cards[0]; facts.append({"text":f"Наибольшее конкурентное давление: {top['name']} — {top['event_count']} событий, {top['artist_count']} артистов, индекс {top['pressure_score']}/100.","evidence":top["notable"][:2]})
        grow=max(cards,key=lambda x:x["delta"])
        if grow["delta"]>0: facts.append({"text":f"Самый заметный рост афиши: {grow['name']} — на {grow['delta']} событий больше предыдущего сопоставимого периода.","evidence":[]})
    same=[x for x in overlaps if x["same_day"]]
    if same: risks.append({"text":f"Обнаружено {len(same)} совпадений одного артиста у Клуба Козлова и конкурента в одну дату.","evidence":[x["competitor"] for x in same[:3]]})
    near=[x for x in overlaps if not x["same_day"]]
    if near: risks.append({"text":f"Ещё {len(near)} выступлений тех же артистов проходят у конкурентов в пределах 14 дней от нашей даты.","evidence":[x["competitor"] for x in near[:3]]})
    high=[x for x in calendar if x["risk"]>=50]
    if high: risks.append({"text":f"На {len(high)} датах высокая конкуренция по артистам, жанрам или плотности афиши.","evidence":[e for x in high[:3] for e in x["competitors"][:1]]})
    no_koz=[x for x in registry if x["has_competitors"] and not x["has_kozlov"] and x["club_count"]>=2]
    if no_koz: opportunities.append({"text":f"Найдены {len(no_koz)} артистов, выступающих минимум у двух конкурентов, но отсутствующих в нашей афише периода.","artists":[x["artist"] for x in no_koz[:8]]})
    imports=sum(x["imports"] for x in cards)
    if imports: opportunities.append({"text":f"В афишах отмечено {imports} потенциальных зарубежных привозов для программного мониторинга.","artists":[]})
    if same: actions.append("Проверить договорённости и коммуникацию по артистам с совпадением в одну дату; согласовать позиционирование и рекламу.")
    if high: actions.append("Усилить рекламный приоритет концертов Клуба Козлова на датах с риском 50/100 и выше.")
    if no_koz: actions.append("Передать программному отделу список артистов, активных у нескольких конкурентов, для оценки приглашения.")
    actions.append("Маркетингу использовать вкладку PR: отобрать сильные механики конкурентов и сформировать контрплан на следующую неделю.")
    return {"facts":facts,"risks":risks,"opportunities":opportunities,"actions":actions[:6]}

def build_report(items:list[dict[str,Any]],date_from:date,date_to:date,previous_items:list[dict[str,Any]]|None=None)->dict[str,Any]:
    cfg=load_sources(); names=[x["name"] for x in cfg["competitors"]]; benchmark=cfg.get("benchmark",{}).get("name",BENCHMARK)
    prepared=_prepare(items); previous=_prepare(previous_items or [])
    events=[x for x in prepared if x["item_type"]=="event"]; news=[x for x in prepared if x["item_type"]=="news"]
    prev_events=[x for x in previous if x["item_type"]=="event"]
    registry=_artist_registry(events,benchmark); overlaps=_overlaps(registry); calendar=_calendar(events,benchmark)
    cards=_comp_cards(events,news,names,benchmark,prev_events)
    executive=_executive(cards,overlaps,calendar,news,registry)
    return {
        "date_from":date_from.isoformat(),"date_to":date_to.isoformat(),"generated_at":datetime.now().isoformat(timespec="seconds"),
        "totals":{"events":sum(1 for x in events if x["competitor"]!=benchmark),"kozlov_events":sum(1 for x in events if x["competitor"]==benchmark),"news":len(news),"artists":len(registry),"shared_artists":sum(1 for x in registry if x["has_kozlov"] and x["has_competitors"]),"same_day_overlaps":sum(1 for x in overlaps if x["same_day"]),"sources":len({x["source_name"] for x in prepared})},
        "executive":executive,"competitors":cards,"artists":registry,"overlaps":overlaps,"calendar":calendar,
        "events":[x for x in events if x["competitor"]!=benchmark],"kozlov_events":[x for x in events if x["competitor"]==benchmark],"news":news,
    }

def report_to_markdown(r):
    lines=["# Конкурентный радар Клуба Алексея Козлова","",f"**Период:** {r['date_from']} — {r['date_to']}",""]
    lines += ["## Резюме", *[f"- {x['text']}" for x in r['executive']['facts']], "", "## Риски", *[f"- {x['text']}" for x in r['executive']['risks']], "", "## Возможности", *[f"- {x['text']}" for x in r['executive']['opportunities']], "", "## Действия", *[f"{i+1}. {x}" for i,x in enumerate(r['executive']['actions'])], "", "## Пересечения артистов"]
    for x in r["overlaps"]: lines.append(f"- **{x['artist']}**: Козлов {x['kozlov'].get('starts_at','')[:10]} / {x['competitor']['competitor']} {x['competitor'].get('starts_at','')[:10]} — {'тот же день' if x['same_day'] else str(x['gap_days'])+' дн.'}")
    lines += ["", "## Все артисты"]
    for x in r["artists"]: lines.append(f"- **{x['artist']}** — Козлов: {', '.join(x['kozlov_dates']) or 'нет'}; конкуренты: {', '.join(x['clubs']) or 'нет'}; даты: {', '.join(x['competitor_dates']) or 'нет'}")
    return "\n".join(lines)+"\n"

def report_to_csv(r):
    out=io.StringIO(); w=csv.writer(out,delimiter=';'); w.writerow(["Артист","Клуб Козлова — даты","Конкуренты","Даты у конкурентов","Совпадение в день","Ближайший разрыв, дней"])
    for x in r["artists"]: w.writerow([x["artist"],", ".join(x["kozlov_dates"]),", ".join(x["clubs"]),", ".join(x["competitor_dates"]),"да" if x["same_day"] else "нет",x["nearest_gap_days"] if x["nearest_gap_days"] is not None else ""])
    return out.getvalue()

def report_to_events_csv(r):
    out=io.StringIO(); w=csv.writer(out,delimiter=';'); w.writerow(["Дата","Площадка","Сцена","Артисты","Программа","Цена от","Категории","Источник"])
    for x in r["kozlov_events"]+r["events"]: w.writerow([x.get("starts_at",""),x["competitor"],x.get("stage",""),", ".join(x.get("artists",[])),x["title"],x.get("price_min") or "",", ".join(x.get("categories",[])),x.get("item_url") or x.get("source_url")])
    return out.getvalue()

def save_markdown_report(r):
    path=settings.report_dir/f"competitor-radar_{r['date_from']}_{r['date_to']}.md"; path.write_text(report_to_markdown(r),encoding="utf-8"); return path
