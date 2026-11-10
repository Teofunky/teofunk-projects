"""SmartFind: режим уборки.

Правила:
- ничего не удаляет, только перемещает: в выбранные папки или в «_На удаление»;
- сначала предпросмотр с галочками, перемещение только после подтверждения;
- каждое применение пишет журнал, по нему всё возвращается на место.
"""
import hashlib
import json
import os
import re
import shutil
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta

import engine
from engine import HOME, human_size, log, nfc, pretty_dir

DOCS = os.path.join(HOME, "Documents")
JOURNAL_DIR = os.path.join(engine.SUPPORT, "journal")
TRASH = "@trash"
ORGANIZE_LIMIT = 150
LIST_LIMIT = 150
DUP_TIME_BUDGET = 90

# внутрь этих пакетов не заходим и по отдельности их содержимое не трогаем
PACKAGE_EXT = (".app", ".photoslibrary", ".fcpbundle", ".logicx", ".band", ".imovielibrary",
               ".tvlibrary", ".musiclibrary", ".lrlibrary", ".lrdata", ".xcodeproj", ".xcworkspace",
               ".bundle", ".framework", ".plugin", ".component", ".vst", ".vst3", ".aaxplugin",
               ".rtfd", ".pages", ".numbers", ".key", ".sparsebundle", ".pkg", ".mpkg", ".abbu")
# папки программ в корне Documents: не трогаем ни как источник, ни как цель (меняется в config.json → "protected")
DEFAULT_PROTECTED = ["Adobe", "Codex", "Max 9", "REAPER Media", "Magenta", "MRT2 Bundle", "Guitar",
                     "Metronome", "KonnakolMaster", "reel_practice_mac_v2"]
INSTALLER_EXT = {"dmg", "pkg", "mpkg", "iso"}
PARTIAL_EXT = {"crdownload", "part", "download", "partial", "opdownload"}
ARCHIVE_EXT = {"zip", "rar", "7z", "tar", "gz", "tgz", "bz2", "xz"}

ORGANIZE_SYSTEM = """Ты раскладываешь файлы пользователя по его папкам. Для каждого файла выбери номер целевой папки из списка или -1, если файл лучше оставить на месте (непонятно, куда его деть, или это явно текущая работа). Учитывай имя, тип, размер, дату, исходную папку и пожелание пользователя. Установщики (.dmg, .pkg), недокачанные файлы и явный мусор отправляй в «_На удаление». Ставь только номера из списка. why: до 5 слов по-русски, почему туда."""

ASSIGN_TOOL = {
    "name": "assign_folders",
    "description": "Куда переложить каждый файл",
    "input_schema": {
        "type": "object",
        "properties": {"moves": {"type": "array", "items": {
            "type": "object",
            "properties": {"i": {"type": "integer"}, "t": {"type": "integer"}, "why": {"type": "string"}},
            "required": ["i", "t"]}}},
        "required": ["moves"],
    },
}


# ---------------------------------------------------------------- защита

def trash_root():
    return os.path.expanduser(engine.load_config().get("trash_folder") or os.path.join(DOCS, "_На удаление"))


def protected_dirs():
    names = engine.load_config().get("protected", DEFAULT_PROTECTED)
    dirs = [os.path.join(DOCS, n) for n in names]
    dirs += [os.path.join(HOME, "Music", "Music"), os.path.join(HOME, "Library"), trash_root()]
    return [nfc(d).rstrip("/") for d in dirs]


def is_protected(path):
    n = nfc(path).rstrip("/")
    if not n.startswith(nfc(HOME) + "/") or engine.is_junk(path):
        return True
    for d in protected_dirs():
        if n == d or n.startswith(d + "/"):
            return True
    return any(part.lower().endswith(PACKAGE_EXT) for part in n.split("/")[:-1])


# ---------------------------------------------------------------- строки предпросмотра

def _size(path):
    try:
        if os.path.isdir(path) and not os.path.islink(path):
            r = subprocess.run(["du", "-sk", path], capture_output=True, text=True, timeout=4)
            return int(r.stdout.split()[0]) * 1024 if r.stdout.strip() else 0
        return os.lstat(path).st_size
    except (OSError, ValueError, IndexError, subprocess.TimeoutExpired):
        return 0


def target_label(t):
    if not t:
        return "оставить на месте"
    if t == TRASH:
        return "_На удаление"
    n = nfc(t)
    docs = nfc(DOCS) + "/"
    return n[len(docs):] if n.startswith(docs) else pretty_dir(n)


def make_row(path, target, reason="", checked=True, group=None, keeper=False, size=None):
    try:
        st = os.lstat(path)
    except OSError:
        return None
    is_app = path.endswith(".app")
    is_dir = os.path.isdir(path) and not is_app
    name = nfc(os.path.basename(path.rstrip("/")))
    ext = os.path.splitext(name)[1].lower().lstrip(".")
    b = _size(path) if size is None else size
    return {
        "path": path, "name": name, "dir": pretty_dir(os.path.dirname(path.rstrip("/"))),
        "date": datetime.fromtimestamp(st.st_mtime).strftime("%d.%m.%Y"),
        "bytes": b, "size": human_size(b),
        "kind": "Приложение" if is_app else ("Папка" if is_dir else (ext.upper() or "Файл")),
        "is_app": is_app, "is_dir": is_dir, "is_image": ext in engine.IMAGE_EXT,
        "target": target, "reason": reason, "checked": bool(checked and target),
        "group": group, "keeper": keeper,
    }


def target_folders():
    """Папки Documents (до 2 уровней) как варианты, куда класть."""
    out = []
    try:
        top = sorted(os.listdir(DOCS))
    except OSError:
        return out
    for e in top:
        p = os.path.join(DOCS, e)
        if e.startswith(".") or not os.path.isdir(p) or is_protected(p) or e.lower().endswith(PACKAGE_EXT):
            continue
        out.append(p)
        try:
            subs = sorted(os.listdir(p))
        except OSError:
            continue
        for s in subs:
            sp = os.path.join(p, s)
            if not s.startswith(".") and os.path.isdir(sp) and not s.lower().endswith(PACKAGE_EXT):
                out.append(sp)
    return out[:250]


def _sources(plan, default):
    out = []
    for f in plan.get("folders") or []:
        p = os.path.expanduser(str(f))
        if os.path.isdir(p) and p not in out:
            out.append(p)
    return out or [p for p in default if os.path.isdir(p)]


# ---------------------------------------------------------------- режимы

def _organize(plan, query, key):
    targets = target_folders()
    if not targets:
        raise RuntimeError("Нет доступа к папке «Документы». Разреши: Системные настройки → "
                           "Конфиденциальность и безопасность → Файлы и папки.")
    tset = {nfc(t) for t in targets}
    entries = []
    for src in _sources(plan, [os.path.join(HOME, "Desktop"), os.path.join(HOME, "Downloads")]):
        try:
            names = sorted(os.listdir(src))
        except OSError:
            continue
        for e in names:
            if e.startswith(".") or e.endswith(".localized"):
                continue
            p = os.path.join(src, e)
            n = nfc(p)
            if is_protected(p) or n in tset or any(t.startswith(n + "/") for t in tset):
                continue
            entries.append(p)
    total = len(entries)
    entries = entries[:ORGANIZE_LIMIT]
    if not entries:
        return [], "Здесь уже чисто: разбирать нечего.", targets

    with ThreadPoolExecutor(8) as ex:
        sizes = list(ex.map(_size, entries))
    lines = []
    for i, (p, s) in enumerate(zip(entries, sizes)):
        try:
            d = datetime.fromtimestamp(os.lstat(p).st_mtime).strftime("%d.%m.%Y")
        except OSError:
            d = "?"
        kind = "папка" if os.path.isdir(p) and not p.endswith(".app") else "файл"
        lines.append(f"{i}. {nfc(os.path.basename(p))} | {kind} | {human_size(s)} | {d} | из {pretty_dir(os.path.dirname(p))}")
    tlines = [f"{j}. {target_label(t)}" for j, t in enumerate(targets)] + [f"{len(targets)}. _На удаление"]

    resp = engine.call_claude({
        "model": engine.model(), "max_tokens": 8000, "system": ORGANIZE_SYSTEM,
        "tools": [ASSIGN_TOOL], "tool_choice": {"type": "tool", "name": "assign_folders"},
        "messages": [{"role": "user", "content":
                      f"Пожелание пользователя: {query}\n\nПапки, куда можно класть:\n" + "\n".join(tlines)
                      + "\n\nФайлы:\n" + "\n".join(lines)}],
    }, key, timeout=120)
    assign = {}
    for m in engine.tool_input(resp, "assign_folders").get("moves") or []:
        i, t = m.get("i"), m.get("t")
        if not (isinstance(i, int) and isinstance(t, int) and 0 <= i < len(entries)):
            continue
        why = str(m.get("why") or "")[:60]
        if 0 <= t < len(targets):
            assign[i] = (targets[t], why)
        elif t == len(targets):
            assign[i] = (TRASH, why)

    rows = []
    for i, p in enumerate(entries):
        tgt, why = assign.get(i, ("", ""))
        if tgt and tgt != TRASH and nfc(os.path.dirname(p)) == nfc(tgt):
            tgt, why = "", "уже на месте"
        r = make_row(p, tgt, why or ("не ясно, куда" if not tgt else ""), checked=bool(tgt), size=sizes[i])
        if r:
            rows.append(r)
    rows.sort(key=lambda r: (r["target"] == "", target_label(r["target"]), r["name"].lower()))
    note = (f"Показаны первые {ORGANIZE_LIMIT} из {total}: после применения запусти ещё раз."
            if total > ORGANIZE_LIMIT else "Проверь, куда что едет: папку можно поменять в каждой строке.")
    return rows, note, targets


def _last_used(path):
    try:
        r = subprocess.run(["mdls", "-raw", "-name", "kMDItemLastUsedDate", path],
                           capture_output=True, text=True, timeout=5)
    except (OSError, subprocess.TimeoutExpired):
        return None
    m = re.match(r"(\d{4})-(\d{2})-(\d{2})", r.stdout.strip())
    return datetime(int(m[1]), int(m[2]), int(m[3])) if m else None


def _large(plan, query, key):
    mn = engine._num(plan.get("min_size_mb")) or 200
    files = []
    for p in dict.fromkeys(engine.mdfind(f"kMDItemFSSize >= {int(mn * 1048576)}", engine.search_roots(plan), limit=5000)):
        if is_protected(p) or not os.path.isfile(p):
            continue
        try:
            files.append((os.path.getsize(p), p))
        except OSError:
            pass
    files.sort(reverse=True)
    files = files[:LIST_LIMIT]
    with ThreadPoolExecutor(8) as ex:
        used = list(ex.map(lambda x: _last_used(x[1]), files))
    now = datetime.now()
    rows = []
    for (size, p), u in zip(files, used):
        ext = os.path.splitext(p)[1].lower().lstrip(".")
        reason = f"открывался {u:%d.%m.%Y}" if u else "дата открытия неизвестна"
        stale = u is None or (now - u).days > 30
        if ext in INSTALLER_EXT:
            reason = "установщик, " + reason
        elif ext in ARCHIVE_EXT:
            reason = "архив, " + reason
        r = make_row(p, TRASH, reason, checked=(ext in INSTALLER_EXT | ARCHIVE_EXT) and stale, size=size)
        if r:
            rows.append(r)
    total = sum(s for s, _ in files)
    note = (f"Файлы больше {mn:g} МБ: {len(files)} шт., всего {human_size(total)}. "
            "Отмечены только давно не открытые установщики и архивы, остальное отметь сам.")
    return rows, note, []


def _junk(plan, query, key):
    roots = engine.search_roots(plan)
    found = {}

    def add(q, reason, checked):
        for p in engine.mdfind(q, roots, limit=2000):
            if p not in found and not is_protected(p) and os.path.lexists(p):
                found[p] = (reason, checked)

    add(engine._or([f'kMDItemFSName == "*.{e}"c' for e in sorted(INSTALLER_EXT)]), "установщик", True)
    add(engine._or([f'kMDItemFSName == "*.{e}"c' for e in sorted(PARTIAL_EXT)]), "недокачанный файл", True)
    old = (datetime.now() - timedelta(days=60)).strftime("%Y-%m-%d")
    add(f"kMDItemIsScreenCapture == 1 && kMDItemContentCreationDate <= $time.iso({old}T00:00:00Z)",
        "скриншот старше 2 месяцев", False)
    rows = [r for r in (make_row(p, TRASH, rs, checked=c) for p, (rs, c) in found.items()) if r]
    rows.sort(key=lambda r: (not r["checked"], -r["bytes"]))
    rows = rows[:LIST_LIMIT * 2]
    marked = sum(r["bytes"] for r in rows if r["checked"])
    note = f"Отмечено {human_size(marked)}: установщики и недокачанное. Старые скриншоты не отмечены, проверь сам."
    return rows, note, []


def _quick_hash(path):
    h = hashlib.blake2b(digest_size=16)
    with open(path, "rb") as f:
        h.update(f.read(65536))
        if os.fstat(f.fileno()).st_size > 131072:
            f.seek(-65536, os.SEEK_END)
            h.update(f.read(65536))
    return h.hexdigest()


def _full_hash(path):
    h = hashlib.blake2b(digest_size=20)
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def _hash_all(paths, fn, deadline):
    def one(p):
        if time.time() > deadline:
            return None
        try:
            return fn(p)
        except OSError:
            return None
    with ThreadPoolExecutor(4) as ex:
        return dict(zip(paths, ex.map(one, paths)))


_COPYISH = re.compile(r"(copy|копия|\(\d+\))\s*(\.[^.]+)?$")
_NUMBERED = re.compile(r"^\d\d ")


def _keeper_key(p):
    """Какой из одинаковых файлов оставить: из папок 01-09, не из Загрузок, без «копия» в имени, старший."""
    n = nfc(p)
    docs = nfc(DOCS) + "/"
    in_numbered = n.startswith(docs) and bool(_NUMBERED.match(n[len(docs):]))
    in_temp = any(n.startswith(nfc(os.path.join(HOME, d)) + "/") for d in ("Downloads", "Desktop"))
    copyish = bool(_COPYISH.search(nfc(os.path.basename(p)).lower()))
    try:
        mtime = os.stat(p).st_mtime
    except OSError:
        mtime = 0
    return (not in_numbered, in_temp, copyish, mtime, len(n))


def _duplicates(plan, query, key):
    kinds = [k for k in plan.get("kinds") or [] if k in engine.KIND_UTIS]
    kc = engine._or([f'kMDItemContentTypeTree == "{u}"' for k in kinds for u in engine.KIND_UTIS[k]])
    min_bytes = 50 * 1024 if kinds else 1024 * 1024
    paths = engine.mdfind(engine._and([kc, f"kMDItemFSSize >= {min_bytes}"]),
                          engine.search_roots(plan), limit=40000, timeout=30)
    sizes, by_size = {}, {}
    for p in dict.fromkeys(paths):
        if is_protected(p) or os.path.islink(p) or not os.path.isfile(p):
            continue
        try:
            sizes[p] = os.path.getsize(p)
        except OSError:
            continue
        by_size.setdefault(sizes[p], []).append(p)
    deadline = time.time() + DUP_TIME_BUDGET
    flat = [p for g in by_size.values() if len(g) > 1 for p in g]
    qh = _hash_all(flat, _quick_hash, deadline)
    buckets = {}
    for p in flat:
        if qh.get(p):
            buckets.setdefault((sizes[p], qh[p]), []).append(p)
    flat2 = [p for g in buckets.values() if len(g) > 1 for p in g]
    fh = _hash_all(flat2, _full_hash, deadline)
    final = {}
    for p in flat2:
        if fh.get(p):
            final.setdefault((sizes[p], fh[p]), []).append(p)
    groups = [g for g in final.values() if len(g) > 1]
    groups.sort(key=lambda g: -sizes[g[0]] * (len(g) - 1))
    partial = time.time() > deadline

    rows, wasted = [], 0
    for gi, g in enumerate(groups[:LIST_LIMIT]):
        g = sorted(g, key=_keeper_key)
        r = make_row(g[0], TRASH, "оставляю", checked=False, group=gi, keeper=True, size=sizes[g[0]])
        if r:
            rows.append(r)
        for d in g[1:]:
            r = make_row(d, TRASH, "копия", checked=True, group=gi, size=sizes[d])
            if r:
                rows.append(r)
                wasted += sizes[d]
    if not groups:
        note = "Одинаковых файлов не нашёл."
    else:
        note = (f"Групп одинаковых файлов: {len(groups)}, копии занимают {human_size(wasted)}. "
                "В каждой группе один файл оставлен (из папок 01-09, без «копия» в имени).")
        if len(groups) > LIST_LIMIT:
            note += f" Показаны {LIST_LIMIT} самых тяжёлых групп."
    if partial:
        note += " Проверка прервана по времени: запусти ещё раз для остального."
    return rows, note, []


MODES = {"organize": _organize, "large": _large, "duplicates": _duplicates, "junk": _junk}


def propose(plan, query, key, sid):
    action = plan.get("action")
    rows, note, targets = MODES[action](plan, query, key)
    for i, r in enumerate(rows):
        r["id"] = i
    engine.remember(sid, {"query": query, "mode": "cleanup", "rows": rows, "allowed": set(targets) | {TRASH}})
    log("cleanup", action, "rows:", len(rows))
    return {
        "id": sid, "mode": "cleanup", "action": action, "intent": plan.get("intent") or "",
        "chips": engine.plan_chips(plan), "rows": rows, "note": note,
        "targets": ([{"path": t, "label": target_label(t)} for t in targets] + [{"path": TRASH, "label": "_На удаление"}])
        if targets else [],
    }


# ---------------------------------------------------------------- применение и отмена

def _unique(path):
    if not os.path.lexists(path):
        return path
    base, ext = os.path.splitext(path)
    if not ext or len(ext) > 7 or " " in ext:
        base, ext = path, ""
    i = 2
    while os.path.lexists(f"{base} ({i}){ext}"):
        i += 1
    return f"{base} ({i}){ext}"


def _write_journal(run_id, moved, undone=False):
    os.makedirs(JOURNAL_DIR, exist_ok=True)
    with open(os.path.join(JOURNAL_DIR, run_id + ".json"), "w", encoding="utf-8") as f:
        json.dump({"run_id": run_id, "moves": moved, "undone": undone}, f, ensure_ascii=False, indent=1)


def apply(sid, selections):
    data = engine.session(sid)
    if not data or data.get("mode") != "cleanup":
        raise RuntimeError("Предложение устарело, запусти уборку заново")
    rows, allowed = data["rows"], data["allowed"]
    run_id = datetime.now().strftime("%Y-%m-%d %H-%M-%S")
    run_trash = os.path.join(trash_root(), f"SmartFind {run_id}")
    moved, moved_ids, failed = [], [], []
    try:
        for sel in selections or []:
            try:
                row = rows[int(sel.get("id"))]
            except (ValueError, TypeError, IndexError, AttributeError):
                continue
            target = sel.get("target") or ""
            if not target or row.get("done"):
                continue
            src = row["path"]
            if target not in allowed:
                failed.append({"name": row["name"], "error": "папка не из списка"})
                continue
            if not os.path.lexists(src) or is_protected(src):
                failed.append({"name": row["name"], "error": "файла нет или он защищён"})
                continue
            if target == TRASH:
                dst = os.path.join(run_trash, os.path.relpath(src, HOME))
            else:
                if nfc(os.path.dirname(src)) == nfc(target):
                    continue
                dst = os.path.join(target, os.path.basename(src))
            ns = nfc(src).rstrip("/")
            if nfc(dst) == ns or nfc(dst).startswith(ns + "/"):
                failed.append({"name": row["name"], "error": "нельзя положить папку в саму себя"})
                continue
            dst = _unique(dst)
            try:
                os.makedirs(os.path.dirname(dst), exist_ok=True)
                shutil.move(src, dst)
            except OSError as e:
                failed.append({"name": row["name"], "error": str(e)[:120]})
                continue
            moved.append({"src": src, "dst": dst, "bytes": row["bytes"]})
            moved_ids.append(row["id"])
            row["done"] = True
    finally:
        if moved:
            _write_journal(run_id, moved)
    log("cleanup apply", run_id, "moved:", len(moved), "failed:", len(failed))
    return {"run_id": run_id if moved else None, "moved": len(moved), "moved_ids": moved_ids,
            "bytes": human_size(sum(m["bytes"] for m in moved)), "failed": failed[:10]}


def history(limit=10):
    try:
        names = sorted((n for n in os.listdir(JOURNAL_DIR) if n.endswith(".json")), reverse=True)
    except OSError:
        return []
    out = []
    for n in names[:limit]:
        try:
            with open(os.path.join(JOURNAL_DIR, n), encoding="utf-8") as f:
                j = json.load(f)
            out.append({"run_id": j["run_id"], "count": len(j["moves"]), "undone": bool(j.get("undone"))})
        except (OSError, ValueError, KeyError):
            continue
    return out


def _prune_empty(root):
    if not os.path.isdir(root):
        return
    for d, _subs, _files in os.walk(root, topdown=False):
        try:
            entries = os.listdir(d)
            if entries == [".DS_Store"]:
                os.remove(os.path.join(d, ".DS_Store"))
                entries = []
            if not entries:
                os.rmdir(d)
        except OSError:
            pass


def undo(run_id=None):
    if not run_id:
        pending = [h for h in history(50) if not h["undone"]]
        if not pending:
            return {"restored": 0, "error": "Нечего отменять"}
        run_id = pending[0]["run_id"]
    if not re.fullmatch(r"[\d\- ]+", run_id or ""):
        return {"restored": 0, "error": "Неверный номер уборки"}
    path = os.path.join(JOURNAL_DIR, run_id + ".json")
    try:
        with open(path, encoding="utf-8") as f:
            j = json.load(f)
    except (OSError, ValueError):
        return {"restored": 0, "error": "Журнал уборки не найден"}
    if j.get("undone"):
        return {"restored": 0, "error": "Эта уборка уже отменена"}
    restored, failed = 0, []
    for m in reversed(j["moves"]):
        if not os.path.lexists(m["dst"]):
            failed.append({"name": os.path.basename(m["dst"]), "error": "файла уже нет на новом месте"})
            continue
        src = _unique(m["src"])
        try:
            os.makedirs(os.path.dirname(src), exist_ok=True)
            shutil.move(m["dst"], src)
            restored += 1
        except OSError as e:
            failed.append({"name": os.path.basename(m["dst"]), "error": str(e)[:120]})
    _write_journal(run_id, j["moves"], undone=True)
    _prune_empty(os.path.join(trash_root(), f"SmartFind {run_id}"))
    log("cleanup undo", run_id, "restored:", restored, "failed:", len(failed))
    return {"restored": restored, "failed": failed[:10]}
