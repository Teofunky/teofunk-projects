"""Разметка картины для ATELIER: сетка координат, увеличенные вырезки, выделение объекта,
вырезка ярусов и чистая подложка под ними.

Координаты везде в пикселях исходной картины (как в spec.json).

python3 mark.py grid картина.png out.jpg [--step 50]
python3 mark.py zoom картина.png out.jpg x0 y0 x1 y1 [--step 10] [--scale 6]
python3 mark.py segment картина.png x0 y0 x1 y1 [--out mask.png]      → контур объекта JSON
python3 mark.py overlay картина.png spec.json out.jpg                  → маски партий поверх картины
"""
import argparse
import json
import math
import sys
from pathlib import Path

import cv2
import numpy as np

FONT = cv2.FONT_HERSHEY_SIMPLEX


def load(path):
    im = cv2.imread(str(path), cv2.IMREAD_UNCHANGED)
    if im is None:
        raise FileNotFoundError(path)
    if im.ndim == 2:
        im = cv2.cvtColor(im, cv2.COLOR_GRAY2BGR)
    return im


def label(img, text, org, scale=0.45):
    cv2.putText(img, text, org, FONT, scale, (0, 0, 0), 3, cv2.LINE_AA)
    cv2.putText(img, text, org, FONT, scale, (255, 255, 255), 1, cv2.LINE_AA)


def grid(painting, out, step=50, box=None, scale=1.0):
    """Сетка с подписями в пикселях картины. box=(x0,y0,x1,y1) и scale дают увеличенную вырезку."""
    im = load(painting)[..., :3]
    x0, y0, x1, y1 = box or (0, 0, im.shape[1], im.shape[0])
    crop = cv2.resize(im[y0:y1, x0:x1], None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC if scale > 1 else cv2.INTER_AREA)
    g = crop.copy()
    for x in range(math.ceil(x0 / step) * step, x1, step):
        X = int((x - x0) * scale)
        major = x % (step * 2) == 0
        cv2.line(g, (X, 0), (X, g.shape[0]), (0, 255, 255) if major else (0, 160, 255), 1)
        if major:
            label(g, str(x), (X + 2, 14))
    for y in range(math.ceil(y0 / step) * step, y1, step):
        Y = int((y - y0) * scale)
        major = y % (step * 2) == 0
        cv2.line(g, (0, Y), (g.shape[1], Y), (0, 255, 255) if major else (0, 160, 255), 1)
        if major:
            label(g, str(y), (2, Y - 3))
    g = cv2.addWeighted(g, 0.55, crop, 0.45, 0)
    cv2.imwrite(str(out), g, [cv2.IMWRITE_JPEG_QUALITY, 90])
    return out


def poly_mask(shape_hw, polys, feather=0.0):
    """Маска 0..1 по многоугольникам (сглаженный край)."""
    h, w = shape_hw
    ss = 4
    m = np.zeros((h * ss, w * ss), np.uint8)
    for p in polys:
        cv2.fillPoly(m, [np.round(np.array(p, np.float64) * ss).astype(np.int32)], 255, cv2.LINE_AA)
    m = cv2.resize(m, (w, h), interpolation=cv2.INTER_AREA).astype(np.float32) / 255
    if feather > 0:
        m = cv2.GaussianBlur(m, (0, 0), feather)
    return m


def contour_of(m, eps=1.2):
    m = cv2.morphologyEx(m, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    cs, _ = cv2.findContours(m, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    if not cs:
        raise RuntimeError("объект не найден")
    c = cv2.approxPolyDP(max(cs, key=cv2.contourArea), eps, True)[:, 0, :]
    return [[int(x), int(y)] for x, y in c]


def merge(painting, polys, eps=1.2):
    """Объединение контуров (выделение плюс дорисованный руками плавник) в один контур."""
    h, w = load(painting).shape[:2]
    m = np.zeros((h, w), np.uint8)
    for p in polys:
        cv2.fillPoly(m, [np.array(p, np.int32)], 255)
    return contour_of(m, eps)


def segment(painting, box, hint=None, iters=6, method="color", close=5, bias=1.0, fins=0, fin_bias=0.45):
    """Объект внутри рамки → контур в пикселях картины.
    method="color": отличие цвета от фона по краю рамки (Lab, порог Отсу). Хорошо для фигур на ровном фоне,
    тонкие плавники и хвосты не теряет. method="grabcut": для пёстрого фона, hint = многоугольник «точно объект»."""
    im = load(painting)[..., :3]
    x0, y0, x1, y1 = map(int, box)
    if method == "color":
        lab = cv2.cvtColor(cv2.GaussianBlur(im[y0:y1, x0:x1], (0, 0), 1.2), cv2.COLOR_BGR2LAB).astype(np.float32)
        border = np.concatenate([lab[0], lab[-1], lab[:, 0], lab[:, -1]])
        d = np.linalg.norm(lab - np.median(border, 0), axis=2)
        d = np.clip(d / max(np.percentile(d, 99), 1) * 255, 0, 255).astype(np.uint8)
        t, _ = cv2.threshold(d, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        _, sub = cv2.threshold(d, t * bias, 255, cv2.THRESH_BINARY)  # bias < 1 захватывает тёмные края и плавники
        sub = cv2.morphologyEx(sub, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (close, close)))
        if hint:
            cv2.fillPoly(sub, [np.array(hint, np.int32) - [x0, y0]], 255)
        n, lab_cc, stats, _ = cv2.connectedComponentsWithStats(sub)
        if n < 2:
            raise RuntimeError("объект не найден")
        sub = np.where(lab_cc == 1 + np.argmax(stats[1:, cv2.CC_STAT_AREA]), 255, 0).astype(np.uint8)
        if fins:  # тонкие тёмные плавники и хвосты: низкий порог, но только рядом с телом
            near = cv2.dilate(sub, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * fins + 1, 2 * fins + 1)))
            low = ((d > t * fin_bias) & (near > 0)).astype(np.uint8) * 255
            low = cv2.morphologyEx(low, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
            n2, cc2 = cv2.connectedComponents(low | sub)
            keep = np.unique(cc2[sub > 0])
            sub = np.isin(cc2, keep[keep > 0]).astype(np.uint8) * 255
        filled = sub.copy()
        cs, _ = cv2.findContours(sub, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
        cv2.drawContours(filled, cs, -1, 255, -1)
        m = np.zeros(im.shape[:2], np.uint8)
        m[y0:y1, x0:x1] = filled
        return contour_of(m), m
    mask = np.full(im.shape[:2], cv2.GC_BGD, np.uint8)
    mask[y0:y1, x0:x1] = cv2.GC_PR_FGD
    if hint:
        cv2.fillPoly(mask, [np.array(hint, np.int32)], cv2.GC_FGD)
    bg, fg = np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64)
    cv2.grabCut(im, mask, None, bg, fg, iters, cv2.GC_INIT_WITH_MASK)
    m = np.where((mask == cv2.GC_FGD) | (mask == cv2.GC_PR_FGD), 255, 0).astype(np.uint8)
    return contour_of(m), m


def matte(painting, box, fins=14, lo_pct=75, close=5):
    """Мягкая маска фигуры на ровном фоне (0..1, размер картины): тело по порогу Отсу непрозрачно,
    тонкие тёмные плавники и хвосты рядом с телом получают частичную прозрачность по силе цвета.
    Звёзды и пятна, не связанные с телом, отбрасываются. Для фигур на тёмном небе лучше, чем контур."""
    im = load(painting)[..., :3]
    x0, y0, x1, y1 = map(int, box)
    lab = cv2.cvtColor(cv2.GaussianBlur(im[y0:y1, x0:x1], (0, 0), 0.8), cv2.COLOR_BGR2LAB).astype(np.float32)
    ring = np.concatenate([lab[:2].reshape(-1, 3), lab[-2:].reshape(-1, 3), lab[:, :2].reshape(-1, 3), lab[:, -2:].reshape(-1, 3)])
    d = np.linalg.norm(lab - np.median(ring, 0), axis=2)
    dn = np.clip(d / max(np.percentile(d, 99), 1) * 255, 0, 255).astype(np.uint8)
    t, _ = cv2.threshold(dn, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    t = t / 255 * max(np.percentile(d, 99), 1)
    body = (d > t).astype(np.uint8) * 255
    body = cv2.morphologyEx(body, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (close, close)))
    n, cc, st, _ = cv2.connectedComponentsWithStats(body)
    body = np.where(cc == 1 + np.argmax(st[1:, cv2.CC_STAT_AREA]), 255, 0).astype(np.uint8)
    cs, _ = cv2.findContours(body, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    cv2.drawContours(body, cs, -1, 255, -1)
    # проекция на ось «цвет фона → цвет фигуры»: пятна другого оттенка (синее небо) не попадают в маску
    bg = np.median(ring, 0)
    v = np.median(lab[body > 0], 0) - bg
    proj = ((lab - bg) @ v) / max(float(v @ v), 1e-3)
    rproj = ((ring - bg) @ v) / max(float(v @ v), 1e-3)
    body = ((body > 0) & (proj > 0.35)).astype(np.uint8) * 255  # из тела тоже убрать пятна чужого оттенка
    n, cc, st, _ = cv2.connectedComponentsWithStats(cv2.morphologyEx(body, cv2.MORPH_CLOSE, np.ones((3, 3), np.uint8)))
    body = np.where(cc == 1 + np.argmax(st[1:, cv2.CC_STAT_AREA]), 255, 0).astype(np.uint8)
    cs, _ = cv2.findContours(body, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    cv2.drawContours(body, cs, -1, 255, -1)
    lo = max(0.08, np.percentile(rproj, lo_pct + 20))
    hi = max(lo + 0.15, 0.55)
    near = cv2.dilate(body, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * fins + 1, 2 * fins + 1))) > 0
    a = np.clip((proj - lo) / (hi - lo), 0, 1)
    a = a * a * (3 - 2 * a) * near
    n, cc = cv2.connectedComponents(((a > 0.2) | (body > 0)).astype(np.uint8))
    keep = np.isin(cc, np.unique(cc[body > 0]))
    a = a * (cv2.dilate(keep.astype(np.uint8), np.ones((3, 3), np.uint8)) > 0)
    a = np.maximum(a, body / 255.0)
    a = cv2.GaussianBlur(a.astype(np.float32), (0, 0), 0.6)
    full = np.zeros(im.shape[:2], np.float32)
    full[y0:y1, x0:x1] = a
    return full


def refine(painting, polys, band=6, iters=5, keep=None):
    """Уточнить грубый многоугольник по краю нарисованной фигуры (GrabCut в полосе ±band пикселей).
    Внутри полосы решает цвет, глубже внутри точно фигура, дальше снаружи точно фон.
    keep: многоугольники, которые обязательно фигура (тонкие места). Возвращает маску 0/255."""
    im = load(painting)[..., :3]
    m = (poly_mask(im.shape[:2], polys) > 0.5).astype(np.uint8)
    k = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * band + 1, 2 * band + 1))
    gc = np.full(im.shape[:2], cv2.GC_BGD, np.uint8)
    gc[cv2.dilate(m, k) > 0] = cv2.GC_PR_BGD
    gc[m > 0] = cv2.GC_PR_FGD
    gc[cv2.erode(m, k) > 0] = cv2.GC_FGD
    if keep:
        gc[poly_mask(im.shape[:2], keep) > 0.5] = cv2.GC_FGD
    ys, xs = np.nonzero(gc != cv2.GC_BGD)
    y0, y1, x0, x1 = max(0, ys.min() - 20), ys.max() + 20, max(0, xs.min() - 20), xs.max() + 20
    sub = gc[y0:y1, x0:x1].copy()
    bg, fg = np.zeros((1, 65), np.float64), np.zeros((1, 65), np.float64)
    cv2.grabCut(im[y0:y1, x0:x1], sub, None, bg, fg, iters, cv2.GC_INIT_WITH_MASK)
    out = np.zeros(im.shape[:2], np.uint8)
    out[y0:y1, x0:x1] = np.where((sub == cv2.GC_FGD) | (sub == cv2.GC_PR_FGD), 255, 0)
    out = cv2.morphologyEx(out, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
    n, cc, st, _ = cv2.connectedComponentsWithStats(out)
    if n > 1:
        out = np.where(cc == 1 + np.argmax(st[1:, cv2.CC_STAT_AREA]), 255, 0).astype(np.uint8)
    cs, _ = cv2.findContours(out, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_NONE)
    cv2.drawContours(out, cs, -1, 255, -1)
    return out


def soft(mask, grow=2, feather=0.8):
    """Маска 0/255 → альфа яруса 0..1 с запасом grow и мягким краем (как cutout)."""
    a = (mask > 0).astype(np.float32)
    r = int(grow + math.ceil(2 * feather))
    if r > 0:
        a = cv2.dilate(a, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * r + 1, 2 * r + 1)))
    return cv2.GaussianBlur(a, (0, 0), feather) if feather > 0 else a


def cutout_alpha(painting, alpha, out):
    """Ярус по мягкой маске matte()."""
    bgr = load(painting)[..., :3]
    rgba = np.dstack([bgr, np.clip(alpha * 255, 0, 255).round().astype(np.uint8)])
    rgba[alpha <= 0.002, :3] = 0
    cv2.imwrite(str(out), rgba, [cv2.IMWRITE_PNG_COMPRESSION, 9])
    return out


def plate_alpha(painting, alphas, out, radius=7, seed=7, extra=None, method="telea"):
    """Подложка под ярусами из мягких масок (и под многоугольниками extra)."""
    im = load(painting)[..., :3]
    m = np.zeros(im.shape[:2], np.uint8)
    for a in alphas:
        m |= ((a > 0.02) * 255).astype(np.uint8)
    if extra:
        m |= ((poly_mask(im.shape[:2], extra) > 0.02) * 255).astype(np.uint8)
    m = cv2.dilate(m, np.ones((3, 3), np.uint8))
    return _fill(im, m, out, radius, seed, method)


def fill_rows(im, m, side=12):
    """Дыра заполняется по строкам: медиана фона слева и справа, между ними плавный переход.
    Для неба, моря и песка с горизонтальными слоями; волоски и пятна у края не растекаются, как у Telea."""
    out = im.astype(np.float32).copy()
    H, W = m.shape
    for y in range(H):
        row = m[y] > 0
        if not row.any():
            continue
        xs = np.flatnonzero(np.diff(np.concatenate([[0], row.astype(np.int8), [0]])))
        for a, b in zip(xs[::2], xs[1::2]):
            L = im[y, max(0, a - side):a].reshape(-1, 3)
            R = im[y, b:min(W, b + side)].reshape(-1, 3)
            lv = np.median(L, 0) if len(L) else None
            rv = np.median(R, 0) if len(R) else None
            lv = rv if lv is None else lv
            rv = lv if rv is None else rv
            if lv is None:
                continue
            t = np.linspace(0, 1, b - a)[:, None]
            out[y, a:b] = lv * (1 - t) + rv * t
    out = np.where(m[..., None] > 0, cv2.GaussianBlur(out, (0, 0), 1.2), out)
    return out


def _fill(im, m, out, radius, seed, method="telea"):
    if method == "rows":
        filled = fill_rows(im, m)
        v = cv2.blur(filled, (1, 9))  # сгладить ступеньки между строками
        filled = np.where(m[..., None] > 0, v, filled)
    else:
        filled = cv2.inpaint(im, m, radius, cv2.INPAINT_TELEA).astype(np.float32)
    ring = cv2.dilate(m, np.ones((31, 31), np.uint8)) & ~m
    hp = im.astype(np.float32) - cv2.GaussianBlur(im.astype(np.float32), (0, 0), 2.0)
    sd = hp[ring > 0].std(axis=0) if ring.any() else np.full(3, 3.0)
    rng = np.random.default_rng(seed)
    noise = cv2.GaussianBlur(rng.normal(0, 1, im.shape).astype(np.float32), (0, 0), 0.7) * 1.6 * sd
    k = (m.astype(np.float32) / 255)[..., None]
    res = im.astype(np.float32) * (1 - k) + (filled + noise) * k
    cv2.imwrite(str(out), np.clip(res, 0, 255).round().astype(np.uint8))
    return out


def cutout(painting, poly, out, feather=0.8, grow=2):
    """Ярус для станка: картина с прозрачностью вне многоугольника, на холсте картины.
    grow: запас наружу в пикселях, чтобы край фигуры целиком ушёл в ярус (иначе на месте
    остаётся светлый или тёмный ободок). Подложку делать с plate(grow) больше этого запаса."""
    im = load(painting)
    bgr = im[..., :3]
    a = poly_mask(bgr.shape[:2], [poly])
    r = int(grow + math.ceil(2 * feather))  # непрозрачное ядро покрывает зону подложки plate(grow)
    if r > 0:
        a = cv2.dilate(a, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * r + 1, 2 * r + 1)))
    if feather > 0:
        a = cv2.GaussianBlur(a, (0, 0), feather)
    rgba = np.dstack([bgr, (a * 255).round().astype(np.uint8)])
    rgba[a <= 0, :3] = 0
    cv2.imwrite(str(out), rgba, [cv2.IMWRITE_PNG_COMPRESSION, 9])
    return out


def plate(painting, polys, out, grow=2, radius=9, seed=7):
    """Чистая подложка: под ярусами закрашено окружением (Telea) плюс зерно той же силы, что вокруг.
    grow тот же, что у cutout: тогда в покое сборка совпадает с картиной пиксель в пиксель.
    Подложку всегда смотреть глазами: на больших дырах inpaint мажет."""
    im = load(painting)[..., :3]
    m = (poly_mask(im.shape[:2], polys) > 0.02).astype(np.uint8) * 255
    m = cv2.dilate(m, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (2 * grow + 1, 2 * grow + 1)))
    return _fill(im, m, out, radius, seed)


def overlay(painting, spec, out):
    """Контуры партий и опор поверх картины: проверка разметки до рендера."""
    sys.path.insert(0, str(Path(__file__).parent))
    from project import shape_points, centroid
    im = load(painting)[..., :3].copy()
    colors = [(0, 255, 255), (255, 0, 255), (0, 255, 0), (255, 128, 0), (0, 128, 255), (255, 255, 0)]
    for i, p in enumerate(spec.get("parts", [])):
        c = colors[i % len(colors)]
        pts = np.array(shape_points(p["shape"]), np.float64)
        cv2.polylines(im, [np.round(pts).astype(np.int32)], True, c, 1, cv2.LINE_AA)
        pv = p.get("pivot") or centroid(pts.tolist())
        cv2.drawMarker(im, tuple(int(v) for v in pv), c, cv2.MARKER_CROSS, 9, 1)
        if p.get("target"):
            cv2.arrowedLine(im, tuple(int(v) for v in pv), tuple(int(v) for v in p["target"]), c, 1, cv2.LINE_AA, tipLength=0.05)
        label(im, p.get("id") or p["name"], (int(pts[:, 0].min()), int(pts[:, 1].min()) - 3), 0.4)
    for t in spec.get("tiers", []):
        cv2.drawMarker(im, tuple(int(v) for v in t["pivot"]), (0, 0, 255), cv2.MARKER_TILTED_CROSS, 11, 2)
        label(im, t["id"], (int(t["pivot"][0]) + 6, int(t["pivot"][1])), 0.4)
    cv2.imwrite(str(out), im, [cv2.IMWRITE_JPEG_QUALITY, 92])
    return out


if __name__ == "__main__":
    a = argparse.ArgumentParser()
    sub = a.add_subparsers(dest="cmd", required=True)
    g = sub.add_parser("grid"); g.add_argument("painting"); g.add_argument("out"); g.add_argument("--step", type=int, default=50)
    z = sub.add_parser("zoom"); z.add_argument("painting"); z.add_argument("out"); z.add_argument("box", type=int, nargs=4)
    z.add_argument("--step", type=int, default=10); z.add_argument("--scale", type=float, default=6)
    s = sub.add_parser("segment"); s.add_argument("painting"); s.add_argument("box", type=int, nargs=4); s.add_argument("--out")
    s.add_argument("--method", default="color", choices=["color", "grabcut"])
    s.add_argument("--bias", type=float, default=1.0)
    o = sub.add_parser("overlay"); o.add_argument("painting"); o.add_argument("spec"); o.add_argument("out")
    x = a.parse_args()
    if x.cmd == "grid":
        print(grid(x.painting, x.out, x.step))
    elif x.cmd == "zoom":
        print(grid(x.painting, x.out, x.step, x.box, x.scale))
    elif x.cmd == "segment":
        poly, m = segment(x.painting, x.box, method=x.method, bias=x.bias)
        if x.out:
            cv2.imwrite(x.out, m)
        print(json.dumps(poly))
    elif x.cmd == "overlay":
        print(overlay(x.painting, json.loads(Path(x.spec).read_text(encoding="utf-8")), x.out))
