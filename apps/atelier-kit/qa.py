"""Автопроверка проекта ATELIER до показа.

python3 qa.py проект.atelier2 --painting картина.png --out qa/ [--height 720]

Проверки (всё рендерит сам ATELIER в headless Chrome):
1. Тождество: партии выключены, ярусы и камера на месте → кадр совпадает с картиной.
   Ловит швы вырезок и плохую подложку.
2. Вне масок: камера остановлена, кадры по всему плану → вне партий и ярусов картина
   не меняется ни на один уровень.
3. Моргания: на пике радужка и зрачок ушли под веко (≥ 80 %). Тон века только для справки:
   цвет века смотреть глазами на blink_*.jpg.
4. Жизнь: все кадры в малом размере, застывшие участки дольше 1,5 с, карта движения,
   контактный лист 1 кадр/с.
Итог: report.json, report.txt и картинки в папке --out.
"""
import argparse
import asyncio
import io
import json
import math
import sys
import tempfile
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from project import FPS, Builder, read, write  # noqa: E402
from studio import Studio  # noqa: E402

OFF_PARTS = "f=>{for(const s of f.scenes)for(const t of Object.values(s.takes))for(const l of t.layers)l.hidden=true}"
STILL_CAMERA = "f=>{const I={x:0,y:0,angle:0,scale:1};for(const s of f.scenes)if(s.stand)s.stand.camera={start:{...I},end:{...I}}}"
STILL_TIERS = ("f=>{const I={x:0,y:0,angle:0,scale:1};for(const s of f.scenes)if(s.stand)for(const L of s.stand.layers)"
               "{L.start={...I};L.end={...I};delete L.keys;L.phases=[L.phases[0]]}}")


def png(b):
    return cv2.imdecode(np.frombuffer(b, np.uint8), cv2.IMREAD_COLOR)


def heat(diff, gain=8):
    d = np.clip(diff.astype(np.float32) * gain, 0, 255).astype(np.uint8)
    return cv2.applyColorMap(d, cv2.COLORMAP_INFERNO)


def poly_px(points, W, H):
    return np.round(np.array(points, np.float64) * [W, H]).astype(np.int32)


def disk(r):
    r = max(1, int(math.ceil(r)))
    return cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * r + 1, 2 * r + 1))


def allowed_region(scene, assets, W, H):
    """Где картине разрешено меняться: партии (с запасом на взмах и удар) и ярусы с их ходом."""
    region = np.zeros((H, W), np.uint8)
    for L in scene["takes"][scene["active"]]["layers"]:
        if L.get("hidden") or not L.get("amp"):
            continue
        m = np.zeros((H, W), np.uint8)
        pts = poly_px(L["points"], W, H)
        cv2.fillPoly(m, [pts], 255)
        piv = np.array(L["pivot"]) * [W, H]
        r = float(np.max(np.hypot(*(pts - piv).T))) if len(pts) else 0
        grow = 3
        if L["effect"] in ("wing", "strike", "hinge"):
            grow += r * (abs(math.radians(L.get("angle", 18))) * abs(L["amp"]) / 100 + 0.15)
        region |= cv2.dilate(m, disk(grow))
    st = scene.get("stand")
    if st:
        byid = {t["id"]: t for t in st["layers"]}

        def travel(t):
            poses = [t["start"], t["end"]] + [k["pose"] for k in t.get("keys", [])]
            move = max(math.hypot(p["x"] * W, p["y"] * H) for p in poses)
            turn = max(abs(math.radians(p["angle"])) for p in poses)
            grow = max(abs(p["scale"] - 1) for p in poses)
            par = travel(byid[t["parent"]]) if t.get("parent") in byid else (0, 0, 0)
            return move + par[0], turn + par[1], grow + par[2]

        for t in st["layers"]:
            if t.get("hidden"):
                continue
            a = np.zeros((H, W), np.uint8)
            for ph in t["phases"]:
                data, _, _, iw, ih = assets[ph["asset"]]
                im = cv2.imdecode(np.frombuffer(data, np.uint8), cv2.IMREAD_UNCHANGED)
                alpha = im[..., 3] if im.ndim == 3 and im.shape[2] == 4 else np.full(im.shape[:2], 255, np.uint8)
                k = min(W / iw, H / ih)
                w2, h2 = max(1, round(iw * k)), max(1, round(ih * k))
                ox, oy = (W - w2) // 2, (H - h2) // 2
                a[oy:oy + h2, ox:ox + w2] |= (cv2.resize(alpha, (w2, h2), interpolation=cv2.INTER_AREA) > 0).astype(np.uint8) * 255
            ys, xs = np.nonzero(a)
            if not len(xs):
                continue
            piv = np.array(t["pivot"]) * [W, H]
            r = float(np.max(np.hypot(xs - piv[0], ys - piv[1])))
            move, turn, grow = travel(t)
            region |= cv2.dilate(a, disk(move + r * (turn + grow) + 3))
    return region


def blink_closure(peak, open_, pts):
    """Закрытие глаза: доля тёмных пикселей радужки и зрачка, которые на пике ушли под веко.
    Плюс тон века против кожи над глазом (для справки: 0 = как кожа)."""
    H, W = peak.shape[:2]
    m = np.zeros((H, W), np.uint8)
    cv2.fillPoly(m, [pts], 255)
    x, y, w, h = cv2.boundingRect(pts)
    core = cv2.erode(m, disk(max(1, h * 0.15))) > 0
    if not core.any():
        core = m > 0
    L = lambda im: cv2.cvtColor(im, cv2.COLOR_BGR2LAB)[..., 0].astype(np.float32)
    Lo, Lp = L(open_), L(peak)
    thr = np.percentile(Lo[core], 35)
    dark_open = ((Lo < thr) & core).sum()
    dark_peak = ((Lp < thr) & core).sum()
    closure = 1 - dark_peak / max(dark_open, 1)
    skin = np.zeros_like(m)
    cv2.rectangle(skin, (x, max(0, y - int(h * 1.4))), (x + w, max(0, y - int(h * 0.5))), 255, -1)
    tone = float(abs(Lp[core].mean() - Lp[skin > 0].mean()))
    return float(closure), round(tone, 1), (x, y, w, h)


async def run(a):
    out = Path(a.out)
    out.mkdir(parents=True, exist_ok=True)
    film, assets = read(a.project)
    sc = film["scenes"][0]
    rep = {"project": str(a.project), "frames": sc["frames"], "issues": []}
    async with Studio() as s:
        await s.load(a.project)
        W, H = await s.size(a.height)
        rep["size"] = [W, H]

        # 1. Тождество с картиной
        if a.painting:
            with tempfile.TemporaryDirectory() as td:
                ref_spec = {"name": "QA · картина", "painting": str(Path(a.painting).resolve()), "ratio": film["ratio"], "frames": 2}
                fl, asl, _ = Builder(ref_spec).build()
                ref_p = write(fl, asl, Path(td) / "ref.atelier2")
                await s.load(ref_p)
                ref = png(await s.frame_png(0, a.height))
            await s.load(a.project)
            await s.patch(OFF_PARTS)
            await s.patch(STILL_CAMERA)
            await s.patch(STILL_TIERS)
            still = png(await s.frame_png(0, a.height))
            d = np.abs(still.astype(np.int16) - ref.astype(np.int16)).max(2)
            rep["identity"] = {"mean": round(float(d.mean()), 3), "p99": int(np.percentile(d, 99)), "max": int(d.max()),
                               "px_over_8": int((d > 8).sum())}
            cv2.imwrite(str(out / "identity_diff.jpg"), heat(d, 12))
            if d.mean() > 1.0 or (d > 8).sum() > W * H * 0.001:
                rep["issues"].append("Без движения сборка заметно отличается от картины: смотреть identity_diff.jpg (швы ярусов, подложка)")

        # 2. Вне масок
        await s.load(a.project)
        await s.patch(STILL_CAMERA)
        await s.patch(OFF_PARTS)
        await s.patch(STILL_TIERS)
        base = png(await s.frame_png(0, a.height))
        await s.load(a.project)
        await s.patch(STILL_CAMERA)
        events = sorted({e for L in sc["takes"][sc["active"]]["layers"] for e in L.get("events", [])})
        sample = sorted(set(range(0, sc["frames"], max(1, sc["frames"] // 24))) | set(events) | {sc["frames"] - 1})
        region = allowed_region(sc, assets, W, H)
        worst = np.zeros((H, W), np.int16)
        for f in sample:
            fr = png(await s.frame_png(f, a.height))
            worst = np.maximum(worst, np.abs(fr.astype(np.int16) - base.astype(np.int16)).max(2))
        outside = worst.copy()
        outside[region > 0] = 0
        rep["outside"] = {"frames": len(sample), "px_changed": int((outside > 2).sum()), "max": int(outside.max())}
        vis = heat(worst, 6)
        cs, _ = cv2.findContours(region, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        cv2.drawContours(vis, cs, -1, (255, 255, 0), 1)
        cv2.imwrite(str(out / "outside_masks.jpg"), vis)
        if (outside > 2).sum() > 20:
            rep["issues"].append(f"Картина меняется вне партий и ярусов: {(outside > 2).sum()} пикс. (outside_masks.jpg)")

        # 3. Моргания
        rep["blinks"] = []
        for L in sc["takes"][sc["active"]]["layers"]:
            if L["effect"] != "blink" or L.get("hidden") or not L.get("events") or L.get("host"):
                continue
            pts = poly_px(L["points"], W, H)
            e = L["events"][0]
            peak = png(await s.frame_png(min(sc["frames"] - 1, e + int(L.get("hold", 0)) // 2), a.height))
            op = png(await s.frame_png(max(0, e - L["attack"] - 6), a.height))
            c, tone, (x, y, w, h) = blink_closure(peak, op, pts)
            rep["blinks"].append({"part": L["id"], "frame": e, "closure": round(c, 2), "lid_vs_skin_L": tone})
            pad = int(max(w, h) * 1.2)
            crop = lambda im: cv2.resize(im[max(0, y - pad):y + h + pad, max(0, x - pad):x + w + pad], None, fx=4, fy=4, interpolation=cv2.INTER_NEAREST)
            cv2.imwrite(str(out / f"blink_{L['id']}.jpg"), np.hstack([crop(op), crop(peak)]))
            if c < 0.8:
                rep["issues"].append(f"Моргание {L['id']} на кадре {e}: глаз закрыт на {c:.0%}. Маска мала или сдвинута (blink_{L['id']}.jpg)")

        # 4. Жизнь: все кадры в малом размере
        await s.load(a.project)
        color = [png(await s.frame_png(f, a.small)) for f in range(sc["frames"])]
        st = np.stack([cv2.cvtColor(c, cv2.COLOR_BGR2GRAY).astype(np.float32) for c in color])
        d = np.abs(np.diff(st, axis=0)).mean((1, 2))
        d2 = np.maximum(d[:-1], d[1:]) if len(d) > 1 else d
        runs, run = [], 0
        for i, v in enumerate(d2):
            run = run + 1 if v < a.still else 0
            if run == int(1.5 * FPS):
                runs.append(i - run + 1)
        rep["frozen_from_frames"] = runs
        if runs:
            rep["issues"].append(f"Застывшие участки дольше 1,5 с с кадров {runs}")
        cam = sc.get("stand", {}).get("camera")
        if cam and (cam.get("keys") or cam["start"] != cam["end"] or cam["start"] != {"x": 0, "y": 0, "angle": 0, "scale": 1}):
            await s.patch(STILL_CAMERA)  # карта движения без камеры: видно, что живёт само
            st = np.stack([cv2.cvtColor(png(await s.frame_png(f, a.small)), cv2.COLOR_BGR2GRAY).astype(np.float32)
                           for f in range(0, sc["frames"], 2)])
        motion = st.std(0)
        motion = np.clip(motion / max(np.percentile(motion, 99.5), 1e-3) * 255, 0, 255).astype(np.uint8)
        cv2.imwrite(str(out / "motion_map.jpg"), cv2.applyColorMap(motion, cv2.COLORMAP_INFERNO))
        thumbs = []
        for f in range(0, sc["frames"], FPS):
            t = color[f].copy()
            cv2.putText(t, f"{f // FPS}s f{f}", (6, 18), cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 255, 255), 2, cv2.LINE_AA)
            thumbs.append(t)
        cols = 5
        while len(thumbs) % cols:
            thumbs.append(np.zeros_like(thumbs[0]))
        sheet = np.vstack([np.hstack(thumbs[i:i + cols]) for i in range(0, len(thumbs), cols)])
        cv2.imwrite(str(out / "contact_sheet.jpg"), sheet, [cv2.IMWRITE_JPEG_QUALITY, 88])
        rep["page_errors"] = s.errors[:20]
        if s.errors:
            rep["issues"].append("Ошибки в консоли ATELIER: " + "; ".join(s.errors[:3]))
    (out / "report.json").write_text(json.dumps(rep, ensure_ascii=False, indent=1), encoding="utf-8")
    lines = [f"Проверка {Path(a.project).name}: {sc['frames']} кадров, {W}×{H}"]
    if "identity" in rep:
        lines.append(f"Тождество с картиной: средняя разница {rep['identity']['mean']}, p99 {rep['identity']['p99']}, макс {rep['identity']['max']}")
    lines.append(f"Вне масок ({rep['outside']['frames']} кадров): изменилось {rep['outside']['px_changed']} пикс., макс {rep['outside']['max']}")
    for b in rep["blinks"]:
        lines.append(f"Моргание {b['part']} (кадр {b['frame']}): закрытие {b['closure']:.0%}, тон века против кожи {b['lid_vs_skin_L']}")
    lines.append("Застывших участков нет" if not runs else f"Застывшие участки: {runs}")
    lines.append("ИТОГ: " + ("замечаний нет" if not rep["issues"] else "; ".join(rep["issues"])))
    (out / "report.txt").write_text("\n".join(lines), encoding="utf-8")
    print("\n".join(lines))
    return 1 if rep["issues"] else 0


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("project")
    p.add_argument("--painting", help="исходная картина для проверки тождества")
    p.add_argument("--out", default="qa")
    p.add_argument("--height", type=int, default=720)
    p.add_argument("--small", type=int, default=216, help="высота кадров для проверки жизни и листа")
    p.add_argument("--still", type=float, default=0.02, help="порог «кадр не изменился», уровни 0..255")
    sys.exit(asyncio.run(run(p.parse_args())))
