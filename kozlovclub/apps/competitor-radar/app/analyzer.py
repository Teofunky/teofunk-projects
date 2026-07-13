from __future__ import annotations

import csv
import io
import json
import re
from collections import Counter, defaultdict
from datetime import date, datetime
from pathlib import Path
from statistics import mean
from typing import Any

from rapidfuzz import fuzz

from .classification import classify, normalize_text
from .config import load_sources, settings

GENERIC_TITLE_WORDS = {
    "концерт", "программа", "шоу", "вечер", "клуб", "джаз", "jazz", "live",
    "трио", "квартет", "квинтет", "группа", "ансамбль", "оркестр", "проект",
}


def _title_key(title: str) -> str:
    value = re.sub(r"[^a-zа-яё0-9 ]+", " ", title.lower())
    words = [w for w in value.split() if w not in GENERIC_TITLE_WORDS and len(w) > 1]
    return " ".join(words[:16]) or value.strip()


def _event_key(item: dict[str, Any]) -> tuple[str, str, str]:
    starts = item.get("starts_at") or ""
    return item["competitor"], starts[:10], _title_key(item["title"])


def dedupe_items(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    grouped: dict[tuple[str, str, str], list[dict[str, Any]]] = defaultdict(list)
    for item in items:
        if item["item_type"] == "event":
            key = _event_key(item)
        else:
            key = (item["competitor"], (item.get("published_at") or "")[:10], _title_key(item["title"]))
        grouped[key].append(item)

    merged: list[dict[str, Any]] = []
    priority = {"official": 0, "benchmark": 0, "aggregator": 1, "news": 2}
    for rows in grouped.values():
        rows.sort(key=lambda r: priority.get(r["source_type"], 9))
        base = dict(rows[0])
        base["sources"] = [
            {"name": r["source_name"], "type": r["source_type"], "url": r["item_url"] or r["source_url"]}
            for r in rows
        ]
        all_categories = sorted({cat for r in rows for cat in r.get("categories", [])})
        base["categories"] = all_categories
        base["is_import"] = any(r.get("is_import") for r in rows)
        prices = [r["price_min"] for r in rows if r.get("price_min")]
        base["price_min"] = min(prices) if prices else None
        merged.append(base)
    merged.sort(key=lambda r: (r["competitor"], r.get("starts_at") or r.get("published_at") or ""))
    return merged


def _artist_overlap(competitor_titles: list[str], benchmark_titles: list[str]) -> list[dict[str, Any]]:
    overlaps: list[dict[str, Any]] = []
    benchmark_keys = [(title, _title_key(title)) for title in benchmark_titles]
    used: set[tuple[str, str]] = set()
    for title in competitor_titles:
        key = _title_key(title)
        if len(key) < 4:
            continue
        best_title = ""
        best_score = 0
        for benchmark_title, benchmark_key in benchmark_keys:
            score = fuzz.token_set_ratio(key, benchmark_key)
            if score > best_score:
                best_score = score
                best_title = benchmark_title
        if best_score >= 88 and (title, best_title) not in used:
            overlaps.append({"competitor_title": title, "kozlov_title": best_title, "score": best_score})
            used.add((title, best_title))
    return sorted(overlaps, key=lambda x: x["score"], reverse=True)[:15]


def build_report(items: list[dict[str, Any]], date_from: date, date_to: date) -> dict[str, Any]:
    config = load_sources()
    competitor_names = [row["name"] for row in config["competitors"]]
    benchmark_name = config.get("benchmark", {}).get("name", "Клуб Алексея Козлова")
    deduped = dedupe_items(items)
    events = [x for x in deduped if x["item_type"] == "event"]
    news = [x for x in deduped if x["item_type"] == "news"]
    benchmark_events = [x for x in events if x["competitor"] == benchmark_name]
    benchmark_titles = [x["title"] for x in benchmark_events]

    competitors: list[dict[str, Any]] = []
    for name in competitor_names:
        cevents = [x for x in events if x["competitor"] == name]
        cnews = [x for x in news if x["competitor"] == name]
        categories = Counter(cat for x in [*cevents, *cnews] for cat in x.get("categories", []))
        genres = Counter()
        for x in cevents:
            _, _, found_genres = classify(f"{x['title']} {x.get('description', '')}")
            genres.update(found_genres)
        title_counts = Counter(_title_key(x["title"]) for x in cevents)
        display_for_key: dict[str, str] = {}
        for event in cevents:
            display_for_key.setdefault(_title_key(event["title"]), event["title"])
        repeated = [
            {"title": display_for_key[key], "count": count}
            for key, count in title_counts.most_common(8)
            if count > 1
        ]
        prices = [x["price_min"] for x in cevents if x.get("price_min")]
        notable = [
            x for x in [*cevents, *cnews]
            if set(x.get("categories", [])) & {
                "фестиваль", "премьера", "привоз", "юбилей", "акция/скидка",
                "инфоповод", "благотворительность", "коллаборация", "шоу/кросс-жанр",
            }
        ]
        notable.sort(key=lambda x: x.get("starts_at") or x.get("published_at") or "", reverse=True)
        source_counts = Counter(x["source_type"] for x in [*cevents, *cnews])
        overlaps = _artist_overlap([x["title"] for x in cevents], benchmark_titles)
        competitors.append({
            "name": name,
            "event_count": len(cevents),
            "news_count": len(cnews),
            "unique_programs": len(title_counts),
            "price_min": min(prices) if prices else None,
            "price_average": round(mean(prices)) if prices else None,
            "imports": sum(1 for x in cevents if x.get("is_import")),
            "festivals": categories.get("фестиваль", 0),
            "premieres": categories.get("премьера", 0),
            "promos": categories.get("акция/скидка", 0),
            "special_formats": sum(categories.get(k, 0) for k in (
                "джем", "бранч", "ночной формат", "шоу/кросс-жанр", "при свечах", "трибьют"
            )),
            "category_counts": dict(categories.most_common()),
            "genre_counts": dict(genres.most_common()),
            "source_counts": dict(source_counts),
            "repeated_programs": repeated,
            "notable": notable[:12],
            "overlap_with_kozlov": overlaps,
        })

    volume_leader = max(competitors, key=lambda x: x["event_count"], default=None)
    imports_leader = max(competitors, key=lambda x: x["imports"], default=None)
    promos_leader = max(competitors, key=lambda x: x["promos"], default=None)
    festival_leader = max(competitors, key=lambda x: x["festivals"], default=None)
    all_categories = Counter(cat for x in deduped for cat in x.get("categories", []))

    signals: list[str] = []
    if volume_leader and volume_leader["event_count"]:
        signals.append(f"Максимальный объём афиши: {volume_leader['name']} — {volume_leader['event_count']} событий.")
    if imports_leader and imports_leader["imports"]:
        signals.append(f"Больше всего признаков зарубежных привозов: {imports_leader['name']} — {imports_leader['imports']}.")
    if festival_leader and festival_leader["festivals"]:
        signals.append(f"Наиболее заметная фестивальная активность: {festival_leader['name']} — {festival_leader['festivals']} упоминаний.")
    if promos_leader and promos_leader["promos"]:
        signals.append(f"Наиболее активные скидки/розыгрыши: {promos_leader['name']} — {promos_leader['promos']} упоминаний.")
    if all_categories:
        signals.append("Самые распространённые форматы: " + ", ".join(f"{k} ({v})" for k, v in all_categories.most_common(5)) + ".")

    return {
        "date_from": date_from.isoformat(),
        "date_to": date_to.isoformat(),
        "generated_at": datetime.now().isoformat(timespec="seconds"),
        "totals": {
            "events": len([x for x in events if x["competitor"] != benchmark_name]),
            "news": len(news),
            "benchmark_events": len(benchmark_events),
            "sources": len({x["source_name"] for x in deduped}),
        },
        "competitors": competitors,
        "signals": signals,
        "events": [x for x in events if x["competitor"] != benchmark_name],
        "news": news,
        "benchmark_events": benchmark_events,
    }


def report_to_markdown(report: dict[str, Any]) -> str:
    lines = [
        "# Обзор конкурентов Клуба Алексея Козлова",
        "",
        f"**Период:** {report['date_from']} — {report['date_to']}",
        f"**Сформирован:** {report['generated_at']}",
        "",
        "## Краткая сводка",
        "",
        f"- Событий конкурентов: {report['totals']['events']}",
        f"- Новостных и PR-упоминаний: {report['totals']['news']}",
        f"- Источников с данными: {report['totals']['sources']}",
    ]
    for signal in report["signals"]:
        lines.append(f"- {signal}")

    lines.extend(["", "## По конкурентам", ""])
    for comp in report["competitors"]:
        lines.extend([
            f"### {comp['name']}",
            "",
            f"Событий: **{comp['event_count']}** · Новостей/PR: **{comp['news_count']}** · "
            f"Привозов: **{comp['imports']}** · Фестивалей: **{comp['festivals']}** · "
            f"Спецформатов: **{comp['special_formats']}**",
        ])
        if comp["price_min"]:
            lines.append(f"Цены из доступных данных: от **{comp['price_min']} ₽**, средняя минимальная цена **{comp['price_average']} ₽**.")
        if comp["repeated_programs"]:
            lines.append("Часто повторяются: " + "; ".join(f"{x['title']} — {x['count']}" for x in comp["repeated_programs"][:5]) + ".")
        if comp["overlap_with_kozlov"]:
            lines.append("Пересечения с афишей Клуба Козлова: " + "; ".join(x["competitor_title"] for x in comp["overlap_with_kozlov"][:5]) + ".")
        if comp["notable"]:
            lines.append("Заметные активности:")
            for item in comp["notable"][:6]:
                when = (item.get("starts_at") or item.get("published_at") or "")[:10]
                cats = ", ".join(item.get("categories", []))
                lines.append(f"- {when}: {item['title']} ({cats}) — {item.get('item_url') or item.get('source_url')}")
        lines.append("")

    lines.extend(["## Полная афиша", ""])
    for item in report["events"]:
        lines.append(
            f"- {(item.get('starts_at') or '')[:16]} · **{item['competitor']}** · {item['title']}"
            + (f" · от {item['price_min']} ₽" if item.get("price_min") else "")
            + f" · {item.get('item_url') or item.get('source_url')}"
        )
    lines.extend(["", "## Новости и PR", ""])
    for item in report["news"]:
        lines.append(
            f"- {(item.get('published_at') or '')[:10]} · **{item['competitor']}** · {item['title']} · "
            f"{item.get('item_url') or item.get('source_url')}"
        )
    return "\n".join(lines)


def save_markdown_report(report: dict[str, Any]) -> Path:
    filename = f"competitor-review_{report['date_from']}_{report['date_to']}.md"
    path = settings.report_dir / filename
    path.write_text(report_to_markdown(report), encoding="utf-8")
    json_path = path.with_suffix(".json")
    json_path.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    return path


def report_to_csv(report: dict[str, Any]) -> str:
    stream = io.StringIO()
    writer = csv.writer(stream)
    writer.writerow([
        "тип", "конкурент", "дата", "название", "категории", "цена_от", "привоз",
        "источник", "ссылка",
    ])
    for item in [*report["events"], *report["news"]]:
        writer.writerow([
            item["item_type"],
            item["competitor"],
            item.get("starts_at") or item.get("published_at") or "",
            item["title"],
            ", ".join(item.get("categories", [])),
            item.get("price_min") or "",
            "да" if item.get("is_import") else "нет",
            item["source_name"],
            item.get("item_url") or item.get("source_url"),
        ])
    return stream.getvalue()
