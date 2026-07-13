from __future__ import annotations

import re
from collections import defaultdict
from typing import Iterable

from rapidfuzz import fuzz
from unidecode import unidecode

from .classification import normalize_text

GENERIC = {
    "джаз", "jazz", "концерт", "вечер", "ночь", "бранч", "brunch", "джем", "jam",
    "программа", "шоу", "клуб", "музыка", "хиты", "свидание", "летний", "воскресный",
    "субботний", "классический", "инструментал", "романтик", "советский", "французский",
    "итальянский", "блюз", "blues", "соул", "soul", "кинохиты", "стандарты", "при свечах",
    "главный концерт недели", "вход свободный", "сертификат", "стендап-концерт",
}
FORM_WORDS = {
    "трио", "квартет", "квинтет", "секстет", "септет", "октет", "ансамбль", "оркестр",
    "бэнд", "band", "project", "проект", "group", "группа", "duo", "дуэт", "orchestra",
    "quartet", "trio", "quintet", "big band", "jazz band", "collective", "school",
}
TRIBUTE_TARGET_RE = re.compile(r"(?:tribute|трибьют|посвящение)\s+(?:to\s+)?(.+)$", re.I)
PERSON_RE = re.compile(r"\b[А-ЯЁA-Z][а-яёa-z'’.-]{2,}(?:\s+[А-ЯЁA-Z][а-яёa-z'’.-]{2,}){1,2}\b")


def artist_key(value: str) -> str:
    text = unidecode(normalize_text(value)).lower().replace("yo", "e")
    text = re.sub(r"\b(the|group|band|trio|quartet|quintet|orchestra|project|группа|трио|квартет|квинтет|оркестр|проект)\b", " ", text)
    text = re.sub(r"[^a-z0-9а-яё]+", " ", text)
    return " ".join(text.split())


def _is_generic(value: str) -> bool:
    key = artist_key(value)
    if not key or len(key) < 3:
        return True
    if key in GENERIC:
        return True
    tokens = set(key.split())
    return bool(tokens) and tokens.issubset(GENERIC | FORM_WORDS)


def _clean_candidate(value: str) -> str:
    value = normalize_text(value).strip(" —–-:;,.«»\"'()[]")
    value = re.sub(r"^(?:концерт|группа|ансамбль|проект)\s+", "", value, flags=re.I)
    value = re.sub(r"\s+(?:с программой|представляет|приглашает).*$", "", value, flags=re.I)
    return value.strip()


def extract_artists(title: str, description: str = "") -> tuple[list[str], list[str]]:
    title = normalize_text(title)
    description = normalize_text(description)
    candidates: list[str] = []
    subjects: list[str] = []

    tribute = TRIBUTE_TARGET_RE.search(title)
    if tribute:
        subjects.append(_clean_candidate(tribute.group(1)))
        left = title[:tribute.start()].strip(" .—–-:")
        if left:
            candidates.append(left)
    else:
        # The leading part is usually the performer; the quoted/colon part is usually the program.
        leading = re.split(r"\s+[—–-]\s+|:\s*[«\"']|:\s+", title, maxsplit=1)[0]
        candidates.append(leading)

    # Explicit performers after dot in tribute titles: "Рей Чарлз трибьют. Денис Мажуков".
    if re.search(r"tribute|трибьют|посвящение", title, re.I) and "." in title:
        tail = title.rsplit(".", 1)[-1]
        candidates.append(tail)

    # Collaborations and guests.
    for fragment in re.split(r"\s+(?:&|и|\+|x|при участии|feat\.?|ft\.?)\s+", title, flags=re.I):
        if len(fragment.split()) <= 7:
            candidates.append(fragment)

    # Names in the first part of description often recover performers hidden by a generic title.
    for match in PERSON_RE.findall(description[:350]):
        candidates.append(match)

    result: list[str] = []
    seen: set[str] = set()
    for raw in candidates:
        value = _clean_candidate(raw)
        if not value or _is_generic(value):
            continue
        # Avoid treating tribute subjects as performers.
        if any(fuzz.token_set_ratio(artist_key(value), artist_key(s)) > 94 for s in subjects if s):
            continue
        if len(value) > 90 or len(value.split()) > 9:
            continue
        key = artist_key(value)
        if key and key not in seen:
            seen.add(key)
            result.append(value)

    clean_subjects = []
    for subject in subjects:
        if subject and not _is_generic(subject):
            clean_subjects.append(subject)
    return result[:8], clean_subjects[:5]


def canonicalize_artists(artists: Iterable[str], aliases: dict[str, list[str]]) -> list[str]:
    index: dict[str, str] = {}
    for canonical, variants in aliases.items():
        index[artist_key(canonical)] = canonical
        for variant in variants:
            index[artist_key(variant)] = canonical

    result: list[str] = []
    for artist in artists:
        key = artist_key(artist)
        canonical = index.get(key)
        if not canonical:
            best_name = ""
            best_score = 0
            for alias_key, name in index.items():
                score = fuzz.token_set_ratio(key, alias_key)
                if score > best_score:
                    best_score, best_name = score, name
            canonical = best_name if best_score >= 92 else artist
        if canonical not in result:
            result.append(canonical)
    return result


def merge_alias_maps(seed: dict[str, list[str]], db_rows: list[dict[str, str]]) -> dict[str, list[str]]:
    merged: dict[str, list[str]] = defaultdict(list)
    for canonical, variants in seed.items():
        merged[canonical].extend(variants)
    for row in db_rows:
        if row["alias"] not in merged[row["canonical"]]:
            merged[row["canonical"]].append(row["alias"])
    return dict(merged)
