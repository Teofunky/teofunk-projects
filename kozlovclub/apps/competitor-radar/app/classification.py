from __future__ import annotations

import re

CATEGORY_KEYWORDS: dict[str, tuple[str, ...]] = {
    "фестиваль": ("фестивал", "festival", "фест", "марафон"),
    "премьера": ("премьер", "впервые", "новая программа", "новый проект", "дебют"),
    "привоз": (
        "из сша", "из франции", "из италии", "из германии", "из испании", "из кубы",
        "из бразилии", "из турции", "из великобритании", "international", "world tour",
        "зарубежн", "иностранн", "usa", "france", "italy", "germany", "spain", "cuba",
        "brazil", "turkey", "uk", "united kingdom",
    ),
    "трибьют": ("tribute", "трибьют", "посвящение", "памяти ", "the beatles", "pink floyd", "deep purple"),
    "джем": ("джем", "jam session", "jazz jam", " jam ", "jam:", "джем-сейшн", "джем-сэшн"),
    "бранч": ("бранч", "brunch"),
    "ночной формат": ("ночной", "night concert", "late night", "23:59", "полноч"),
    "бесплатный вход": ("свободный вход", "вход свободный", "free entry", "бесплатн"),
    "шоу/кросс-жанр": ("stand-up", "стендап", "бурлеск", "спектакл", "шоу", "кабаре", "танцевальн"),
    "при свечах": ("при свечах", "свеч"),
    "юбилей": ("юбиле", "летие", "годовщин", "birthday", "anniversary"),
    "благотворительность": ("благотвор", "charity", "в поддержку"),
    "коллаборация": ("коллаб", "совместн", "x ", "при участии", "специальный гость"),
    "акция/скидка": ("скидк", "промокод", "акци", "розыгрыш", "подарок", "special offer", "%"),
    "инфоповод": ("открытие", "награ", "побед", "рекорд", "презентация", "релиз", "интервью", "объявил"),
}

GENRE_KEYWORDS: dict[str, tuple[str, ...]] = {
    "jazz": ("джаз", "jazz", "bebop", "swing", "свинг"),
    "blues": ("блюз", "blues"),
    "soul/funk/r&b": ("соул", "soul", "фанк", "funk", "r&b", "rhythm and blues"),
    "latin/world": ("latin", "латино", "bossa", "босса", "куб", "flamenco", "фламенко", "этно", "world music"),
    "rock/tribute": ("рок", "rock", "tribute", "трибьют", "metallica", "pink floyd", "beatles"),
    "classical/crossover": ("классик", "оркестр", "classical", "неокласс", "саундтрек"),
}


def normalize_text(text: str) -> str:
    return re.sub(r"\s+", " ", text or "").strip()


def classify(text: str) -> tuple[list[str], bool, list[str]]:
    value = f" {normalize_text(text).lower()} "
    categories = [name for name, keys in CATEGORY_KEYWORDS.items() if any(key in value for key in keys)]
    genres = [name for name, keys in GENRE_KEYWORDS.items() if any(key in value for key in keys)]
    is_import = "привоз" in categories
    return sorted(set(categories)), is_import, sorted(set(genres))


def extract_price(text: str) -> int | None:
    values: list[int] = []
    for raw in re.findall(r"(?:от\s*)?(\d[\d\s]{2,6})\s*(?:₽|руб(?:\.|лей|ля)?|р\.)", text.lower()):
        try:
            value = int(re.sub(r"\s+", "", raw))
        except ValueError:
            continue
        if 100 <= value <= 100_000:
            values.append(value)
    return min(values) if values else None
