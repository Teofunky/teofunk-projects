#!/usr/bin/env python3
"""Календарь памятных дат для «Ежедневной Джазеты».

  build  собирает базу из Wikidata и категорий ру-Википедии -> data/people.json, data/review.csv
  pick   отбирает записи на дату -> JSON рубрики и отдельной карточки для соцсетей

Принцип: даты и имена берутся только из базы, текст строк собирается шаблоном.
Модель ничего не придумывает и может добавить разве что пояснение, которое потом проверяется.
"""
import argparse
import csv
import datetime as dt
import json
import math
import re
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent
DATA = ROOT / "data"
UA = "JazzetaCalendar/0.1 (https://kozlovclub.ru/gazeta/)"
WDQS = "https://query.wikidata.org/sparql"
RUWIKI = "https://ru.wikipedia.org/w/api.php"
FOOTER = "Ежедневная Джазета · kozlovclub.ru/gazeta/"

# Джазовые профессии Wikidata: подклассы Q15981151 «jazz musician» (проверено 07.11.2026).
# По ним отбираются люди в базу.
JAZZ_OCC = ["Q15981151", "Q61947380", "Q64355059", "Q61947786", "Q6168364", "Q66314404",
            "Q98822525", "Q64353861", "Q64354204", "Q61946839", "Q63565256", "Q64354837",
            "Q66357787", "Q66712090", "Q81727892", "Q96625642", "Q2391699", "Q116938279",
            "Q136158735"]
# Подписи: джазовая и общая профессия Wikidata -> (муж., жен.). Порядок = приоритет.
# Общие профессии (pianist и т.п.) только уточняют подпись, в базу по ним не отбирают.
OCC_WORDS = [
    (("Q1863064",), "аккордеонист", "аккордеонистка"),
    (("Q61947380", "Q486748"), "пианист", "пианистка"),
    (("Q64355059", "Q12800682"), "саксофонист", "саксофонистка"),
    (("Q61947786", "Q12377274"), "трубач", "трубач"),
    (("Q66712090", "Q24574678"), "корнетист", "корнетистка"),
    (("Q6168364", "Q855091"), "гитарист", "гитаристка"),
    (("Q64353861", "Q1214796"), "контрабасист", "контрабасистка"),
    (("Q64354204",), "бас-гитарист", "бас-гитаристка"),
    (("Q584301",), "басист", "басистка"),
    (("Q61946839", "Q386854"), "барабанщик", "барабанщица"),
    (("Q63565256", "Q544972"), "тромбонист", "тромбонистка"),
    (("Q64354837", "Q118865"), "кларнетист", "кларнетистка"),
    (("Q12902372",), "флейтист", "флейтистка"),
    (("Q66357787", "Q765778"), "органист", "органистка"),
    (("Q81727892", "Q21855974"), "вибрафонист", "вибрафонистка"),
    (("Q96625642",), "арфист", "арфистка"),
    (("Q2391699", "Q1259917"), "скрипач", "скрипачка"),
    (("Q66314404", "Q177220"), "певец", "певица"),
    (("Q98822525",), "скэт-вокалист", "скэт-вокалистка"),
    (("Q116938279", "Q36834"), "композитор", "композитор"),
    (("Q1643514",), "аранжировщик", "аранжировщица"),
    (("Q806349",), "руководитель оркестра", "руководитель оркестра"),
]
ALL_OCC = [q for qs, _, _ in OCC_WORDS for q in qs]
GENERAL_OCC = [q for q in ALL_OCC if q not in JAZZ_OCC]
GENERIC_OCC = "джазовый музыкант"
UNKNOWN_GENDER = {"певец": "вокалист", "скэт-вокалист": "скэт-вокалист"}
FEMALE = {"Q6581072", "Q1052281"}
RU_COUNTRIES = {"Q159", "Q15180", "Q34266"}  # Россия, СССР, Российская империя
JULIAN_MODEL = "Q1985786"
RU_CATEGORIES = [
    "Джазмены России", "Джазмены СССР", "Джазовые трубачи России",
    "Джазовые пианисты России", "Джазовые композиторы России",
]
MIN_LINKS_WORLD = 12  # порог известности: число статей о человеке в разных Википедиях
MONTHS = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля",
          "августа", "сентября", "октября", "ноября", "декабря"]


# ---------------------------------------------------------------- запросы

def _vals(qids):
    return " ".join("wd:" + q for q in qids)


def people_query(qids=None, extra=""):
    """SPARQL: даты рождения/смерти с точностью до дня, без устаревших (deprecated) утверждений.
    qids=None: все люди с джазовой профессией (известные или из России/СССР).
    qids=[...]: конкретные люди (список из категорий ру-Википедии)."""
    if qids:
        head = "VALUES ?p { %s } OPTIONAL { VALUES ?o { %s } ?p wdt:P106 ?o }" % (
            _vals(qids), _vals(JAZZ_OCC))
        links_filter = ""
    else:
        head = "VALUES ?o { %s } ?p wdt:P106 ?o ." % _vals(JAZZ_OCC)
        links_filter = ("FILTER(?l >= %d || EXISTS { ?p wdt:P27 ?rc . VALUES ?rc { %s } })"
                        % (MIN_LINKS_WORLD, " ".join("wd:" + q for q in RU_COUNTRIES)))
    date = ("[ wikibase:timeValue ?d ; wikibase:timePrecision 11 ; "
            "wikibase:timeCalendarModel ?cal ] ; wikibase:rank ?rank .")
    return f"""SELECT ?p ?ru ?en ?g ?o ?k ?d ?cal ?rank ?l
(GROUP_CONCAT(DISTINCT ?cq; separator=",") AS ?cits)
(GROUP_CONCAT(DISTINCT ?o2q; separator=",") AS ?occ2) WHERE {{
 {head}
 ?p wikibase:sitelinks ?l . {links_filter}
 {{ ?p p:P569 ?st . ?st psv:P569 {date} BIND("b" AS ?k) }}
 UNION {{ ?p p:P570 ?st . ?st psv:P570 {date} BIND("d" AS ?k) }}
 FILTER(?rank != wikibase:DeprecatedRank) {extra}
 OPTIONAL {{ ?p wdt:P21 ?g }}
 OPTIONAL {{ ?p rdfs:label ?ru FILTER(lang(?ru) = "ru") }}
 OPTIONAL {{ ?p rdfs:label ?en FILTER(lang(?en) = "en") }}
 OPTIONAL {{ ?p wdt:P27 ?c . BIND(STRAFTER(STR(?c), "entity/") AS ?cq) }}
 OPTIONAL {{ VALUES ?o2 {{ {_vals(GENERAL_OCC)} }} ?p wdt:P106 ?o2 . BIND(STRAFTER(STR(?o2), "entity/") AS ?o2q) }}
}} GROUP BY ?p ?ru ?en ?g ?o ?k ?d ?cal ?rank ?l"""


class _Http:
    """HTTP без сторонних библиотек (на Mac с Python из Homebrew pip ставить пакеты не даёт)."""

    def __init__(self):
        import ssl
        self.ctx = ssl.create_default_context()  # системные сертификаты (у Python из Homebrew они есть)
        if not self.ctx.cert_store_stats().get("x509_ca"):
            try:  # Python с python.org без «Install Certificates»: берём certifi, если он установлен
                import certifi
                self.ctx = ssl.create_default_context(cafile=certifi.where())
            except ImportError:
                pass

    def _json(self, req, timeout):
        import urllib.error
        import urllib.request
        for attempt in range(5):
            try:
                with urllib.request.urlopen(req, timeout=timeout, context=self.ctx) as r:
                    return json.loads(r.read().decode("utf-8"))
            except urllib.error.HTTPError as e:
                if e.code in (429, 500, 502, 503, 504) and attempt < 4:
                    time.sleep(int(e.headers.get("Retry-After") or 10 * (attempt + 1)))
                    continue
                raise SystemExit(f"{req.full_url[:60]}…: ошибка {e.code}")
            except urllib.error.URLError as e:
                if "CERTIFICATE" in str(e.reason).upper():
                    raise SystemExit("Python не доверяет сертификатам сайтов. Выполните в Терминале: "
                                     "brew reinstall ca-certificates openssl@3 — и запустите команду снова.")
                if attempt < 4:
                    time.sleep(5 * (attempt + 1))
                    continue
                raise SystemExit(f"нет связи с {req.full_url[:40]}…: {e.reason}")
        raise SystemExit("сервер не ответил после 5 попыток")

    def get(self, url, params, timeout=60):
        import urllib.parse
        import urllib.request
        req = urllib.request.Request(url + "?" + urllib.parse.urlencode(params), headers={"User-Agent": UA})
        return self._json(req, timeout)

    def post(self, url, data, headers, timeout=120):
        import urllib.parse
        import urllib.request
        req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode("utf-8"),
                                     headers={"User-Agent": UA, **headers}, method="POST")
        return self._json(req, timeout)


def _session():
    return _Http()


def sparql(session, query):
    j = session.post(WDQS, {"query": query}, {"Accept": "application/sparql-results+json"})
    return [{k: v["value"] for k, v in b.items()} for b in j["results"]["bindings"]]


def ruwiki_category_people(session):
    """Категории ру-Википедии о российском джазе -> {QID: заголовок статьи}."""
    titles = set()
    for cat in RU_CATEGORIES:
        params = {"action": "query", "list": "categorymembers", "cmtitle": "Категория:" + cat,
                  "cmnamespace": 0, "cmlimit": 500, "format": "json"}
        while True:
            j = session.get(RUWIKI, params)
            titles.update(m["title"] for m in j.get("query", {}).get("categorymembers", []))
            if "continue" not in j:
                break
            params.update(j["continue"])
    titles, out = sorted(titles), {}
    for i in range(0, len(titles), 50):
        j = session.get(RUWIKI, {"action": "query", "prop": "pageprops",
                                 "ppprop": "wikibase_item", "format": "json",
                                 "titles": "|".join(titles[i:i + 50])})
        for page in j["query"]["pages"].values():
            q = page.get("pageprops", {}).get("wikibase_item")
            if q:
                out[q] = page["title"]
    return out


# ---------------------------------------------------------------- нормализация

PATRONYMIC = re.compile(r"(вич|вна|ична|оглы|кызы)$")


def ru_name(label):
    """«Илугдин, Дмитрий Григорьевич» -> «Дмитрий Илугдин»; латиница -> None."""
    if not label or not re.search(r"[А-Яа-яЁё]", label):
        return None
    label = re.sub(r"\s*\(.*?\)", "", label).strip()
    label = re.sub(r"\s\S+\s(кызы|оглы)(?=\s\S)", "", label)  # «Азиза Вагиф кызы Мустафа-заде»
    if "," in label:
        last, rest = (s.strip() for s in label.split(",", 1))
        words = [w for w in rest.split() if not PATRONYMIC.search(w)] + [last]
    else:
        words = label.split()
        if len(words) == 3 and PATRONYMIC.search(words[1]):
            words = [words[0], words[2]]
    return " ".join(words)


def _q(uri):
    return uri.rsplit("/", 1)[-1] if uri else None


def normalize(rows, people, source, ru_titles=None):
    ru_titles = ru_titles or {}
    for b in rows:
        q = _q(b["p"])
        p = people.setdefault(q, {"qid": q, "label_ru": None, "label_en": None, "gender": None,
                                  "occ": set(), "links": 0, "countries": set(), "sources": set(),
                                  "_raw": {"b": set(), "d": set()}})
        p["label_ru"] = p["label_ru"] or b.get("ru") or ru_titles.get(q)
        p["label_en"] = p["label_en"] or b.get("en")
        p["gender"] = p["gender"] or _q(b.get("g"))
        if b.get("o"):
            p["occ"].add(_q(b["o"]))
        p["occ"].update(o for o in (b.get("occ2") or "").split(",") if o)
        p["links"] = max(p["links"], int(b.get("l") or 0))
        p["countries"].update(c for c in (b.get("cits") or "").split(",") if c)
        p["sources"].add(source)
        p["_raw"][b["k"]].add((b["d"][:10], _q(b["rank"]), _q(b["cal"]) == JULIAN_MODEL))
    return people


def resolve(people):
    """Выбор даты: утверждение с рангом preferred, иначе все обычные. Несколько разных -> спор."""
    out, review = [], []
    for p in people.values():
        rec = {k: p[k] for k in ("qid", "label_ru", "label_en", "gender", "links")}
        rec["name_ru"] = ru_name(p["label_ru"])
        rec["occ"] = [o for o in ALL_OCC if o in p["occ"]]
        rec["countries"] = sorted(p["countries"])
        rec["russian"] = bool(p["sources"] & {"ruwiki", "list"}) or bool(p["countries"] & RU_COUNTRIES)
        rec["sources"] = sorted(p["sources"])
        for k, field in (("b", "birth"), ("d", "death")):
            raw = p["_raw"][k]
            pref = {r for r in raw if r[1] == "PreferredRank"}
            dates = sorted({r[0] for r in (pref or raw)})
            rec[field] = dates[0] if len(dates) == 1 else None
            rec[field + "_conflict"] = dates if len(dates) > 1 else []
            rec[field + "_old_style"] = any(r[2] for r in raw)
            if len(dates) > 1:
                review.append((rec, f"спорная дата {'рождения' if k == 'b' else 'смерти'}",
                               ", ".join(dates)))
            elif rec[field] and rec[field + "_old_style"]:
                review.append((rec, "старый стиль в источнике",
                               f"{rec[field]} (уже пересчитано на новый стиль)"))
        if not rec["name_ru"]:
            review.append((rec, "нет русского имени", rec["label_en"] or ""))
        out.append(rec)
    out.sort(key=lambda r: -r["links"])
    return out, review


def write_build(people_list, review):
    DATA.mkdir(exist_ok=True)
    (DATA / "people.json").write_text(json.dumps(people_list, ensure_ascii=False, indent=1),
                                      encoding="utf-8")
    with open(DATA / "review.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["qid", "имя", "проблема", "детали", "статей", "ссылка"])
        for rec, issue, detail in sorted(review, key=lambda x: -x[0]["links"]):
            w.writerow([rec["qid"], rec["name_ru"] or rec["label_en"], issue, detail,
                        rec["links"], "https://www.wikidata.org/wiki/" + rec["qid"]])
    days = {r[f][5:] for r in people_list for f in ("birth", "death") if r[f]}
    print(f"людей: {len(people_list)}, из них российских: "
          f"{sum(r['russian'] for r in people_list)}, дней с датами: {len(days)}/366, "
          f"на проверку: {len(review)} -> data/review.csv")


def cmd_build(args):
    if args.from_rows:  # офлайн: строки, уже полученные запросами people_query()
        src = json.loads(Path(args.from_rows).read_text(encoding="utf-8"))
        people = normalize(src["occupation"], {}, "occupation")
        normalize(src["ruwiki"], people, "ruwiki", src.get("ru_titles"))
        normalize(src.get("list", []), people, "list")
    else:
        s = _session()
        people = {}
        for month in range(1, 13):  # по месяцу на запрос: у Wikidata лимит 60 секунд
            rows = sparql(s, people_query(extra=f"FILTER(MONTH(?d) = {month})"))
            normalize(rows, people, "occupation")
            print(f"месяц {month}: {len(rows)} строк", file=sys.stderr)
        ru_titles = ruwiki_category_people(s)
        qids = sorted(ru_titles)
        extra = load_json(ROOT / "extra_people.json", {})  # свой список: кого нет в категориях
        extra_qids = sorted(q for q in extra if not q.startswith("_"))
        if extra_qids:
            normalize(sparql(s, people_query(extra_qids)), people, "list", extra)
        for i in range(0, len(qids), 200):
            normalize(sparql(s, people_query(qids[i:i + 200])), people, "ruwiki", ru_titles)
    write_build(*resolve(people))


# ---------------------------------------------------------------- отбор на день

def plural_years(n):
    if n % 10 == 1 and n % 100 != 11:
        return "год"
    if 2 <= n % 10 <= 4 and not 12 <= n % 100 <= 14:
        return "года"
    return "лет"


def round_bonus(n):
    for step, bonus in ((100, 100), (50, 60), (25, 35), (10, 15), (5, 5)):
        if n % step == 0:
            return bonus
    return 0


def occupation_ru(p):
    female = p["gender"] in FEMALE
    words = []
    # сначала джазовая профессия («jazz singer»), затем общие («pianist»)
    jazz_first = sorted(OCC_WORDS, key=lambda w: not any(q in p["occ"] and q in JAZZ_OCC
                                                         for q in w[0]))
    for qids, m, f in jazz_first:
        if any(q in p["occ"] for q in qids):
            w = f if female else m
            if p["gender"] is None:
                w = UNKNOWN_GENDER.get(m, m)
            if w not in words:
                words.append(w)
    return " и ".join(words[:2]) if words else GENERIC_OCC


def line_for(kind, n, name, occ):
    """Без глаголов с родом и без склонения имён: строка верна для любого человека."""
    what = "со дня рождения" if kind == "birth" else "со дня смерти"
    head = "Год" if n == 1 else f"{n} {plural_years(n)}"
    return f"{head} {what}: {name}, {occ}"


def load_json(path, default):
    path = Path(path)
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else default


def candidates(people, overrides, day):
    md = day.strftime("%m-%d")
    leap_extra = md == "02-28" and not (day.year % 4 == 0 and (day.year % 100 or day.year % 400 == 0))
    out, skipped = [], []
    for p in people:
        o = overrides.get(p["qid"], {})
        if o.get("exclude"):
            continue
        p = {**p, **{k: v for k, v in o.items() if k in ("name_ru", "birth", "death", "gender")}}
        for kind in ("birth", "death"):
            date = p.get(kind)
            if not date or not (date[5:] == md or (leap_extra and date[5:] == "02-29")):
                continue
            if p.get(kind + "_conflict") and kind not in o:
                skipped.append((p, "спорная дата"))
                continue
            n = day.year - int(date[:4])
            if n < 1:
                continue
            if not p.get("name_ru"):
                skipped.append((p, "нет русского имени"))
                continue
            occ = o.get("occupation_ru") or occupation_ru(p)
            out.append({
                "kind": kind, "year": int(date[:4]), "years_ago": n, "round": round_bonus(n) >= 15,
                "name_ru": p["name_ru"], "occupation_ru": occ, "russian": p["russian"],
                "line": line_for(kind, n, p["name_ru"], occ),
                "qid": p["qid"], "source": "https://www.wikidata.org/wiki/" + p["qid"],
                "old_style": p.get(kind + "_old_style", False),
                "score": round(10 * math.log2(1 + p["links"]) + round_bonus(n), 1),
                "priority": bool(o.get("priority")),
            })
    return out, skipped


def pick_day(people, overrides, manual, day, n=4, max_russian=2):
    md = day.strftime("%m-%d")
    picked, manual_cands = [], []
    for m in manual:  # ручной слой: даты клуба, некрологи jazz.ru, события; только проверенные
        ago = day.year - m["year"]
        if m.get("date") != md or not m.get("verified") or ago < 1:
            continue
        if m.get("text"):  # событие: «{n} {years} назад ...»
            line = m["text"].format(n=ago, years=plural_years(ago))
        else:
            line = line_for(m["kind"], ago, m["name_ru"], m["occupation_ru"])
        item = {"kind": m["kind"], "year": m["year"], "years_ago": ago,
                "round": round_bonus(ago) >= 15, "line": line,
                "name_ru": m.get("name_ru", ""), "occupation_ru": m.get("occupation_ru", ""),
                "russian": m.get("russian", False), "club": m.get("club", False),
                "qid": m.get("qid"), "source": m.get("source"), "priority": bool(m.get("priority"))}
        if item["club"]:  # даты клуба всегда первыми
            picked.append({**item, "score": 1000})
        else:  # остальные на общих основаниях, как малоизвестные по Wikidata (1 статья)
            manual_cands.append({**item, "score": round(10 * math.log2(2) + round_bonus(ago), 1)})
    manual_qids = {m.get("qid") for m in manual if m.get("qid")}
    overrides = {**overrides, **{q: {**overrides.get(q, {}), "exclude": True} for q in manual_qids}}
    cands, skipped = candidates(people, overrides, day)
    cands = sorted(cands + manual_cands, key=lambda c: -c["score"])
    # «Приоритет» (overrides / manual): люди, которых редакция назвала сама, идут раньше прочих
    # и не упираются в лимит российских записей.
    for c in [c for c in cands if c.get("priority")]:
        if len(picked) < n:
            picked.append(c)
    ru = [c for c in cands if c["russian"]]
    if ru and not any(p["russian"] for p in picked):
        picked.append(ru[0])
    for c in cands:
        if len(picked) >= n:
            break
        if c in picked or (c["russian"] and sum(p["russian"] for p in picked) >= max_russian):
            continue
        picked.append(c)
    picked = picked[:n]
    picked.sort(key=lambda c: (not c.get("club"), c["year"]))
    return picked, skipped


def card_for(day, items):
    label = f"{day.day} {MONTHS[day.month - 1]}"
    lines = [{"year": i["year"], "text": i["line"]} for i in items]
    tg = "\n".join([f"ЭТОТ ДЕНЬ В ДЖАЗЕ · {label}", ""] +
                   [f"{l['year']} · {l['text']}" for l in lines] + ["", FOOTER])
    return {"type": "calendar", "title": "Этот день в джазе", "date_label": label,
            "items": lines, "footer": FOOTER, "telegram_text": tg}


def cmd_pick(args):
    people = load_json(args.people, None)
    if people is None:
        sys.exit(f"нет базы {args.people}: сначала запустите build")
    overrides = load_json(ROOT / "overrides.json", {})
    manual = [m for f in sorted(ROOT.glob("manual*.json")) for m in load_json(f, [])]
    start = dt.date.fromisoformat(args.date) if args.date else dt.date.today()
    result = []
    for i in range(args.days):
        day = start + dt.timedelta(days=i)
        items, skipped = pick_day(people, overrides, manual, day, n=args.n)
        result.append({"date": day.isoformat(),
                       "calendar": {"title": "Этот день в джазе", "items": items},
                       "card": card_for(day, items),
                       "skipped": [{"qid": p["qid"], "name": p.get("name_ru") or p.get("label_en"),
                                    "why": why} for p, why in skipped]})
    out = result[0] if args.days == 1 else result
    text = json.dumps(out, ensure_ascii=False, indent=1)
    if args.out:
        Path(args.out).write_text(text, encoding="utf-8")
    if args.preview:
        for r in result:
            print(r["card"]["telegram_text"], end="\n")
            for s in r["skipped"]:
                print(f"  [пропущено: {s['name']} ({s['qid']}), {s['why']}]")
            print("-" * 40)
    elif not args.out:
        print(text)


def cmd_export(args):
    """calendar.json для плагина газеты (формат es-calendar-1): готовый отбор на каждый день диапазона."""
    people = load_json(args.people, None)
    if people is None:
        sys.exit(f"нет базы {args.people}: сначала запустите build")
    overrides = load_json(ROOT / "overrides.json", {})
    manual = [m for f in sorted(ROOT.glob("manual*.json")) for m in load_json(f, [])]
    start = dt.date.fromisoformat(args.date_from)
    end = dt.date.fromisoformat(args.date_to)
    if end < start or (end - start).days > 1100:
        sys.exit("диапазон: от --from до --to, не больше 1100 дней")
    days, empty = {}, 0
    day = start
    while day <= end:
        items, _ = pick_day(people, overrides, manual, day, n=args.n)
        rows = []
        for it in items:
            if it["kind"] not in ("birth", "death") or not it.get("name_ru"):
                continue  # события с произвольным текстом плагин пока не принимает
            rows.append({
                "kind": it["kind"], "year": it["year"], "age": it["years_ago"],
                "name": it["name_ru"], "occupation": it.get("occupation_ru") or "",
                "round": bool(it.get("round")), "russian": bool(it.get("russian")),
                "club": bool(it.get("club")), "source": it.get("source") or "",
            })
        if rows:
            days[day.isoformat()] = rows
        else:
            empty += 1
        day += dt.timedelta(days=1)
    out = {"format": "es-calendar-1",
           "generated_at": dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
           "from": start.isoformat(), "to": end.isoformat(), "days": days}
    Path(args.out).write_text(json.dumps(out, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    size = Path(args.out).stat().st_size
    print(f"{args.out}: {len(days)} дней с датами, {empty} без дат, {size // 1024} КБ")


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    sub = ap.add_subparsers(dest="cmd", required=True)
    b = sub.add_parser("build", help="собрать базу из Wikidata и ру-Википедии")
    b.add_argument("--from-rows", help="офлайн-сборка из сохранённых строк запросов")
    b.set_defaults(func=cmd_build)
    p = sub.add_parser("pick", help="отобрать даты на день")
    p.add_argument("--date", help="ГГГГ-ММ-ДД, по умолчанию сегодня")
    p.add_argument("--days", type=int, default=1, help="сколько дней вперёд (для проверки недели)")
    p.add_argument("-n", type=int, default=4, help="записей в рубрике")
    p.add_argument("--people", default=str(DATA / "people.json"))
    p.add_argument("--out", help="записать JSON в файл")
    p.add_argument("--preview", action="store_true", help="показать карточки текстом")
    p.set_defaults(func=cmd_pick)
    today = dt.date.today()
    e = sub.add_parser("export", help="calendar.json для загрузки в газету (Газета → Календарь)")
    e.add_argument("--from", dest="date_from", default="2026-10-21", help="первый день, ГГГГ-ММ-ДД")
    e.add_argument("--to", dest="date_to", default=(today.replace(day=1) + dt.timedelta(days=430)).isoformat(),
                   help="последний день (по умолчанию примерно 14 месяцев вперёд)")
    e.add_argument("-n", type=int, default=4, help="записей в день")
    e.add_argument("--people", default=str(DATA / "people.json"))
    e.add_argument("--out", default=str(DATA / "calendar.json"))
    e.set_defaults(func=cmd_export)
    args = ap.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
