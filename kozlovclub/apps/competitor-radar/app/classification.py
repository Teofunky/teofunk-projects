from __future__ import annotations

import re

CATEGORY_KEYWORDS: dict[str, tuple[str, ...]] = {
    "фестиваль": ("фестивал", "festival", "фест", "марафон"),
    "премьера": ("премьер", "впервые", "новая программа", "новый проект", "дебют"),
    "привоз": (
        "из сша", "из франции", "из италии", "из германии", "из испании", "из кубы",
        "из бразилии", "из турции", "из великобритании", "international", "world tour",
        "зарубежн", "иностранн", "usa", "france", "italy", "germany", "spain", "cuba",
        "brazil", "turkey", "uk", "united kingdom", "аргентина", "сербия", "израиль",
    ),
    "трибьют": ("tribute", "трибьют", "посвящение", "памяти "),
    "джем": ("джем", "jam session", "jazz jam", " jam ", "jam:", "джем-сейшн", "джем-сэшн"),
    "бранч": ("бранч", "brunch"),
    "ночной формат": ("ночной", "night concert", "late night", "23:59", "полноч", "ночь джаза"),
    "бесплатный вход": ("свободный вход", "вход свободный", "free entry", "бесплатн", "от 0 ₽"),
    "шоу/кросс-жанр": ("stand-up", "стендап", "бурлеск", "спектакл", "шоу", "кабаре", "танцевальн", "ужин-лекц"),
    "при свечах": ("при свечах", "свеч"),
    "юбилей": ("юбиле", "летие", "годовщин", "birthday", "anniversary", "день рождения"),
    "благотворительность": ("благотвор", "charity", "в поддержку"),
    "коллаборация": ("коллаб", "совместн", " x ", "при участии", "специальный гость", "special guest"),
    "акция/скидка": ("скидк", "промокод", "акци", "розыгрыш", "подарок", "special offer", "%"),
    "интервью/медиа": ("интервью", "подкаст", "эфир", "премьера видео", "новое видео"),
    "партнёрство": ("партнер", "партнёр", "совместно с", "при поддержке"),
    "солд-аут": ("sold out", "солд-аут", "билеты закончились", "аншлаг"),
    "дополнительный концерт": ("дополнительный концерт", "добавили концерт", "вторая дата"),
    "релиз": ("релиз", "новый альбом", "новый сингл", "презентация альбома"),
    "инфоповод": ("открытие", "награ", "побед", "рекорд", "презентация", "объявил", "запускаем", "новая серия"),
}

GENRE_KEYWORDS: dict[str, tuple[str, ...]] = {
    "jazz": ("джаз", "jazz", "bebop", "бибоп", "swing", "свинг", "ragtime", "диксиленд"),
    "blues": ("блюз", "blues"),
    "soul/funk/r&b": ("соул", "soul", "фанк", "funk", "r&b", "rhythm and blues", "ритм-н-блюз"),
    "latin/world": ("latin", "латино", "bossa", "босса", "куб", "flamenco", "фламенко", "этно", "world music", "танго", "reggae", "регги"),
    "rock/tribute": ("рок", "rock", "tribute", "трибьют", "metal", "beatles", "pink floyd"),
    "classical/crossover": ("классик", "оркестр", "classical", "неокласс", "саундтрек", "струнн"),
    "pop/retro": ("поп", "ретро", "эстрад", "диско"),
    "theatre/show": ("стендап", "спектакль", "кабаре", "бурлеск", "мюзикл", "лекц"),
}


def normalize_text(text: str) -> str:
    value = (text or "").replace("\xa0", " ")
    return re.sub(r"\s+", " ", value).strip()


def classify(text: str) -> tuple[list[str], bool, list[str]]:
    value = f" {normalize_text(text).lower()} "
    categories = [name for name, keys in CATEGORY_KEYWORDS.items() if any(key in value for key in keys)]
    genres = [name for name, keys in GENRE_KEYWORDS.items() if any(key in value for key in keys)]
    is_import = "привоз" in categories
    return sorted(set(categories)), is_import, sorted(set(genres))


def extract_price(text: str) -> int | None:
    values: list[int] = []
    for raw in re.findall(r"(?:от\s*)?(\d[\d\s]{2,6})\s*(?:₽|руб(?:\.|лей|ля)?|р\.?)(?![а-я])", text.lower()):
        try:
            value = int(re.sub(r"\s+", "", raw))
        except ValueError:
            continue
        if 100 <= value <= 100_000:
            values.append(value)
    return min(values) if values else None
