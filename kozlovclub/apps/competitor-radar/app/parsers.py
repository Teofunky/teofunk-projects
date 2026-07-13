from __future__ import annotations

import json
import re
from datetime import date, datetime, time as dt_time
from typing import Any, Iterable
from urllib.parse import urljoin

import dateparser
from bs4 import BeautifulSoup, Tag

from .artists import extract_artists
from .classification import classify, extract_price, normalize_text
from .models import CollectedItem

RU_MONTHS = {
    "января": 1, "январь": 1, "февраля": 2, "февраль": 2, "марта": 3, "март": 3,
    "апреля": 4, "апрель": 4, "мая": 5, "май": 5, "июня": 6, "июнь": 6,
    "июля": 7, "июль": 7, "августа": 8, "август": 8, "сентября": 9, "сентябрь": 9,
    "октября": 10, "октябрь": 10, "ноября": 11, "ноябрь": 11, "декабря": 12, "декабрь": 12,
}
MONTH_RE = "|".join(RU_MONTHS)
DATE_RE = re.compile(
    rf"(?P<day>[0-3]?\d)\s+(?P<month>{MONTH_RE})(?:\s+(?P<year>20\d{{2}}))?"
    rf"(?:\s*[,|•]?\s*(?P<time>[0-2]?\d:[0-5]\d))?",
    re.I,
)
NUMERIC_DATE_RE = re.compile(r"(?P<day>[0-3]?\d)[./-](?P<month>[01]?\d)[./-](?P<year>20\d{2})(?:\s*[,|•]?\s*(?P<time>[0-2]?\d:[0-5]\d))?")
MULTI_DATE_RE = re.compile(
    rf"(?P<days>\d{{1,2}}(?:\s*,\s*\d{{1,2}})*(?:\s*(?:и|&)\s*\d{{1,2}})?)\s+"
    rf"(?P<month>{MONTH_RE})(?:\s+(?P<year>20\d{{2}}))?\s*(?:\||,)?\s*(?P<time>[0-2]?\d:[0-5]\d)?",
    re.I,
)
DATE_HINT_RE = re.compile(rf"(?:\b[0-3]?\d\s+(?:{MONTH_RE})\b|\b[0-3]?\d[./-][01]?\d[./-]20\d{{2}}\b)", re.I)
PRICE_FREE_RE = re.compile(r"вход\s+свободный|бесплатн|free\s+entry|от\s+0\s*₽", re.I)
BAD_TITLE_RE = re.compile(r"^(купить|билет|подробнее|забронировать|афиша|события|концерты|расписание|далее)$", re.I)


def parse_datetimes(text: str, date_from: date, date_to: date) -> list[datetime]:
    text = normalize_text(text)
    found: list[datetime] = []
    multi = MULTI_DATE_RE.search(text)
    if multi:
        month = RU_MONTHS[multi.group("month").lower()]
        year = int(multi.group("year") or date_from.year)
        hour, minute = _time_parts(multi.group("time"))
        days = [int(x) for x in re.findall(r"\d{1,2}", multi.group("days"))]
        for day in days:
            try:
                dt = datetime(year, month, day, hour, minute)
            except ValueError:
                continue
            if date_from <= dt.date() <= date_to:
                found.append(dt)
        if found:
            return found

    for match in NUMERIC_DATE_RE.finditer(text):
        hour, minute = _time_parts(match.group("time"))
        try:
            dt = datetime(int(match.group("year")), int(match.group("month")), int(match.group("day")), hour, minute)
        except ValueError:
            continue
        if date_from <= dt.date() <= date_to:
            found.append(dt)

    for match in DATE_RE.finditer(text):
        month = RU_MONTHS[match.group("month").lower()]
        year = int(match.group("year") or _infer_year(month, date_from, date_to))
        hour, minute = _time_parts(match.group("time"))
        try:
            dt = datetime(year, month, int(match.group("day")), hour, minute)
        except ValueError:
            continue
        if date_from <= dt.date() <= date_to:
            found.append(dt)

    # English/RFC dates and fallback.
    if not found and DATE_HINT_RE.search(text):
        parsed = dateparser.parse(
            text,
            languages=["ru", "en"],
            settings={
                "DATE_ORDER": "DMY",
                "PREFER_DATES_FROM": "future",
                "RELATIVE_BASE": datetime.combine(date_from, dt_time(12, 0)),
                "RETURN_AS_TIMEZONE_AWARE": False,
            },
        )
        if parsed and date_from <= parsed.date() <= date_to:
            found.append(parsed)

    unique: list[datetime] = []
    for item in found:
        if item not in unique:
            unique.append(item)
    return unique


def _time_parts(value: str | None) -> tuple[int, int]:
    if not value:
        return 20, 0
    hour, minute = value.split(":")
    return int(hour), int(minute)


def _infer_year(month: int, date_from: date, date_to: date) -> int:
    if date_from.year == date_to.year:
        return date_from.year
    return date_from.year if month >= date_from.month else date_to.year


def _hashable_item(
    source: dict[str, Any], title: str, starts_at: datetime | None, published_at: datetime | None,
    description: str, url: str, venue: str = "", stage: str = "", confidence: float = 0.6,
    parser_name: str = "auto", item_type: str = "event",
) -> CollectedItem:
    categories, is_import, genres = classify(f"{title} {description}")
    artists, subjects = extract_artists(title, description)
    full_text = f"{title} {description}"
    return CollectedItem(
        competitor=source["competitor"],
        source_name=source["name"],
        source_type=source["source_type"],
        source_url=source["url"],
        item_url=url,
        title=normalize_text(title),
        description=normalize_text(description)[:4000],
        starts_at=starts_at,
        published_at=published_at,
        price_min=extract_price(full_text),
        venue=venue,
        stage=stage,
        item_type=item_type,
        categories=categories,
        genres=genres,
        artists=artists,
        program_entities=subjects,
        is_import=is_import,
        is_free=bool(PRICE_FREE_RE.search(full_text)),
        confidence=confidence,
        parser_name=parser_name,
    )


def parse_jsonld(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
    output: list[CollectedItem] = []
    for script in soup.select('script[type="application/ld+json"]'):
        raw = script.string or script.get_text(" ", strip=True)
        if not raw:
            continue
        try:
            payload = json.loads(raw)
        except (json.JSONDecodeError, TypeError):
            continue
        for node in _walk_json(payload):
            node_type = node.get("@type")
            types = node_type if isinstance(node_type, list) else [node_type]
            if not any(t in {"Event", "MusicEvent", "TheaterEvent", "Festival"} for t in types):
                continue
            title = normalize_text(str(node.get("name") or ""))
            if not title:
                continue
            starts_raw = str(node.get("startDate") or "")
            starts = dateparser.parse(starts_raw, settings={"RETURN_AS_TIMEZONE_AWARE": False}) if starts_raw else None
            if starts and not (date_from <= starts.date() <= date_to):
                continue
            description = BeautifulSoup(str(node.get("description") or ""), "lxml").get_text(" ", strip=True)
            location = node.get("location") or {}
            if isinstance(location, list):
                location = location[0] if location else {}
            venue = normalize_text(str(location.get("name") or "")) if isinstance(location, dict) else ""
            offers = node.get("offers") or {}
            if isinstance(offers, list):
                offers = offers[0] if offers else {}
            price_text = ""
            if isinstance(offers, dict):
                price_text = f"от {offers.get('lowPrice') or offers.get('price') or ''} руб."
            item = _hashable_item(
                source, title, starts, None, f"{description} {price_text}",
                urljoin(source["url"], str(node.get("url") or (offers.get("url") if isinstance(offers, dict) else "") or "")),
                venue=venue, confidence=0.98, parser_name="jsonld",
            )
            output.append(item)
    return output


def _walk_json(value: Any) -> Iterable[dict[str, Any]]:
    if isinstance(value, dict):
        yield value
        for child in value.values():
            yield from _walk_json(child)
    elif isinstance(value, list):
        for child in value:
            yield from _walk_json(child)


def parse_ticketscloud(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
    output: list[CollectedItem] = []
    for anchor in soup.find_all("a", href=True):
        text = normalize_text(anchor.get_text(" ", strip=True))
        dates = parse_datetimes(text, date_from, date_to)
        if not dates:
            continue
        date_match = NUMERIC_DATE_RE.search(text) or DATE_RE.search(text)
        if not date_match:
            continue
        title = normalize_text(text[:date_match.start()])
        if not title or BAD_TITLE_RE.match(title):
            continue
        venue = ""
        venue_match = re.search(r"•\s*Москва\s*•\s*(.+?)(?:\s+от\s+\d|$)", text, re.I)
        if venue_match:
            venue = normalize_text(venue_match.group(1))
        for starts in dates:
            output.append(_hashable_item(
                source, title, starts, None, text, urljoin(source["url"], anchor["href"]),
                venue=venue, confidence=0.97, parser_name="ticketscloud",
            ))
    return output


def parse_esse(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
    output: list[CollectedItem] = []
    for heading in soup.select("h1 a, h2 a, h3 a, h4 a, h5 a, h6 a, .event-title a, .views-field-title a"):
        title = normalize_text(heading.get_text(" ", strip=True))
        if not title or BAD_TITLE_RE.match(title):
            continue
        container = _nearest_dated_container(heading, date_from, date_to)
        if not container:
            continue
        text = normalize_text(container.get_text(" ", strip=True))
        dates = parse_datetimes(text, date_from, date_to)
        if not dates:
            continue
        desc = _description_without_title(text, title)
        stage = "Jazz Lab" if "Jazz Lab" in text else ""
        for starts in dates[:3]:
            output.append(_hashable_item(
                source, title, starts, None, desc, urljoin(source["url"], heading.get("href", "")),
                stage=stage, confidence=0.96, parser_name="esse",
            ))
    return output


def parse_academ(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
    output: list[CollectedItem] = []
    for anchor in soup.find_all("a", href=True):
        title = normalize_text(anchor.get_text(" ", strip=True))
        if not title or BAD_TITLE_RE.match(title) or len(title) > 160:
            continue
        container = _nearest_dated_container(anchor, date_from, date_to, max_levels=6)
        if not container:
            continue
        text = normalize_text(container.get_text(" ", strip=True))
        if "СЦЕНА НА" not in text.upper() and "Купить билет" not in text:
            continue
        dates = parse_datetimes(text, date_from, date_to)
        if not dates:
            continue
        stage_match = re.search(r"СЦЕНА\s+НА\s+([А-ЯЁA-Z\s]+?)(?:\s+(?:ПН|ВТ|СР|ЧТ|ПТ|СБ|ВС|ВC)[,\s])", text, re.I)
        stage = normalize_text(stage_match.group(1).title()) if stage_match else ""
        desc = _description_without_title(text, title)
        for starts in dates[:2]:
            output.append(_hashable_item(
                source, title, starts, None, desc, urljoin(source["url"], anchor["href"]),
                stage=stage, confidence=0.95, parser_name="academ",
            ))
    return output


def parse_union(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
    output: list[CollectedItem] = []
    for anchor in soup.find_all("a", href=True):
        title = normalize_text(anchor.get_text(" ", strip=True))
        if not title or BAD_TITLE_RE.match(title) or len(title) > 180:
            continue
        container = anchor.find_parent(["li", "article", "div"])
        if not container:
            continue
        text = normalize_text(container.get_text(" ", strip=True))
        dates = parse_datetimes(text, date_from, date_to)
        if not dates or not re.search(r"КУПИТЬ|ОТ\s+\d", text, re.I):
            continue
        for starts in dates[:2]:
            output.append(_hashable_item(
                source, title, starts, None, _description_without_title(text, title),
                urljoin(source["url"], anchor["href"]), confidence=0.96, parser_name="union",
            ))
    return output


def parse_louis(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
    output: list[CollectedItem] = []
    # Louis uses compact blocks: date line, heading, description and button.
    for node in soup.find_all(["div", "article", "section", "li"]):
        text = normalize_text(node.get_text(" ", strip=True))
        if len(text) < 15 or len(text) > 1200:
            continue
        dates = parse_datetimes(text, date_from, date_to)
        if not dates or "Купить билет" not in text:
            continue
        title_node = node.find(["h2", "h3", "h4", "h5", "strong"])
        title = normalize_text(title_node.get_text(" ", strip=True)) if title_node else ""
        if not title:
            date_match = MULTI_DATE_RE.search(text) or DATE_RE.search(text)
            tail = text[date_match.end():] if date_match else text
            title = normalize_text(re.split(r"Купить билет", tail, maxsplit=1)[0])[:140]
        if not title or BAD_TITLE_RE.match(title):
            continue
        anchor = node.find("a", href=True)
        url = urljoin(source["url"], anchor["href"]) if anchor else source["url"]
        for starts in dates:
            output.append(_hashable_item(
                source, title, starts, None, _description_without_title(text, title), url,
                confidence=0.91, parser_name="louis",
            ))
    return output


def parse_generic_dom(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
    output: list[CollectedItem] = []

    # First pass: anchors that already contain title, date and price (aggregators/ticketing pages).
    for anchor in soup.find_all("a", href=True):
        text = normalize_text(anchor.get_text(" ", strip=True))
        dates = parse_datetimes(text, date_from, date_to)
        if not dates or len(text) > 500:
            continue
        date_match = NUMERIC_DATE_RE.search(text) or DATE_RE.search(text)
        title = normalize_text(text[:date_match.start()]) if date_match else ""
        if title and not BAD_TITLE_RE.match(title) and len(title) > 2:
            for starts in dates[:4]:
                output.append(_hashable_item(
                    source, title, starts, None, text, urljoin(source["url"], anchor["href"]),
                    confidence=0.90, parser_name="anchor_line",
                ))

    selectors = [
        "article", "li", "[class*='event']", "[class*='concert']", "[class*='afisha']",
        "[class*='schedule']", "[class*='card']", "[data-event-id]", ".t-card", ".tribe-events-calendar-list__event-row",
    ]
    seen_nodes: set[int] = set()
    for node in soup.select(",".join(selectors)):
        if id(node) in seen_nodes:
            continue
        seen_nodes.add(id(node))
        text = normalize_text(node.get_text(" ", strip=True))
        if len(text) < 12 or len(text) > 1800 or not DATE_HINT_RE.search(text):
            continue
        dates = parse_datetimes(text, date_from, date_to)
        if not dates:
            continue
        title_node = node.find(["h1", "h2", "h3", "h4", "h5", "h6"])
        if not title_node:
            title_node = node.find("a", href=True)
        title = normalize_text(title_node.get_text(" ", strip=True)) if title_node else ""
        if not title or BAD_TITLE_RE.match(title) or len(title) > 180:
            continue
        anchor = title_node if isinstance(title_node, Tag) and title_node.name == "a" else title_node.find("a", href=True) if title_node else None
        if not anchor:
            anchor = node.find("a", href=True)
        url = urljoin(source["url"], anchor.get("href", "")) if anchor else source["url"]
        for starts in dates[:4]:
            output.append(_hashable_item(
                source, title, starts, None, _description_without_title(text, title), url,
                confidence=0.76, parser_name="generic_dom",
            ))
    return output


def parse_telegram(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
    output: list[CollectedItem] = []
    for message in soup.select(".tgme_widget_message_wrap"):
        time_node = message.select_one("time[datetime]")
        text_node = message.select_one(".tgme_widget_message_text")
        if not time_node or not text_node:
            continue
        published = dateparser.parse(time_node.get("datetime", ""), settings={"RETURN_AS_TIMEZONE_AWARE": False})
        if not published or not (date_from <= published.date() <= date_to):
            continue
        text = normalize_text(text_node.get_text(" ", strip=True))
        if not text:
            continue
        first_line = normalize_text(text_node.get_text("\n", strip=True).split("\n")[0])
        title = first_line[:180] if len(first_line) >= 5 else text[:180]
        link = message.select_one(".tgme_widget_message_date")
        url = link.get("href", source["url"]) if link else source["url"]
        item = _hashable_item(
            source, title, None, published, text, url, confidence=0.93, parser_name="telegram", item_type="news",
        )
        output.append(item)
    return output


def _nearest_dated_container(node: Tag, date_from: date, date_to: date, max_levels: int = 5) -> Tag | None:
    current: Tag | None = node
    for _ in range(max_levels):
        if not current:
            return None
        text = normalize_text(current.get_text(" ", strip=True))
        if 10 <= len(text) <= 1800 and parse_datetimes(text, date_from, date_to):
            return current
        current = current.parent if isinstance(current.parent, Tag) else None
    return None


def _description_without_title(text: str, title: str) -> str:
    value = normalize_text(text.replace(title, " ", 1))
    value = re.sub(r"\b(?:Купить билет|Забронировать|Подробнее)\b", " ", value, flags=re.I)
    return normalize_text(value)


def _generic_named(name: str, confidence: float = 0.78):
    def parser(soup: BeautifulSoup, source: dict[str, Any], date_from: date, date_to: date) -> list[CollectedItem]:
        items = parse_generic_dom(soup, source, date_from, date_to)
        for item in items:
            item.parser_name = name
            item.confidence = max(item.confidence, confidence)
        return items
    return parser


# Each source family has a stable adapter name. The implementation deliberately keeps
# the tolerant DOM fallback so minor redesigns do not stop the whole weekly report.
parse_butman = _generic_named("butman", 0.83)
parse_ticketland = _generic_named("ticketland", 0.88)
parse_yandex = _generic_named("yandex_afisha", 0.84)
parse_afisha = _generic_named("afisha_ru", 0.84)
parse_rhythm_blues = _generic_named("rhythm_blues", 0.82)
parse_gamma = _generic_named("gamma_more", 0.82)
parse_mts_live = _generic_named("mts_live", 0.86)
parse_jam_site = _generic_named("jam_site", 0.82)
parse_jazzmap = _generic_named("jazzmap", 0.80)

PARSERS = {
    "auto": parse_generic_dom,
    "esse": parse_esse,
    "louis": parse_louis,
    "academ": parse_academ,
    "union": parse_union,
    "ticketscloud": parse_ticketscloud,
    "telegram": parse_telegram,
    "butman": parse_butman,
    "ticketland": parse_ticketland,
    "yandex": parse_yandex,
    "afisha": parse_afisha,
    "rhythm_blues": parse_rhythm_blues,
    "gamma": parse_gamma,
    "mts_live": parse_mts_live,
    "jam_site": parse_jam_site,
    "jazzmap": parse_jazzmap,
}
