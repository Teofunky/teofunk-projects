from __future__ import annotations

import asyncio
import hashlib
import json
import re
import time
from dataclasses import dataclass
from datetime import date, datetime
from email.utils import parsedate_to_datetime
from typing import Any
from urllib.parse import quote_plus, urlparse
from urllib.robotparser import RobotFileParser

import dateparser
import feedparser
import httpx
from bs4 import BeautifulSoup
from icalendar import Calendar

from .classification import classify, extract_price, normalize_text
from .config import load_sources, settings
from .models import CollectedItem
from .parsers import PARSERS, parse_generic_dom, parse_jsonld, parse_telegram


@dataclass
class SourceResult:
    source_name: str
    competitor: str
    source_type: str
    status: str
    count: int = 0
    parser: str = ""
    http_status: int | None = None
    error: str = ""
    duration_seconds: float = 0.0


class CompetitorScraper:
    def __init__(self) -> None:
        self.config = load_sources()
        rows = [*self.config["competitors"], self.config["benchmark"]]
        self.aliases: dict[str, list[str]] = {
            row["name"]: [row["name"].lower(), *[a.lower() for a in row.get("aliases", [])]]
            for row in rows
        }
        self._robots_cache: dict[str, bool] = {}
        self._semaphore = asyncio.Semaphore(settings.max_concurrency)

    # Backward-compatible parser hooks used by tests and local diagnostics.
    def _parse_jsonld(self, soup, source, date_from, date_to):
        return parse_jsonld(soup, source, date_from, date_to)

    def _parse_dom_candidates(self, soup, source, date_from, date_to):
        return parse_generic_dom(soup, source, date_from, date_to)

    async def collect(
        self,
        date_from: date,
        date_to: date,
        selected_competitors: list[str] | None = None,
        include_news: bool = True,
    ) -> tuple[list[CollectedItem], list[SourceResult]]:
        sources = [s for s in self.config["sources"] if s.get("enabled", True)]
        if selected_competitors:
            allowed = set(selected_competitors) | {"__multi__", self.config["benchmark"]["name"]}
            sources = [s for s in sources if s["competitor"] in allowed]

        headers = {
            "User-Agent": settings.user_agent,
            "Accept-Language": "ru-RU,ru;q=0.9,en;q=0.7",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.7",
        }
        limits = httpx.Limits(max_connections=max(4, settings.max_concurrency), max_keepalive_connections=4)
        async with httpx.AsyncClient(timeout=settings.timeout, follow_redirects=True, headers=headers, limits=limits) as client:
            tasks = [self._collect_source(client, source, date_from, date_to) for source in sources]
            results = await asyncio.gather(*tasks)

            items: list[CollectedItem] = []
            stats: list[SourceResult] = []
            for source_items, result in results:
                items.extend(source_items)
                stats.append(result)

            if settings.kozlov_ics_urls:
                ics_items, ics_stats = await self._collect_ics_sources(client, date_from, date_to)
                items.extend(ics_items)
                stats.extend(ics_stats)

            if include_news:
                competitors = selected_competitors or [row["name"] for row in self.config["competitors"]]
                news_results = await asyncio.gather(*[
                    self._collect_source(client, self._news_source(name), date_from, date_to)
                    for name in competitors
                ])
                for source_items, result in news_results:
                    items.extend(source_items)
                    stats.append(result)

        items = self._dedupe_items(items)
        return items, stats

    async def _collect_source(
        self, client: httpx.AsyncClient, source: dict[str, Any], date_from: date, date_to: date
    ) -> tuple[list[CollectedItem], SourceResult]:
        async with self._semaphore:
            started = time.monotonic()
            parser_name = source.get("parser", source.get("kind", "auto"))
            try:
                if source.get("kind") == "rss":
                    items, status_code = await self._collect_rss(client, source, date_from, date_to)
                elif parser_name == "wordpress_events":
                    items, status_code = await self._collect_wordpress_events(client, source, date_from, date_to)
                else:
                    html, status_code = await self._fetch(client, source["url"])
                    soup = BeautifulSoup(html, "lxml")
                    if source.get("kind") == "telegram" or parser_name == "telegram":
                        items = parse_telegram(soup, source, date_from, date_to)
                    else:
                        items = parse_jsonld(soup, source, date_from, date_to)
                        parser = PARSERS.get(parser_name, parse_generic_dom)
                        items.extend(parser(soup, source, date_from, date_to))
                    items = self._map_multi_source(items)
                    items = self._dedupe_items(items)
                for item in items:
                    item.raw_hash = self._hash_item(item)
                result = SourceResult(
                    source_name=source["name"], competitor=source["competitor"],
                    source_type=source["source_type"], status="ok" if items else "empty", count=len(items),
                    parser=parser_name, http_status=status_code,
                    error="" if items else "Источник доступен, но записи за период не распознаны",
                    duration_seconds=round(time.monotonic() - started, 2),
                )
                return items, result
            except Exception as exc:  # one source must not break the whole report
                result = SourceResult(
                    source_name=source["name"], competitor=source["competitor"],
                    source_type=source["source_type"], status="error", count=0,
                    parser=parser_name, error=str(exc)[:500],
                    duration_seconds=round(time.monotonic() - started, 2),
                )
                return [], result
            finally:
                await asyncio.sleep(settings.request_delay)

    def _news_source(self, competitor: str) -> dict[str, Any]:
        query = quote_plus(f'"{competitor}" (джаз OR концерт OR фестиваль OR клуб OR открыл OR анонсировал)')
        return {
            "name": f"Новости — {competitor}",
            "competitor": competitor,
            "source_type": "news",
            "kind": "rss",
            "parser": "rss",
            "url": f"https://news.google.com/rss/search?q={query}&hl=ru&gl=RU&ceid=RU:ru",
        }

    async def _allowed_by_robots(self, client: httpx.AsyncClient, url: str) -> bool:
        parsed = urlparse(url)
        base = f"{parsed.scheme}://{parsed.netloc}"
        if base in self._robots_cache:
            return self._robots_cache[base]
        if not settings.strict_robots:
            self._robots_cache[base] = True
            return True
        try:
            response = await client.get(f"{base}/robots.txt")
            if response.status_code >= 400:
                allowed = True
            else:
                parser = RobotFileParser()
                parser.set_url(f"{base}/robots.txt")
                parser.parse(response.text.splitlines())
                allowed = parser.can_fetch(settings.user_agent, url)
        except Exception:
            allowed = True
        self._robots_cache[base] = allowed
        return allowed

    async def _fetch(self, client: httpx.AsyncClient, url: str) -> tuple[str, int]:
        if not await self._allowed_by_robots(client, url):
            raise RuntimeError("Источник запрещает автоматическое чтение в robots.txt")
        response = await client.get(url)
        response.raise_for_status()
        ctype = response.headers.get("content-type", "")
        if not any(token in ctype for token in ("text", "xml", "json", "calendar")):
            raise RuntimeError(f"Неподдерживаемый Content-Type: {ctype}")
        return response.text, response.status_code

    async def _collect_rss(
        self, client: httpx.AsyncClient, source: dict[str, Any], date_from: date, date_to: date
    ) -> tuple[list[CollectedItem], int]:
        text, status_code = await self._fetch(client, source["url"])
        feed = feedparser.parse(text)
        items: list[CollectedItem] = []
        for entry in feed.entries[:150]:
            published = self._entry_datetime(entry)
            if not published or not (date_from <= published.date() <= date_to):
                continue
            title = normalize_text(entry.get("title", ""))
            description = normalize_text(BeautifulSoup(entry.get("summary", ""), "lxml").get_text(" "))
            if not title:
                continue
            categories, is_import, genres = classify(f"{title} {description}")
            item = CollectedItem(
                competitor=source["competitor"], source_name=source["name"],
                source_type=source["source_type"], source_url=source["url"],
                item_url=entry.get("link", ""), title=title, description=description[:4000],
                published_at=published.replace(tzinfo=None), item_type="news",
                categories=categories, genres=genres, is_import=is_import,
                confidence=0.74, parser_name="rss",
            )
            item.raw_hash = self._hash_item(item)
            items.append(item)
        return items, status_code

    def _entry_datetime(self, entry: Any) -> datetime | None:
        for key in ("published", "updated", "created"):
            value = entry.get(key)
            if not value:
                continue
            try:
                parsed = parsedate_to_datetime(value)
                return parsed.replace(tzinfo=None)
            except Exception:
                parsed = dateparser.parse(value, settings={"RETURN_AS_TIMEZONE_AWARE": False})
                if parsed:
                    return parsed
        return None

    async def _collect_wordpress_events(
        self, client: httpx.AsyncClient, source: dict[str, Any], date_from: date, date_to: date
    ) -> tuple[list[CollectedItem], int]:
        base = f"{urlparse(source['url']).scheme}://{urlparse(source['url']).netloc}"
        api_urls = [
            f"{base}/wp-json/tribe/events/v1/events?start_date={date_from.isoformat()}&end_date={date_to.isoformat()}&per_page=100",
            f"{base}/wp-json/wp/v2/tribe_events?per_page=100&after={date_from.isoformat()}T00:00:00&before={date_to.isoformat()}T23:59:59",
        ]
        for api_url in api_urls:
            try:
                text, code = await self._fetch(client, api_url)
                payload = json.loads(text)
                rows = payload.get("events", payload) if isinstance(payload, dict) else payload
                if not isinstance(rows, list):
                    continue
                items: list[CollectedItem] = []
                for row in rows:
                    title_value = row.get("title", "")
                    title = title_value.get("rendered", "") if isinstance(title_value, dict) else title_value
                    title = normalize_text(BeautifulSoup(str(title), "lxml").get_text(" "))
                    raw_start = row.get("start_date") or row.get("date") or row.get("startDate")
                    starts = dateparser.parse(str(raw_start), settings={"RETURN_AS_TIMEZONE_AWARE": False}) if raw_start else None
                    if not title or not starts or not (date_from <= starts.date() <= date_to):
                        continue
                    desc_value = row.get("description") or row.get("content") or ""
                    if isinstance(desc_value, dict):
                        desc_value = desc_value.get("rendered", "")
                    description = normalize_text(BeautifulSoup(str(desc_value), "lxml").get_text(" "))
                    venue = row.get("venue") or {}
                    venue_name = normalize_text(str(venue.get("venue") or venue.get("name") or "")) if isinstance(venue, dict) else ""
                    stage = venue_name
                    url = row.get("url") or row.get("link") or source["url"]
                    categories, is_import, genres = classify(f"{title} {description}")
                    from .artists import extract_artists
                    artists, subjects = extract_artists(title, description)
                    item = CollectedItem(
                        competitor=source["competitor"], source_name=source["name"],
                        source_type=source["source_type"], source_url=source["url"], item_url=url,
                        title=title, description=description[:4000], starts_at=starts,
                        price_min=extract_price(f"{title} {description}"), venue=venue_name, stage=stage,
                        categories=categories, genres=genres, artists=artists, program_entities=subjects,
                        is_import=is_import, confidence=0.99, parser_name="wordpress_api",
                    )
                    item.raw_hash = self._hash_item(item)
                    items.append(item)
                if items:
                    return self._dedupe_items(items), code
            except Exception:
                continue
        html, code = await self._fetch(client, source["url"])
        soup = BeautifulSoup(html, "lxml")
        items = parse_jsonld(soup, source, date_from, date_to)
        items.extend(parse_generic_dom(soup, source, date_from, date_to))
        return self._dedupe_items(items), code

    async def _collect_ics_sources(
        self, client: httpx.AsyncClient, date_from: date, date_to: date
    ) -> tuple[list[CollectedItem], list[SourceResult]]:
        all_items: list[CollectedItem] = []
        stats: list[SourceResult] = []
        for index, url in enumerate(settings.kozlov_ics_urls, start=1):
            started = time.monotonic()
            name = f"Клуб Козлова — внутренний календарь {index}"
            try:
                text, code = await self._fetch(client, url)
                cal = Calendar.from_ical(text)
                items: list[CollectedItem] = []
                for component in cal.walk("VEVENT"):
                    start_value = component.decoded("DTSTART")
                    if isinstance(start_value, date) and not isinstance(start_value, datetime):
                        starts = datetime.combine(start_value, datetime.min.time())
                    else:
                        starts = start_value.replace(tzinfo=None) if getattr(start_value, "tzinfo", None) else start_value
                    if not (date_from <= starts.date() <= date_to):
                        continue
                    title = normalize_text(str(component.get("SUMMARY", "")))
                    description = normalize_text(str(component.get("DESCRIPTION", "")))
                    location = normalize_text(str(component.get("LOCATION", "")))
                    categories, is_import, genres = classify(f"{title} {description}")
                    from .artists import extract_artists
                    artists, subjects = extract_artists(title, description)
                    item = CollectedItem(
                        competitor=self.config["benchmark"]["name"], source_name=name,
                        source_type="benchmark_internal", source_url="internal://google-calendar",
                        item_url=str(component.get("URL", "")), title=title, description=description,
                        starts_at=starts, venue=location, stage=location, categories=categories,
                        genres=genres, artists=artists, program_entities=subjects, is_import=is_import,
                        confidence=1.0, parser_name="ics",
                    )
                    item.raw_hash = self._hash_item(item)
                    items.append(item)
                all_items.extend(items)
                stats.append(SourceResult(name, self.config["benchmark"]["name"], "benchmark_internal", "ok", len(items), "ics", code, duration_seconds=round(time.monotonic()-started,2)))
            except Exception as exc:
                stats.append(SourceResult(name, self.config["benchmark"]["name"], "benchmark_internal", "error", 0, "ics", error=str(exc)[:500], duration_seconds=round(time.monotonic()-started,2)))
        return all_items, stats

    def _map_multi_source(self, items: list[CollectedItem]) -> list[CollectedItem]:
        result: list[CollectedItem] = []
        for item in items:
            if item.competitor != "__multi__":
                result.append(item)
                continue
            haystack = f"{item.title} {item.description} {item.venue}".lower()
            assigned = ""
            for competitor, aliases in self.aliases.items():
                if any(alias in haystack for alias in aliases):
                    assigned = competitor
                    break
            if assigned:
                item.competitor = assigned
                result.append(item)
        return result

    def _dedupe_items(self, items: list[CollectedItem]) -> list[CollectedItem]:
        priority = {"benchmark_internal": 0, "benchmark": 1, "official": 1, "social": 2, "aggregator": 3, "news": 4}
        grouped: dict[tuple[str, str, str, str], CollectedItem] = {}
        for item in items:
            date_key = (item.starts_at or item.published_at).strftime("%Y-%m-%d") if (item.starts_at or item.published_at) else ""
            title_key = re.sub(r"[^a-zа-яё0-9]+", " ", item.title.lower()).strip()[:120]
            key = (item.competitor, item.item_type, date_key, title_key)
            existing = grouped.get(key)
            if not existing or priority.get(item.source_type, 9) < priority.get(existing.source_type, 9):
                grouped[key] = item
        return list(grouped.values())

    def _hash_item(self, item: CollectedItem) -> str:
        when = item.starts_at or item.published_at
        payload = "|".join([
            item.competitor, item.item_type, item.source_name,
            when.isoformat(timespec="minutes") if when else "",
            normalize_text(item.title).lower(), item.item_url,
        ])
        return hashlib.sha256(payload.encode("utf-8")).hexdigest()
