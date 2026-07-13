from __future__ import annotations

import asyncio
import hashlib
import json
import re
import time
from dataclasses import dataclass
from datetime import date, datetime, time as dt_time
from email.utils import parsedate_to_datetime
from typing import Any, Iterable
from urllib.parse import quote_plus, urljoin, urlparse
from urllib.robotparser import RobotFileParser

import dateparser
import feedparser
import httpx
from bs4 import BeautifulSoup, Tag
from dateparser.search import search_dates

from .classification import classify, extract_price, normalize_text
from .config import load_sources, settings
from .models import CollectedItem

DATE_HINT_RE = re.compile(
    r"\b(?:[0-3]?\d[./-][01]?\d(?:[./-]\d{2,4})?|[0-3]?\d\s+"
    r"(?:января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)|"
    r"(?:январь|февраль|март|апрель|май|июнь|июль|август|сентябрь|октябрь|ноябрь|декабрь)|"
    r"(?:mon|tue|wed|thu|fri|sat|sun)|(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b",
    re.IGNORECASE,
)
BAD_TITLES = {
    "купить билет", "купить билеты", "забронировать", "подробнее", "афиша", "концерты",
    "смотреть", "читать далее", "расписание", "билетов нет", "вход свободный",
}


@dataclass
class SourceResult:
    source_name: str
    status: str
    count: int = 0
    error: str = ""
    duration_seconds: float = 0.0


class CompetitorScraper:
    def __init__(self) -> None:
        self.config = load_sources()
        self.competitors = self.config["competitors"]
        self.aliases: dict[str, list[str]] = {
            row["name"]: [row["name"].lower(), *[a.lower() for a in row.get("aliases", [])]]
            for row in self.competitors
        }
        benchmark = self.config.get("benchmark")
        if benchmark:
            self.aliases[benchmark["name"]] = [
                benchmark["name"].lower(), *[a.lower() for a in benchmark.get("aliases", [])]
            ]
        self._robots_cache: dict[str, bool] = {}

    async def collect(
        self,
        date_from: date,
        date_to: date,
        selected_competitors: list[str] | None = None,
        include_news: bool = True,
    ) -> tuple[list[CollectedItem], list[SourceResult]]:
        sources = [s for s in self.config["sources"] if s.get("enabled", True)]
        if selected_competitors:
            allowed = set(selected_competitors) | {"__multi__", "Клуб Алексея Козлова"}
            sources = [s for s in sources if s["competitor"] in allowed]

        items: list[CollectedItem] = []
        stats: list[SourceResult] = []
        headers = {"User-Agent": settings.user_agent, "Accept-Language": "ru,en;q=0.8"}
        async with httpx.AsyncClient(
            timeout=settings.timeout,
            follow_redirects=True,
            headers=headers,
        ) as client:
            for source in sources:
                started = time.monotonic()
                try:
                    if source["kind"] == "rss":
                        parsed = await self._collect_rss(client, source, date_from, date_to)
                    else:
                        parsed = await self._collect_html(client, source, date_from, date_to)
                    items.extend(parsed)
                    stats.append(SourceResult(source["name"], "ok", len(parsed), duration_seconds=round(time.monotonic() - started, 2)))
                except Exception as exc:  # source isolation is intentional
                    stats.append(SourceResult(source["name"], "error", 0, str(exc)[:300], round(time.monotonic() - started, 2)))
                await asyncio.sleep(settings.request_delay)

            if include_news:
                competitors = selected_competitors or [row["name"] for row in self.competitors]
                for competitor in competitors:
                    started = time.monotonic()
                    source = self._news_source(competitor)
                    try:
                        parsed = await self._collect_rss(client, source, date_from, date_to)
                        items.extend(parsed)
                        stats.append(SourceResult(source["name"], "ok", len(parsed), duration_seconds=round(time.monotonic() - started, 2)))
                    except Exception as exc:
                        stats.append(SourceResult(source["name"], "error", 0, str(exc)[:300], round(time.monotonic() - started, 2)))
                    await asyncio.sleep(settings.request_delay)

        return items, stats

    def _news_source(self, competitor: str) -> dict[str, Any]:
        query = quote_plus(f'"{competitor}" (джаз OR концерт OR фестиваль OR клуб)')
        return {
            "name": f"Новости — {competitor}",
            "competitor": competitor,
            "source_type": "news",
            "kind": "rss",
            "url": f"https://news.google.com/rss/search?q={query}&hl=ru&gl=RU&ceid=RU:ru",
        }

    async def _allowed_by_robots(self, client: httpx.AsyncClient, url: str) -> bool:
        parsed = urlparse(url)
        base = f"{parsed.scheme}://{parsed.netloc}"
        if base in self._robots_cache:
            return self._robots_cache[base]
        robots_url = f"{base}/robots.txt"
        try:
            response = await client.get(robots_url)
            if response.status_code >= 400:
                allowed = True
            else:
                parser = RobotFileParser()
                parser.set_url(robots_url)
                parser.parse(response.text.splitlines())
                allowed = parser.can_fetch(settings.user_agent, url)
        except Exception:
            allowed = True
        self._robots_cache[base] = allowed
        return allowed

    async def _fetch(self, client: httpx.AsyncClient, url: str) -> str:
        if not await self._allowed_by_robots(client, url):
            raise RuntimeError("Источник запрещает автоматическое чтение в robots.txt")
        response = await client.get(url)
        response.raise_for_status()
        ctype = response.headers.get("content-type", "")
        if "text" not in ctype and "xml" not in ctype and "json" not in ctype:
            raise RuntimeError(f"Неподдерживаемый Content-Type: {ctype}")
        return response.text

    async def _collect_html(
        self, client: httpx.AsyncClient, source: dict[str, Any], date_from: date, date_to: date
    ) -> list[CollectedItem]:
        html = await self._fetch(client, source["url"])
        soup = BeautifulSoup(html, "lxml")
        parsed = self._parse_jsonld(soup, source, date_from, date_to)
        parsed.extend(self._parse_dom_candidates(soup, source, date_from, date_to))
        return self._dedupe_items(parsed)

    async def _collect_rss(
        self, client: httpx.AsyncClient, source: dict[str, Any], date_from: date, date_to: date
    ) -> list[CollectedItem]:
        text = await self._fetch(client, source["url"])
        feed = feedparser.parse(text)
        items: list[CollectedItem] = []
        for entry in feed.entries[:100]:
            published = self._entry_datetime(entry)
            if not published or not (date_from <= published.date() <= date_to):
                continue
            title = normalize_text(entry.get("title", ""))
            description = normalize_text(BeautifulSoup(entry.get("summary", ""), "lxml").get_text(" "))
            if not title:
                continue
            categories, is_import, _ = classify(f"{title} {description}")
            item = CollectedItem(
                competitor=source["competitor"],
                source_name=source["name"],
                source_type=source["source_type"],
                source_url=source["url"],
                item_url=entry.get("link", ""),
                title=title,
                description=description[:1200],
                published_at=published.replace(tzinfo=None),
                item_type="news",
                categories=categories,
                is_import=is_import,
            )
            item.raw_hash = self._hash_item(item)
            items.append(item)
        return items

    def _entry_datetime(self, entry: Any) -> datetime | None:
        for key in ("published", "updated", "created"):
            value = entry.get(key)
            if not value:
                continue
            try:
                parsed = parsedate_to_datetime(value)
                return parsed.astimezone().replace(tzinfo=None) if parsed.tzinfo else parsed
            except Exception:
                parsed = dateparser.parse(value, languages=["ru", "en"])
                if parsed:
                    return parsed.replace(tzinfo=None)
        return None

    def _parse_jsonld(
        self, soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date
    ) -> list[CollectedItem]:
        results: list[CollectedItem] = []
        for script in soup.select('script[type="application/ld+json"]'):
            try:
                data = json.loads(script.string or script.get_text())
            except Exception:
                continue
            for obj in self._walk_json(data):
                item_type = obj.get("@type")
                types = item_type if isinstance(item_type, list) else [item_type]
                if not any(str(t).lower().endswith("event") for t in types if t):
                    continue
                title = normalize_text(str(obj.get("name", "")))
                starts_at = self._parse_iso_or_text(str(obj.get("startDate", "")), date_from)
                if not title or not starts_at or not (date_from <= starts_at.date() <= date_to):
                    continue
                description = normalize_text(BeautifulSoup(str(obj.get("description", "")), "lxml").get_text(" "))
                competitor = self._resolve_competitor(source["competitor"], f"{title} {description}")
                if not competitor:
                    continue
                offers = obj.get("offers") or {}
                if isinstance(offers, list):
                    offers = offers[0] if offers else {}
                price = None
                for key in ("lowPrice", "price"):
                    try:
                        if offers.get(key) is not None:
                            price = int(float(str(offers[key]).replace(" ", "")))
                            break
                    except (ValueError, TypeError):
                        pass
                location = obj.get("location") or {}
                venue = location.get("name", "") if isinstance(location, dict) else str(location)
                categories, is_import, _ = classify(f"{title} {description}")
                item = CollectedItem(
                    competitor=competitor,
                    source_name=source["name"],
                    source_type=source["source_type"],
                    source_url=source["url"],
                    item_url=urljoin(source["url"], str(obj.get("url", ""))),
                    title=title,
                    description=description[:1200],
                    starts_at=starts_at,
                    price_min=price,
                    venue=normalize_text(venue),
                    item_type="event",
                    categories=categories,
                    is_import=is_import,
                )
                item.raw_hash = self._hash_item(item)
                results.append(item)
        return results

    def _walk_json(self, value: Any) -> Iterable[dict[str, Any]]:
        if isinstance(value, dict):
            yield value
            for child in value.values():
                yield from self._walk_json(child)
        elif isinstance(value, list):
            for child in value:
                yield from self._walk_json(child)

    def _parse_dom_candidates(
        self, soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date
    ) -> list[CollectedItem]:
        selectors = [
            '[itemtype*="Event"]',
            "article",
            '[class*="event"]',
            '[class*="concert"]',
            '[class*="schedule"] > *',
            '[class*="afisha"] > *',
            ".t-card__col",
            '[data-hook*="event"]',
        ]
        candidates: list[Tag] = []
        seen_nodes: set[int] = set()
        for selector in selectors:
            try:
                nodes = soup.select(selector)
            except Exception:
                continue
            for node in nodes:
                if not isinstance(node, Tag) or id(node) in seen_nodes:
                    continue
                text = normalize_text(node.get_text(" ", strip=True))
                if 18 <= len(text) <= 2200 and DATE_HINT_RE.search(text):
                    candidates.append(node)
                    seen_nodes.add(id(node))
                if len(candidates) >= 500:
                    break
            if len(candidates) >= 500:
                break

        if not candidates:
            for text_node in soup.find_all(string=DATE_HINT_RE):
                parent = text_node.parent
                if isinstance(parent, Tag):
                    container = parent.parent if isinstance(parent.parent, Tag) else parent
                    candidates.append(container)
                if len(candidates) >= 300:
                    break

        results: list[CollectedItem] = []
        for node in candidates:
            text = normalize_text(node.get_text(" ", strip=True))
            starts_at = self._find_event_date(text, date_from, date_to)
            if not starts_at:
                continue
            competitor = self._resolve_competitor(source["competitor"], text)
            if not competitor:
                continue
            title = self._extract_title(node, text)
            if not title:
                continue
            anchor = node.find("a", href=True)
            item_url = urljoin(source["url"], anchor["href"]) if anchor else source["url"]
            categories, is_import, _ = classify(text)
            item = CollectedItem(
                competitor=competitor,
                source_name=source["name"],
                source_type=source["source_type"],
                source_url=source["url"],
                item_url=item_url,
                title=title,
                description=text[:1200],
                starts_at=starts_at,
                price_min=extract_price(text),
                venue=competitor,
                item_type="event",
                categories=categories,
                is_import=is_import,
            )
            item.raw_hash = self._hash_item(item)
            results.append(item)
        return results

    def _find_event_date(self, text: str, date_from: date, date_to: date) -> datetime | None:
        if not DATE_HINT_RE.search(text):
            return None
        base = datetime.combine(date_from, dt_time(hour=12))
        try:
            matches = search_dates(
                text[:800],
                languages=["ru", "en"],
                settings={
                    "RELATIVE_BASE": base,
                    "DATE_ORDER": "DMY",
                    "PREFER_DATES_FROM": "future",
                    "RETURN_AS_TIMEZONE_AWARE": False,
                    "STRICT_PARSING": False,
                },
            ) or []
        except Exception:
            return None
        for _, parsed in matches:
            if date_from <= parsed.date() <= date_to:
                return parsed.replace(tzinfo=None)
        return None

    def _extract_title(self, node: Tag, fallback_text: str) -> str:
        options: list[str] = []
        for selector in ("h1", "h2", "h3", "h4", "h5", "strong", "b", "a"):
            for element in node.select(selector)[:8]:
                value = normalize_text(element.get_text(" ", strip=True))
                if value:
                    options.append(value)
        for value in options:
            clean = self._clean_title(value)
            if clean:
                return clean
        chunks = [normalize_text(chunk) for chunk in re.split(r"[|•\n]", fallback_text)]
        chunks = [c for c in chunks if 4 <= len(c) <= 180 and not DATE_HINT_RE.fullmatch(c)]
        chunks.sort(key=lambda c: (any(ch.isalpha() for ch in c), len(c)), reverse=True)
        return self._clean_title(chunks[0]) if chunks else ""

    def _clean_title(self, value: str) -> str:
        value = normalize_text(value)
        value = re.sub(r"^(?:\d{1,2}[:.]\d{2}\s*)+", "", value)
        value = re.sub(r"^(?:купить билет(?:ы)?|подробнее|забронировать)\s*", "", value, flags=re.I)
        if not value or value.lower() in BAD_TITLES or len(value) < 4 or len(value) > 220:
            return ""
        if sum(ch.isalpha() for ch in value) < 3:
            return ""
        return value

    def _resolve_competitor(self, configured: str, text: str) -> str | None:
        if configured != "__multi__":
            return configured
        lower = text.lower()
        for competitor, aliases in self.aliases.items():
            if competitor == "Клуб Алексея Козлова":
                continue
            if any(alias in lower for alias in aliases):
                return competitor
        return None

    def _parse_iso_or_text(self, value: str, date_from: date) -> datetime | None:
        if not value:
            return None
        try:
            return datetime.fromisoformat(value.replace("Z", "+00:00")).replace(tzinfo=None)
        except ValueError:
            return dateparser.parse(
                value,
                languages=["ru", "en"],
                settings={"RELATIVE_BASE": datetime.combine(date_from, dt_time(hour=12)), "DATE_ORDER": "DMY"},
            )

    def _hash_item(self, item: CollectedItem) -> str:
        date_key = item.starts_at.isoformat() if item.starts_at else (item.published_at.isoformat() if item.published_at else "")
        raw = "|".join([
            item.source_name.lower(),
            item.competitor.lower(),
            re.sub(r"\W+", "", item.title.lower()),
            date_key[:16],
            item.item_type,
        ])
        return hashlib.sha256(raw.encode("utf-8")).hexdigest()

    def _dedupe_items(self, items: list[CollectedItem]) -> list[CollectedItem]:
        unique: dict[str, CollectedItem] = {}
        for item in items:
            unique[item.raw_hash] = item
        return list(unique.values())
