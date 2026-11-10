"""SmartFind engine.

1. Claude (Haiku) превращает запрос на обычном языке в план поиска.
2. Spotlight (mdfind) ищет файлы по плану: имя, текст внутри, что на фото, даты, тип.
3. Claude переранжирует найденное (имя, папка, дата, метки содержимого фото).
"""
import base64
import hashlib
import json
import os
import re
import shutil
import ssl
import subprocess
import tempfile
import threading
import time
import unicodedata
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from datetime import date, datetime

APP_NAME = "SmartFind"
HOME = os.path.expanduser("~")
SUPPORT = os.path.join(HOME, "Library", "Application Support", APP_NAME)
CONFIG_PATH = os.path.join(SUPPORT, "config.json")
THUMBS = os.path.join(SUPPORT, "thumbs")
LOG_PATH = os.path.join(SUPPORT, "smartfind.log")
API_URL = "https://api.anthropic.com/v1/messages"
DEFAULT_MODEL = "claude-haiku-5-5"
KEYCHAIN_SERVICE = "SmartFind Anthropic API"
MAX_RESULTS = 60
RERANK_POOL = 40

IMAGE_EXT = {"jpg", "jpeg", "png", "heic", "heif", "gif", "tif", "tiff", "webp", "bmp",
             "raw", "dng", "cr2", "cr3", "nef", "arw", "orf", "rw2", "psd", "svg"}

KIND_UTIS = {
    "image": ["public.image"],
    "video": ["public.movie"],
    "audio": ["public.audio"],
    "pdf": ["com.adobe.pdf"],
    "document": ["org.openxmlformats.wordprocessingml.document", "com.microsoft.word.doc",
                 "com.apple.iwork.pages.sffpages", "com.apple.iwork.pages.pages",
                 "public.rtf", "org.oasis-open.opendocument.text"],
    "spreadsheet": ["public.spreadsheet", "org.openxmlformats.spreadsheetml.sheet",
                    "com.microsoft.excel.xls", "com.apple.iwork.numbers.sffnumbers",
                    "public.comma-separated-values-text"],
    "presentation": ["public.presentation", "org.openxmlformats.presentationml.presentation",
                     "com.apple.iwork.keynote.sffkey"],
    "text": ["public.plain-text"],
    "code": ["public.source-code", "public.script"],
    "archive": ["public.archive", "public.zip-archive"],
    "folder": ["public.folder"],
}
KIND_RU = {"image": "фото", "video": "видео", "audio": "аудио", "pdf": "PDF",
           "document": "документ", "spreadsheet": "таблица", "presentation": "презентация",
           "text": "текст", "code": "код", "archive": "архив", "folder": "папка", "app": "приложение"}
SOURCE_SCORE = {"app": 1000, "strict": 100, "no_date": 60, "filters": 40, "interpret": 20}
APP_DIRS = ["/Applications", os.path.join(HOME, "Applications"), "/System/Applications",
            "/System/Applications/Utilities", "/Applications/Utilities"]
WEEKDAYS_RU = ["понедельник", "вторник", "среда", "четверг", "пятница", "суббота", "воскресенье"]

_sessions = {}
_lock = threading.Lock()
_apps_cache = {"t": 0.0, "apps": {}}
_key_cache = {"key": None}


# ---------------------------------------------------------------- утилиты

def nfc(s):
    """macOS хранит имена в NFD (й = и + бреве); для сравнения приводим к NFC."""
    return unicodedata.normalize("NFC", s or "")


def log(*parts):
    try:
        os.makedirs(SUPPORT, exist_ok=True)
        with open(LOG_PATH, "a", encoding="utf-8") as f:
            f.write(datetime.now().strftime("%Y-%m-%d %H:%M:%S ") + " ".join(str(p) for p in parts) + "\n")
    except OSError:
        pass


def load_config():
    try:
        with open(CONFIG_PATH, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def model():
    return load_config().get("model") or DEFAULT_MODEL


def human_size(n):
    for unit in ("Б", "КБ", "МБ", "ГБ"):
        if n < 1024 or unit == "ГБ":
            return f"{n:.0f} {unit}" if unit == "Б" else f"{n:.1f} {unit}"
        n /= 1024.0
    return ""


def pretty_dir(d):
    d = nfc(d)
    return "~" + d[len(nfc(HOME)):] if d.startswith(nfc(HOME)) else d


def is_junk(p):
    if "/Library/" in p and "/Library/Mobile Documents/" not in p:
        return True
    for part in ("/.Trash/", ".app/", ".photoslibrary/", "/node_modules/", "/.git/",
                 "/site-packages/", "/__pycache__/", ".bundle/", ".framework/"):
        if part in p:
            return True
    rel = p[len(HOME):] if p.startswith(HOME) else p
    return any(seg.startswith(".") for seg in rel.split("/") if seg)


# ---------------------------------------------------------------- ключ API

def get_api_key():
    env = os.environ.get("ANTHROPIC_API_KEY", "").strip()
    if env:
        return env
    if _key_cache["key"]:
        return _key_cache["key"]
    try:
        r = subprocess.run(["security", "find-generic-password", "-s", KEYCHAIN_SERVICE, "-w"],
                           capture_output=True, text=True, timeout=5)
    except (OSError, subprocess.TimeoutExpired):
        return None
    if r.returncode != 0:
        return None
    _key_cache["key"] = r.stdout.strip() or None
    return _key_cache["key"]


def set_api_key(key):
    key = key.strip()
    subprocess.run(["security", "add-generic-password", "-U", "-s", KEYCHAIN_SERVICE,
                    "-a", os.environ.get("USER", "user"), "-w", key],
                   check=True, capture_output=True, timeout=5)
    _key_cache["key"] = key


# ---------------------------------------------------------------- Claude

def _ssl_ctx():
    try:
        import certifi
        return ssl.create_default_context(cafile=certifi.where())
    except Exception:
        return ssl.create_default_context()


def call_claude(payload, key, timeout=40):
    data = json.dumps(payload).encode("utf-8")
    headers = {"x-api-key": key, "anthropic-version": "2023-06-01", "content-type": "application/json"}
    for attempt in range(2):
        req = urllib.request.Request(API_URL, data=data, headers=headers, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=timeout, context=_ssl_ctx()) as r:
                return json.loads(r.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8", "replace")
            if e.code in (429, 500, 529) and attempt == 0:
                time.sleep(1.5)
                continue
            try:
                msg = json.loads(body)["error"]["message"]
            except (ValueError, KeyError, TypeError):
                msg = body[:300]
            if e.code == 401:
                _key_cache["key"] = None
                raise RuntimeError("Ключ API не подошёл (401). Введи ключ заново.")
            raise RuntimeError(f"Claude API {e.code}: {msg}")
        except urllib.error.URLError as e:
            raise RuntimeError(f"Нет связи с Claude API: {e.reason}")
    raise RuntimeError("Claude API не ответил")


def tool_input(resp, name):
    for block in resp.get("content", []):
        if block.get("type") == "tool_use" and block.get("name") == name:
            return block.get("input") or {}
    raise RuntimeError("Claude не вернул ответ в нужном формате")


PLAN_SYSTEM = """Ты планировщик поиска файлов на Mac пользователя. Запрос приходит на любом языке, часто разговорный и с опечатками. Преврати его в параметры поиска через Spotlight, вызвав search_plan.

Правила:
- intent: коротко по-русски, что ищем (до 10 слов).
- kinds: фото/фотка/картинка/скрин -> image; видео/ролик -> video; песня/трек/запись/минусовка -> audio; pdf -> pdf; договор/документ/ворд -> document и pdf; таблица/эксель -> spreadsheet; презентация -> presentation; программа/приложение/прога -> app; папка -> folder. Не ясно -> ["any"].
- name_terms: 2-10 коротких слов или основ слов, которые могут быть в имени файла. Давай русский, английский и транслит: концерт, concert, kontsert. Основы без окончаний: «афиш», «договор». Без расширений и без общих слов вроде «файл», «фото».
- content_terms: слова, которые могут быть внутри текста документа. Только если ищут документ по содержанию.
- image_labels: только для фото и картинок. Что изображено, отдельными словами на английском И на русском: guitar, гитара, stage, сцена, people, люди, dog, собака, beach, пляж, sky, небо, food, еда, document, документ, car, машина, night, ночь. 2-10 штук, от конкретного к общему.
- Даты: YYYY-MM-DD относительно сегодняшней даты. «Недавно» = последние 30 дней, «на днях» = последние 7. Фото и видео: date_field=created; документы: modified; «открывал», «запускал» = used. Дат нет -> не заполняй.
- apps: если ищут программу, перечисли точные имена из списка установленных, подходящие по смыслу (например «чем монтировать видео» -> iMovie, Final Cut Pro, DaVinci Resolve, если они есть в списке). Сначала самые подходящие.
- screenshot=true, если просят скриншот или снимок экрана.
- folders: только если пользователь явно назвал папку; бери полные пути из контекста, не выдумывай.
- extensions: только если назван формат (mp3, psd, docx).
- action: что хочет пользователь.
  search: найти файл или программу (по умолчанию).
  organize: разложить, разобрать, навести порядок, рассортировать файлы по папкам.
  large: что занимает место, большие файлы, освободить место на диске.
  duplicates: дубли, дубликаты, одинаковые файлы, копии.
  junk: мусор, хлам, установщики, недокачанные файлы, почистить.
  Для уборки folders = какие папки убирать, полными путями (рабочий стол: <дом>/Desktop, загрузки: <дом>/Downloads). Не названы -> не заполняй. Для large «больше N гигабайт» -> min_size_mb. Для duplicates kinds = тип файлов, если назван (фото -> image).
"""

PLAN_TOOL = {
    "name": "search_plan",
    "description": "План поиска файлов через Spotlight",
    "input_schema": {
        "type": "object",
        "properties": {
            "intent": {"type": "string"},
            "action": {"type": "string", "enum": ["search", "organize", "large", "duplicates", "junk"]},
            "kinds": {"type": "array", "items": {"type": "string", "enum": [
                "image", "video", "audio", "pdf", "document", "spreadsheet", "presentation",
                "text", "code", "archive", "folder", "app", "any"]}},
            "apps": {"type": "array", "items": {"type": "string"}},
            "name_terms": {"type": "array", "items": {"type": "string"}},
            "content_terms": {"type": "array", "items": {"type": "string"}},
            "image_labels": {"type": "array", "items": {"type": "string"}},
            "extensions": {"type": "array", "items": {"type": "string"}},
            "date_from": {"type": "string", "description": "YYYY-MM-DD"},
            "date_to": {"type": "string", "description": "YYYY-MM-DD"},
            "date_field": {"type": "string", "enum": ["modified", "created", "used"]},
            "min_size_mb": {"type": "number"},
            "max_size_mb": {"type": "number"},
            "folders": {"type": "array", "items": {"type": "string"}},
            "screenshot": {"type": "boolean"},
        },
        "required": ["intent", "kinds"],
    },
}

RANK_SYSTEM = """Ты ранжируешь результаты поиска файлов на Mac. Верни через rank_results номера кандидатов, которые подходят под запрос, от самого подходящего к менее подходящему. Учитывай имя, папку, дату, тип и метки «на фото» (их ставит автоматический классификатор macOS, он ошибается, но это сильный сигнал). Явно неподходящие не включай."""

RANK_TOOL = {
    "name": "rank_results",
    "description": "Номера подходящих результатов по убыванию релевантности",
    "input_schema": {
        "type": "object",
        "properties": {"order": {"type": "array", "items": {"type": "integer"}}},
        "required": ["order"],
    },
}


# ---------------------------------------------------------------- контекст для плана

def installed_apps():
    if _apps_cache["apps"] and time.time() - _apps_cache["t"] < 600:
        return _apps_cache["apps"]
    apps = {}
    for d in APP_DIRS:
        try:
            entries = os.listdir(d)
        except OSError:
            continue
        for e in entries:
            p = os.path.join(d, e)
            if e.endswith(".app"):
                apps.setdefault(nfc(e[:-4]), p)
            elif not e.startswith(".") and os.path.isdir(p):
                try:
                    for sub in os.listdir(p):
                        if sub.endswith(".app"):
                            apps.setdefault(nfc(sub[:-4]), os.path.join(p, sub))
                except OSError:
                    pass
    _apps_cache.update(t=time.time(), apps=apps)
    return apps


def folder_context():
    lines = []
    for base in ("Documents", "Desktop", "Downloads", "Pictures", "Movies", "Music"):
        p = os.path.join(HOME, base)
        try:
            subs = sorted(nfc(e) for e in os.listdir(p)
                          if not e.startswith(".") and os.path.isdir(os.path.join(p, e)))
        except OSError:
            continue
        if subs:
            lines.append(f"{p}: " + "; ".join(subs[:40]))
    return "\n".join(lines)


def make_plan(query, key):
    today = date.today()
    ctx = (f"Сегодня: {today.isoformat()}, {WEEKDAYS_RU[today.weekday()]}.\n"
           f"Домашняя папка: {HOME}\n"
           f"Папки пользователя:\n{folder_context() or '(нет данных)'}\n\n"
           f"Установленные приложения: {'; '.join(sorted(installed_apps()))}\n\n"
           f"Запрос: {query}")
    resp = call_claude({
        "model": model(), "max_tokens": 800, "system": PLAN_SYSTEM,
        "tools": [PLAN_TOOL], "tool_choice": {"type": "tool", "name": "search_plan"},
        "messages": [{"role": "user", "content": ctx}],
    }, key)
    return tool_input(resp, "search_plan")


# ---------------------------------------------------------------- запросы Spotlight

def _q(s):
    return re.sub(r'["\\*]', "", nfc(str(s))).strip()


def _or(parts):
    parts = [p for p in parts if p]
    if not parts:
        return ""
    return parts[0] if len(parts) == 1 else "(" + " || ".join(parts) + ")"


def _and(parts):
    return " && ".join(p for p in parts if p)


def _num(v):
    try:
        v = float(v)
        return v if v > 0 else None
    except (TypeError, ValueError):
        return None


def _date(v):
    return v if isinstance(v, str) and re.fullmatch(r"\d{4}-\d{2}-\d{2}", v) else None


def _terms(plan, field, limit):
    out = []
    for t in plan.get(field) or []:
        t = _q(t)
        if len(t) >= 2 and t not in out:
            out.append(t)
    return out[:limit]


def date_clause(plan):
    attr = {"created": "kMDItemContentCreationDate",
            "used": "kMDItemLastUsedDate"}.get(plan.get("date_field"), "kMDItemContentModificationDate")
    parts = []
    df, dt = _date(plan.get("date_from")), _date(plan.get("date_to"))
    if df:
        parts.append(f"{attr} >= $time.iso({df}T00:00:00Z)")
    if dt:
        parts.append(f"{attr} <= $time.iso({dt}T23:59:59Z)")
    return _and(parts)


def build_queries(plan):
    kinds = [k for k in plan.get("kinds") or [] if k in KIND_UTIS]
    kc = _or([f'kMDItemContentTypeTree == "{u}"' for k in kinds for u in KIND_UTIS[k]])
    ec = _or([f'kMDItemFSName == "*.{e.lstrip(".")}"c' for e in _terms(plan, "extensions", 6)])
    extra = []
    if plan.get("screenshot"):
        extra.append("kMDItemIsScreenCapture == 1")
    mn, mx = _num(plan.get("min_size_mb")), _num(plan.get("max_size_mb"))
    if mn:
        extra.append(f"kMDItemFSSize >= {int(mn * 1048576)}")
    if mx:
        extra.append(f"kMDItemFSSize <= {int(mx * 1048576)}")
    dc = date_clause(plan)
    tc = _or(
        [f'kMDItemFSName == "*{t}*"cd' for t in _terms(plan, "name_terms", 10)]
        + [f'kMDItemTextContent == "{t}*"cdw' for t in _terms(plan, "content_terms", 6)]
        + [f'(* == "{t}*"cdw || kMDItemTextContent == "{t}*"cdw)' for t in _terms(plan, "image_labels", 10)]
    )
    base = [kc, ec] + extra
    out = []
    if tc:
        out.append(("strict", _and(base + [dc, tc])))
        if dc:
            out.append(("no_date", _and(base + [tc])))
    filt = _and(base + [dc])
    if filt:
        out.append(("filters", filt))
    return out


def mdfind(query, roots, interpret=False, limit=4000, timeout=12):
    out = []
    for root in roots:
        cmd = ["mdfind", "-onlyin", root] + (["-interpret"] if interpret else []) + [query]
        try:
            proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        except OSError as e:
            log("mdfind failed", e)
            continue
        deadline = time.time() + timeout
        try:
            for line in proc.stdout:
                line = line.rstrip("\n")
                if line:
                    out.append(line)
                if len(out) >= limit or time.time() > deadline:
                    break
        finally:
            if proc.poll() is None:
                proc.kill()
            err = proc.stderr.read() if proc.stderr else ""
            proc.wait()
            if err.strip():
                log("mdfind stderr:", err.strip()[:300])
    return out


def search_roots(plan):
    roots = []
    for f in plan.get("folders") or []:
        p = os.path.expanduser(str(f))
        if os.path.isdir(p) and p not in roots:
            roots.append(p)
    return roots or load_config().get("roots") or [HOME]


def run_queries(plan, roots, raw_query):
    found = {}
    for label, q in build_queries(plan):
        if label == "filters" and found:
            break  # фильтры без слов нужны, только если по словам ничего нет
        log("mdfind", label, q)
        for p in mdfind(q, roots):
            if p not in found and not is_junk(p):
                found[p] = label
        if len(found) >= 25:
            break
    if not found:
        words = " ".join(_terms(plan, "name_terms", 3)) or raw_query
        log("mdfind interpret", words)
        for p in mdfind(words, roots, interpret=True):
            if p not in found and not is_junk(p):
                found[p] = "interpret"
    return found


def find_apps(plan):
    apps = installed_apps()
    picked = []
    for name in plan.get("apps") or []:
        name = nfc(name)
        if name in apps:
            picked.append(name)
            continue
        low = name.lower()
        picked += [n for n in apps if low and low in n.lower()]
    if "app" in (plan.get("kinds") or []):
        for t in plan.get("name_terms") or []:
            tl = nfc(t).lower()
            if len(tl) >= 3:
                picked += [n for n in apps if tl in n.lower()]
    out, seen = [], set()
    for n in picked:
        if n not in seen:
            seen.add(n)
            out.append(apps[n])
    return out[:20]


# ---------------------------------------------------------------- результаты

def make_item(path, source, plan):
    try:
        st = os.stat(path)
    except OSError:
        return None
    is_app = path.endswith(".app")
    is_dir = os.path.isdir(path) and not is_app
    name = nfc(os.path.basename(path.rstrip("/")))
    ext = os.path.splitext(name)[1].lower().lstrip(".")
    low = name.lower()
    score = SOURCE_SCORE.get(source, 10)
    for t in plan.get("name_terms") or []:
        t = nfc(t).lower().strip()
        if len(t) >= 2 and t in low:
            score += 25
    for t in plan.get("image_labels") or []:
        t = nfc(t).lower().strip()
        if len(t) >= 3 and t in low:
            score += 8
    age_days = max(0.0, (time.time() - st.st_mtime) / 86400)
    score += max(0.0, 20 - age_days / 30)
    if "/_На удаление/" in nfc(path):
        score -= 40
    return {
        "path": path,
        "name": name[:-4] if is_app else name,
        "dir": pretty_dir(os.path.dirname(path.rstrip("/"))),
        "date": datetime.fromtimestamp(st.st_mtime).strftime("%d.%m.%Y"),
        "size": "" if (is_dir or is_app) else human_size(st.st_size),
        "kind": "Приложение" if is_app else ("Папка" if is_dir else (ext.upper() or "Файл")),
        "is_app": is_app,
        "is_dir": is_dir,
        "is_image": ext in IMAGE_EXT,
        "score": round(score, 1),
    }


def plan_chips(plan):
    out = []
    kinds = [KIND_RU.get(k, k) for k in plan.get("kinds") or [] if k != "any"]
    if kinds:
        out.append("тип: " + ", ".join(kinds))
    if _date(plan.get("date_from")) or _date(plan.get("date_to")):
        out.append(f"даты: {plan.get('date_from') or '…'} – {plan.get('date_to') or '…'}")
    for field, label in (("name_terms", "имя"), ("image_labels", "на фото"), ("content_terms", "текст")):
        t = (plan.get(field) or [])[:5]
        if t:
            out.append(f"{label}: " + ", ".join(t))
    if plan.get("folders"):
        out.append("папка: " + ", ".join(pretty_dir(os.path.expanduser(f)) for f in plan["folders"]))
    if plan.get("screenshot"):
        out.append("скриншоты")
    return out


def search(query):
    key = get_api_key()
    if not key:
        raise RuntimeError("NO_KEY")
    t0 = time.time()
    plan = make_plan(query, key)
    t1 = time.time()
    log("query:", query, "| plan:", json.dumps(plan, ensure_ascii=False))
    sid = str(int(time.time() * 1000))

    if plan.get("action") in ("organize", "large", "duplicates", "junk"):
        import cleanup  # отложенный импорт: cleanup сам импортирует engine
        res = cleanup.propose(plan, query, key, sid)
        res.update(plan_ms=int((t1 - t0) * 1000), search_ms=int((time.time() - t1) * 1000))
        return res

    items, seen = [], set()
    for p in find_apps(plan):
        it = make_item(p, "app", plan)
        if it:
            seen.add(p)
            items.append(it)

    loose = False
    if (plan.get("kinds") or []) != ["app"] or not items:
        files = []
        found = run_queries(plan, search_roots(plan), query)
        has_terms = any(plan.get(f) for f in ("name_terms", "content_terms", "image_labels"))
        for p, src in found.items():
            if p in seen:
                continue
            it = make_item(p, src, plan)
            if it:
                files.append(it)
                if has_terms and src in ("filters", "interpret"):
                    loose = True
        files.sort(key=lambda x: -x["score"])
        items += files[:MAX_RESULTS]

    remember(sid, {"query": query, "items": items})
    return {"id": sid, "mode": "search", "intent": plan.get("intent") or "", "chips": plan_chips(plan),
            "items": items, "loose": loose,
            "plan_ms": int((t1 - t0) * 1000), "search_ms": int((time.time() - t1) * 1000)}


def remember(sid, data):
    with _lock:
        if len(_sessions) > 20:
            _sessions.clear()
        _sessions[sid] = data


def session(sid):
    with _lock:
        return _sessions.get(sid)


def image_labels(path):
    try:
        r = subprocess.run(["mdls", "-raw", "-name", "kMDItemPhotosSceneClassificationLabels", path],
                           capture_output=True, text=True, timeout=5)
    except (OSError, subprocess.TimeoutExpired):
        return []
    out = r.stdout.strip()
    if not out or out == "(null)":
        return []
    return [x.strip().strip('"') for x in out.strip("()").split(",") if x.strip().strip('"')]


def rerank(sid):
    data = session(sid)
    if not data or "items" not in data:
        return None
    cands = [it for it in data["items"] if not it["is_app"]][:RERANK_POOL]
    if len(cands) < 4:
        return None
    key = get_api_key()
    if not key:
        return None
    with ThreadPoolExecutor(8) as ex:
        labels = list(ex.map(lambda it: image_labels(it["path"]) if it["is_image"] else [], cands))
    lines = []
    for i, (it, lab) in enumerate(zip(cands, labels)):
        line = f"{i}. {it['name']} | {it['dir']} | {it['date']} | {it['kind']}"
        if lab:
            line += " | на фото: " + ", ".join(lab[:12])
        lines.append(line)
    resp = call_claude({
        "model": model(), "max_tokens": 400, "system": RANK_SYSTEM,
        "tools": [RANK_TOOL], "tool_choice": {"type": "tool", "name": "rank_results"},
        "messages": [{"role": "user", "content": f"Запрос: {data['query']}\n\nКандидаты:\n" + "\n".join(lines)}],
    }, key)
    top = []
    for i in tool_input(resp, "rank_results").get("order") or []:
        if isinstance(i, int) and 0 <= i < len(cands) and cands[i]["path"] not in top:
            top.append(cands[i]["path"])
    return {"top": top, "labels": {it["path"]: lab[:6] for it, lab in zip(cands, labels) if lab}}


# ---------------------------------------------------------------- превью и действия

def thumbnail(path, size=128):
    try:
        mtime = os.stat(path).st_mtime
    except OSError:
        return None
    os.makedirs(THUMBS, exist_ok=True)
    out = os.path.join(THUMBS, hashlib.md5(f"{path}|{mtime}".encode("utf-8")).hexdigest() + ".png")
    if not os.path.exists(out):
        tmp = tempfile.mkdtemp(dir=THUMBS)
        try:
            subprocess.run(["qlmanage", "-t", "-s", str(size), "-o", tmp, path],
                           capture_output=True, timeout=10)
            pngs = [f for f in os.listdir(tmp) if f.endswith(".png")]
            if pngs:
                os.replace(os.path.join(tmp, pngs[0]), out)
        except (OSError, subprocess.TimeoutExpired):
            pass
        finally:
            shutil.rmtree(tmp, ignore_errors=True)
    if not os.path.exists(out):
        return None
    with open(out, "rb") as f:
        return "data:image/png;base64," + base64.b64encode(f.read()).decode("ascii")


def open_path(path):
    if os.path.exists(path):
        subprocess.Popen(["open", path])


def reveal_path(path):
    if os.path.exists(path):
        subprocess.Popen(["open", "-R", path])


def copy_path(path):
    subprocess.run(["pbcopy"], input=path, text=True, timeout=5)
