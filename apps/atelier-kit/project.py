"""Сборка и чтение проектов ATELIER (.atelier2) из простого описания spec.json.

Описание пишется в пикселях картины и секундах, сборщик переводит всё в формат ATELIER 0.4:
доли кадра (холст contain под выбранное соотношение), кадры 24/с, ярусы станка, партии.
Формат описан в README.md рядом.
"""
import hashlib
import io
import json
import math
import re
import zipfile
from pathlib import Path

from PIL import Image

FPS = 24
RATIOS = ["16:9", "9:16", "1:1", "2:3", "3:2", "4:3", "4:5"]
ID = re.compile(r"^[A-Za-z0-9_-]{1,100}$")
MIME = {".png": "image/png", ".webp": "image/webp", ".jpg": "image/jpeg", ".jpeg": "image/jpeg",
        ".mp4": "video/mp4", ".webm": "video/webm", ".mov": "video/quicktime",
        ".mp3": "audio/mpeg", ".wav": "audio/wav", ".m4a": "audio/mp4", ".aac": "audio/aac"}

# Значения по умолчанию взяты из ATELIER 0.4 (blankLayer и setGesture) и вчерашних проб.
BASE = {"amp": 48, "feather": 6, "mode": "accents", "period": 3.5, "attack": 6, "release": 10}
EFFECTS = {
    # лицо
    "blink": {"amp": 100, "feather": 1.2, "attack": 3, "release": 5, "hold": 1},
    "gaze": {"amp": 100, "feather": 1.2, "attack": 8, "release": 14, "hold": 15},
    "brow": {"amp": 60, "feather": 4, "attack": 8, "release": 14, "hold": 10},
    "smile": {"amp": 60, "feather": 4, "attack": 10, "release": 16, "hold": 20},
    # тело и предметы
    "flex": {"amp": 5, "feather": 10, "mode": "loop", "period": 4.2},
    "hinge": {"amp": 100, "feather": 4, "mode": "loop", "period": 2.3, "angle": 7},
    "wing": {"amp": 100, "feather": 2, "mode": "loop", "period": 0.72, "angle": 18},
    "strike": {"amp": 100, "attack": 2, "release": 5, "hold": 0, "angle": -17, "rebound": 0.78},
    "shift": {"amp": 48, "feather": 6},
    # среда
    "wind": {"amp": 40, "feather": 12, "mode": "loop", "period": 4.0},
    "wave": {"amp": 46, "feather": 12, "mode": "loop", "period": 2.5},
    "light": {"amp": 30, "feather": 18, "mode": "loop", "period": 4.0},
    "donor": {"amp": 100, "feather": 8, "mode": "loop"},
}
GESTURES = {"gaze", "blink", "smile", "brow", "wing", "flex", "strike"}
PART_KEYS = {"amp", "feather", "mode", "period", "attack", "release", "hold", "phase", "angle", "ease",
             "rebound", "blur", "dx", "dy", "followDelay", "donorFit", "approved"}


def choose_ratio(w, h):
    r = w / h
    return min(RATIOS, key=lambda s: abs(math.log(r / (int(s.split(":")[0]) / int(s.split(":")[1])))))


class Frame:
    """Перевод пикселей картины в доли холста ATELIER (картина вписана contain)."""

    def __init__(self, iw, ih, ratio):
        a, b = (int(v) for v in ratio.split(":"))
        self.W, self.H = 10000.0, 10000.0 * b / a
        self.k = min(self.W / iw, self.H / ih)
        self.ox, self.oy = (self.W - iw * self.k) / 2, (self.H - ih * self.k) / 2
        self.iw, self.ih, self.ratio = iw, ih, ratio

    def pt(self, p):
        return [round((self.ox + p[0] * self.k) / self.W, 6), round((self.oy + p[1] * self.k) / self.H, 6)]

    def back(self, q):
        return [(q[0] * self.W - self.ox) / self.k, (q[1] * self.H - self.oy) / self.k]

    def dx(self, px):
        return round(px * self.k / self.W, 6)

    def dy(self, px):
        return round(px * self.k / self.H, 6)


def shape_points(shape, n=24):
    if "poly" in shape:
        return [list(map(float, p)) for p in shape["poly"]]
    if "rect" in shape:
        x0, y0, x1, y1 = shape["rect"]
        return [[x0, y0], [x1, y0], [x1, y1], [x0, y1]]
    if "ellipse" in shape:
        cx, cy, rx, ry = shape["ellipse"][:4]
        rot = math.radians(shape["ellipse"][4]) if len(shape["ellipse"]) > 4 else 0
        out = []
        for i in range(n):
            t = 2 * math.pi * i / n
            x, y = rx * math.cos(t), ry * math.sin(t)
            out.append([cx + x * math.cos(rot) - y * math.sin(rot), cy + x * math.sin(rot) + y * math.cos(rot)])
        return out
    raise ValueError("shape: нужен poly, rect или ellipse")


def centroid(pts):
    return [sum(p[0] for p in pts) / len(pts), sum(p[1] for p in pts) / len(pts)]


def frames_of(sec):
    return int(round(float(sec) * FPS))


class Builder:
    def __init__(self, spec, root="."):
        self.spec, self.root = spec, Path(root)
        self.assets = {}  # id -> (bytes, name, mime, w, h)

    def path(self, p):
        p = Path(p)
        return p if p.is_absolute() else self.root / p

    def asset(self, aid, file, name=None):
        if aid in self.assets:
            return aid
        if not ID.match(aid):
            raise ValueError(f"id материала «{aid}»: только латиница, цифры, _ и -")
        f = self.path(file)
        data, mime = f.read_bytes(), MIME.get(f.suffix.lower())
        if not mime:
            raise ValueError(f"неизвестный тип файла {f.name}")
        w = h = None
        if mime.startswith("image/"):
            w, h = Image.open(io.BytesIO(data)).size
        self.assets[aid] = (data, name or f.name, mime, w, h)
        return aid

    def pose(self, fr, k):
        return {"x": fr.dx(k.get("dx", 0)), "y": fr.dy(k.get("dy", 0)),
                "angle": float(k.get("angle", 0)), "scale": float(k.get("scale", k.get("zoom", 1)))}

    def track(self, fr, keys, frames, curve=None):
        """Ключи {t, dx, dy, angle, scale} → start, end, keys ATELIER (кадры 1..frames-2)."""
        last = frames - 1
        keys = sorted(keys or [], key=lambda k: k["t"])
        ident = {"x": 0.0, "y": 0.0, "angle": 0.0, "scale": 1.0}
        start, end, mid = dict(ident), None, []
        for k in keys:
            f = min(last, max(0, frames_of(k["t"])))
            p = self.pose(fr, k)
            if f == 0:
                start = p
            elif f >= last:
                end = p
            elif not mid or f > mid[-1]["frame"]:
                mid.append({"frame": f, "pose": p})
        if end is None:
            end = mid[-1]["pose"] if mid else start
        t = {"start": start, "end": end}
        if mid:
            t["keys"] = mid
        if curve:
            t["curve"] = curve
        return t

    def part(self, fr, p, frames, n):
        eff = p["effect"]
        if eff not in EFFECTS:
            raise ValueError(f"эффект {eff}: допустимы {', '.join(EFFECTS)}")
        pts = shape_points(p["shape"])
        L = {"id": p.get("id") or f"p{n:02d}", "name": p.get("name", eff), "effect": eff,
             "points": [fr.pt(q) for q in pts], "pivot": fr.pt(p.get("pivot") or centroid(pts)),
             **BASE, **EFFECTS[eff], "events": [], "hidden": bool(p.get("hidden", False))}
        for key in PART_KEYS & p.keys():
            L[key] = p[key]
        if "accents" in p:
            L["events"] = sorted({min(frames - 1, max(0, frames_of(s))) for s in p["accents"]})
        if "frames" in p:
            L["events"] = sorted({min(frames - 1, max(0, int(f))) for f in p["frames"]})
        if L["mode"] == "accents" and not L["events"] and not p.get("link"):
            raise ValueError(f"партия «{L['name']}»: режим accents без акцентов, задай accents или mode=loop")
        if eff in GESTURES:
            L.setdefault("hold", 0)
            L.setdefault("phase", 0)
            L["target"] = fr.pt(p.get("target") or centroid(pts))
        elif "target" in p:
            L["target"] = fr.pt(p["target"])
        if eff == "strike":
            for key in ("tip", "rigid"):
                if key not in p:
                    raise ValueError(f"удар «{L['name']}»: нужны tip, rigid и target в пикселях")
                L[key] = fr.pt(p[key])
            if "seam" in p:
                L["seam"] = [fr.pt(q) for q in p["seam"]]
        if "bend" in p:
            L["bend"] = [fr.pt([0, p["bend"][0]])[1], fr.pt([0, p["bend"][1]])[1]]
        if eff == "donor":
            L["donor"] = self.asset(p.get("video_id", L["id"] + "-video"), p["video"])
        if "plate" in p:
            L["plate"] = self.asset(L["id"] + "-plate", p["plate"])
        for key in ("host", "follow"):
            if key in p:
                L[key] = p[key]
        if "link" in p:
            L["link"] = {"id": p["link"]["id"], "delay": int(p["link"].get("delay", 0))}
        return L

    def tier(self, fr, t, frames):
        if not ID.match(t["id"]):
            raise ValueError(f"id яруса «{t['id']}»")
        phases = t.get("phases") or [{"t": 0, "image": t["image"]}]
        ph = []
        for i, q in enumerate(sorted(phases, key=lambda q: q["t"])):
            aid = self.asset(q.get("id") or f"{t['id']}-{i}", q["image"], q.get("name"))
            ph.append({"frame": 0 if i == 0 else min(frames - 1, frames_of(q["t"])), "asset": aid})
        img = self.assets[ph[0]["asset"]]
        if (img[3], img[4]) != (fr.iw, fr.ih):
            raise ValueError(f"ярус {t['id']}: {img[3]}×{img[4]}, а картина {fr.iw}×{fr.ih}. Слои должны быть на холсте картины")
        tr = self.track(fr, t.get("keys"), frames, t.get("curve"))
        return {"id": t["id"], "name": t.get("name", t["id"]), "parent": t.get("parent"), "depth": float(t.get("depth", 0)),
                "opacity": float(t.get("opacity", 1)), "pivot": fr.pt(t["pivot"]), **tr, "phases": ph,
                **({"hidden": True} if t.get("hidden") else {})}

    def build(self):
        s = self.spec
        base_file = s.get("plate") or s["painting"]
        iw, ih = Image.open(self.path(s["painting"])).size
        ratio = s.get("ratio", "auto")
        ratio = choose_ratio(iw, ih) if ratio == "auto" else ratio
        if ratio not in RATIOS:
            raise ValueError(f"соотношение {ratio}: допустимы {RATIOS}")
        fr = Frame(iw, ih, ratio)
        frames = int(s["frames"]) if "frames" in s else frames_of(s["seconds"])
        base = self.asset(s.get("base_id", "plate" if s.get("plate") else "painting"), base_file, s.get("base_name"))
        if self.assets[base][3:] != (iw, ih):
            raise ValueError("подложка должна совпадать по размеру с картиной")
        parts = [self.part(fr, p, frames, i + 1) for i, p in enumerate(s.get("parts", []))]
        ids = [p["id"] for p in parts]
        if len(set(ids)) != len(ids):
            raise ValueError("повторяются id партий")
        scene = {"id": s.get("scene_id", "shot-1"), "asset": base, "name": s.get("shot_name", s["name"]), "frames": frames,
                 "exposure": int(s.get("exposure", 1)), "fit": "contain", "trim": 0, "notes": s.get("notes", ""),
                 "active": "A", "takes": {"A": {"layers": parts, "guards": [[fr.pt(q) for q in shape_points(g)] for g in s.get("guards", [])]}}}
        tiers = [self.tier(fr, t, frames) for t in s.get("tiers", [])]
        cam = s.get("camera")
        if tiers or cam:
            scene["stand"] = {"layers": tiers, "camera": self.track(fr, (cam or {}).get("keys"), frames, (cam or {}).get("curve", "smooth"))}
        known = {t["id"] for t in tiers}
        for p in parts:
            if p.get("host") and p["host"] not in known:
                raise ValueError(f"партия «{p['name']}» ссылается на ярус {p['host']}, его нет")
        audio = s.get("audio") or {}
        film = {"format": "atelier.studio", "version": 2, "id": s.get("id", re.sub(r"[^A-Za-z0-9_-]", "", s.get("slug", "film")) or "film"),
                "name": s["name"], "ratio": ratio, "fps": 24, "scenes": [scene],
                "audio": self.asset("audio", audio["file"]) if audio.get("file") else None,
                "audioTrim": float(audio.get("trim", 0)), "volume": float(audio.get("volume", 100)),
                "bpm": float(audio.get("bpm", 120)), "zero": float(audio.get("zero", 0)), "snap": bool(audio.get("snap", False))}
        return film, self.assets, fr


def write(film, assets, out):
    """Пишет .atelier2: manifest.json, scenes/NNNN.json, media/<sha256>.<ext> (ZIP без сжатия)."""
    out = Path(out)
    out.parent.mkdir(parents=True, exist_ok=True)
    ext = {v: k for k, v in MIME.items()}
    with zipfile.ZipFile(out, "w", zipfile.ZIP_STORED) as z:
        names = []
        for i, sc in enumerate(film["scenes"]):
            n = f"scenes/{i + 1:04d}.json"
            z.writestr(n, json.dumps(sc, ensure_ascii=False))
            names.append(n)
        man = []
        for aid, (data, name, mime, w, h) in assets.items():
            path = f"media/{hashlib.sha256(data).hexdigest()}{ext.get(mime, '.bin')}"
            if path not in z.namelist():
                z.writestr(path, data)
            m = {"id": aid, "name": name, "mime": mime, "path": path}
            if w:
                m.update(width=w, height=h)
            man.append(m)
        proj = dict(film, scenes=names)
        z.writestr("manifest.json", json.dumps({"format": "atelier.archive", "version": 3, "project": proj, "assets": man}, ensure_ascii=False))
    return out


def read(path):
    """Читает .atelier2 → (film со сценами, assets как в Builder)."""
    with zipfile.ZipFile(path) as z:
        man = json.loads(z.read("manifest.json"))
        film = dict(man["project"])
        film["scenes"] = [json.loads(z.read(n)) for n in film["scenes"]]
        assets = {a["id"]: (z.read(a["path"]), a["name"], a["mime"], a.get("width"), a.get("height")) for a in man["assets"]}
    return film, assets


def build(spec_path, out=None):
    spec_path = Path(spec_path)
    spec = json.loads(spec_path.read_text(encoding="utf-8"))
    film, assets, fr = Builder(spec, spec_path.parent).build()
    out = out or spec_path.parent / f"{spec['name']}.atelier2"
    return write(film, assets, out), film, fr


if __name__ == "__main__":
    import sys
    p, film, fr = build(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else None)
    sc = film["scenes"][0]
    print(f"{p} · {fr.ratio} · {sc['frames']} кадров ({sc['frames'] / FPS:.1f} с) · партий {len(sc['takes']['A']['layers'])}"
          f" · ярусов {len(sc.get('stand', {}).get('layers', []))} · {p.stat().st_size / 1e6:.1f} МБ")
