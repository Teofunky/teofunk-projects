"""Девочка с раковиной: описание сцены для atelier-kit (ключи считаются здесь)."""
import json, math
import cv2
import numpy as np

FPS = 24
DUR = 15.0
N = int(DUR * FPS)
T = [f / FPS for f in range(0, N, 2)]          # ключи на двойках
W, H = 1456, 816

def sm(x):  # smoothstep 0..1
    x = min(1.0, max(0.0, x)); return x * x * (3 - 2 * x)

def bump(t, a, b):  # 0→1→0 на отрезке [a, b]
    if t <= a or t >= b: return 0.0
    u = (t - a) / (b - a); return math.sin(math.pi * u) ** 2

def interp(t, pts, ease=sm):  # ключевые значения [(t, v)], сглаженно
    if t <= pts[0][0]: return pts[0][1]
    for (t0, v0), (t1, v1) in zip(pts, pts[1:]):
        if t <= t1:
            return v0 + (v1 - v0) * ease((t - t0) / (t1 - t0))
    return pts[-1][1]

def keys(fn):
    out = []
    for t in T:
        dx, dy, ang, sc = fn(t)
        out.append({"t": round(t, 5), "dx": round(dx, 3), "dy": round(dy, 3), "angle": round(ang, 4), "scale": round(sc, 5)})
    return out

# ---------- девочка ----------
STEPS = [("legB", 0.15), ("legF", 0.85), ("legB", 1.55), ("legF", 2.25), ("legB", 2.95), ("legF", 3.65)]
LIFT = 0.55
def leg(name):
    def fn(t):
        dy = dx = 0.0
        for n, a in STEPS:
            if n == name:
                b = bump(t, a, a + LIFT); dy -= 12 * b; dx += (2.5 if n == "legF" else -1.8) * math.sin(math.pi * min(1, max(0, (t - a) / LIFT)))
        return dx, dy, 0.0, 1.0
    return fn

BODY_PIV = (433, 770)
def body_pose(t):
    ang = dx = dy = 0.0
    for n, a in STEPS:  # вес на опорную ногу: тело к ней
        s = bump(t, a - 0.1, a + LIFT + 0.1)
        side = 1 if n == "legB" else -1   # поднята задняя (левая) нога → вес вправо
        ang += 0.6 * side * s; dx += 3.0 * side * s; dy -= 2.0 * s
    # замах назад и бросок вперёд
    ang += interp(t, [(4.35, 0), (4.85, -1.2), (5.15, 1.6), (5.8, 0.6), (7.5, 0.0)])
    # дыхание
    dy += 1.0 * math.sin(2 * math.pi * t / 3.4)
    return dx, dy, ang, 1.0

ELBOW = (491, 342)
WU, TH, FT = 4.35, 4.85, 5.15          # замах, бросок, проводка
def arm_angle(t):
    base = 2.0 * math.sin(2 * math.pi * t / 3.4)
    if t < WU: return base
    if t < TH: u = sm((t - WU) / (TH - WU)); return base * (1 - u) - 26 * u
    if t < FT: u = (t - TH) / (FT - TH); return -26 + 78 * (u ** 2.2)   # разгон до +52
    if t < FT + 0.2: return 52 + 8 * sm((t - FT) / 0.2)
    return interp(t, [(FT + 0.2, 60), (6.4, 42), (15.0, 22)]) + 1.5 * math.sin(2 * math.pi * t / 2.9)

def rot(p, c, deg):
    r = math.radians(deg); x, y = p[0] - c[0], p[1] - c[1]
    return (c[0] + x * math.cos(r) - y * math.sin(r), c[1] + x * math.sin(r) + y * math.cos(r))

def body_map(p, t):
    dx, dy, ang, _ = body_pose(t)
    q = rot(p, BODY_PIV, ang); return (q[0] + dx, q[1] + dy)

# раковина: до броска в руке, потом дуга к морю под солнцем
SHELL_C = (507, 224)
LAND = (992, 494)
# момент отпуска: угол руки проходит +20°
TR = next(t for t in [TH + i / 1000 for i in range(400)] if arm_angle(t) >= 20)
FLIGHT = 1.45
TL = TR + FLIGHT
def shell_world(t):
    tt = min(t, TR)
    c = body_map(rot(SHELL_C, ELBOW, arm_angle(tt)), tt)
    ang = body_pose(tt)[2] + arm_angle(tt)
    if t <= TR: return c, ang, 1.0
    u = min(1.0, (t - TR) / FLIGHT); v = 1 - (1 - u) ** 1.35
    p0, p3 = c, LAND
    p1 = (p0[0] + 120, p0[1] - 150)          # вылет вверх и вправо по ходу руки
    p2 = (p3[0] - 55, 40)                     # высоко над тёмным небом, падение слева от солнца
    x = (1 - v) ** 3 * p0[0] + 3 * (1 - v) ** 2 * v * p1[0] + 3 * (1 - v) * v * v * p2[0] + v ** 3 * p3[0]
    y = (1 - v) ** 3 * p0[1] + 3 * (1 - v) ** 2 * v * p1[1] + 3 * (1 - v) * v * v * p2[1] + v ** 3 * p3[1]
    return (x, y), ang + 620 * v, 1.0 - 0.8 * v ** 0.8

def shell_pose(t):
    (x, y), ang, sc = shell_world(t)
    return x - SHELL_C[0], y - SHELL_C[1], ang, sc

# ---------- рыбы ----------
FISH = {"f01": (229, 67, 116), "f02": (585, 58, 85), "f03": (689, 67, 71), "f04": (925, 63, 115), "f05": (750, 137, 62),
        "f06": (622, 177, 73), "f07": (58, 185, 59), "f08": (845, 216, 65), "f09": (181, 250, 67), "f10": (680, 286, 52),
        "f11": (1332, 119, 54)}
FISH_TOP = {k: int(np.nonzero(np.load(f"alpha_{k}.npy") > 0.05)[0].min()) for k in FISH}
rng = np.random.default_rng(11)
FP = {k: dict(v=rng.uniform(5.0, 8.0), ph=rng.uniform(0, 6.28), bob=rng.uniform(3.5, 6.0), per=rng.uniform(2.2, 3.2),
              tail=rng.uniform(0.55, 0.85), tph=rng.uniform(0, 1)) for k in FISH}
def fish_fn(k):
    cx, cy, w = FISH[k]; p = FP[k]
    dist = math.hypot(cx - LAND[0], cy - LAND[1])
    ts = TL + 0.12 + dist / 2200                    # чем ближе к всплеску, тем раньше
    power = max(0.35, 1.25 - dist / 1100)            # ближние сильнее
    away = np.array([cx - LAND[0], cy - LAND[1]], float); away /= np.linalg.norm(away)
    dash = np.array([0.35 * away[0] + 0.25, -1.0]) ; dash /= np.linalg.norm(dash)  # вверх и чуть от всплеска
    D = 85 * power
    def fn(t):
        dx = p["v"] * t; dy = p["bob"] * math.sin(2 * math.pi * t / p["per"] + p["ph"])
        ang = -3.5 * math.cos(2 * math.pi * t / p["per"] + p["ph"])
        if t > ts:
            u = (t - ts) / 0.45
            e = 1 - (1 - min(u, 1)) ** 3                # резкий рывок
            room = max(0.0, cy - FISH_TOP[k] - 6)          # не уходить за верхний край кадра
            dyd = max(D * dash[1], -room)
            dxd = D * dash[0] + 0.6 * (D * abs(dash[1]) - abs(dyd))
            dx += dxd * e + (t - ts) * p["v"] * 2.5
            dy += dyd * e
            ang += -16 * power * bump(t, ts, ts + 0.9)   # нос вверх при рывке
            dy += 0.0
        return dx, dy, ang, 1.0
    return fn, ts

parts = []
tiers = []
for k, (cx, cy, w) in FISH.items():
    fn, ts = fish_fn(k)
    tiers.append({"id": k, "name": f"Рыбка {k[1:]}", "image": f"L_{k}.png", "pivot": [cx, cy], "depth": 0.02, "keys": keys(fn), "curve": "linear"})
    a = np.load(f"alpha_{k}.npy"); ys, xs = np.nonzero(a > 0.05)
    x0, x1 = int(xs.min()), int(xs.max()); cut = round(x0 + 0.34 * (x1 - x0), 1)
    col = ys[(xs > cut - 2) & (xs < cut + 2)]
    py = float(col.mean()) if len(col) else cy
    y0, y1 = int(ys.min()), int(ys.max())
    tail = [[x0 - 4, y0 - 4], [cut, y0 - 4], [cut, y1 + 4], [x0 - 4, y1 + 4]]
    parts.append({"id": f"tail-{k}", "name": f"Хвост {k[1:]}", "effect": "hinge", "shape": {"poly": tail}, "pivot": [cut, round(py, 1)],
                  "angle": 16, "amp": 100, "feather": 3, "mode": "loop", "period": FP[k]["tail"], "phase": int(FP[k]["tph"] * 24), "host": k})

tiers += [
    {"id": "legB", "name": "Левая нога", "image": "L_legB.png", "pivot": [402, 765], "depth": 0.05, "keys": keys(leg("legB")), "curve": "linear"},
    {"id": "legF", "name": "Правая нога", "image": "L_legF.png", "pivot": [440, 778], "depth": 0.06, "keys": keys(leg("legF")), "curve": "linear"},
    {"id": "body", "name": "Девочка", "image": "L_body.png", "pivot": list(BODY_PIV), "depth": 0.1, "keys": keys(body_pose), "curve": "linear"},
    {"id": "hand", "name": "Рука с кистью", "image": "L_hand.png", "pivot": list(ELBOW), "depth": 0.12, "parent": "body",
     "keys": keys(lambda t: (0, 0, arm_angle(t), 1.0)), "curve": "linear"},
    {"id": "shell", "name": "Раковина", "pivot": list(SHELL_C), "depth": 0.11, "keys": keys(shell_pose), "curve": "linear",
     "phases": [{"t": 0, "image": "L_shell.png", "id": "shell-0"}, {"t": round(TL, 4), "image": "empty.png", "id": "empty"}]},
]
# круги на воде: фазы, нарисованные ripples.py
import os
RIP = json.load(open("ripples.json")) if os.path.exists("ripples.json") else []
tiers.append({"id": "ripples", "name": "Круги на воде", "pivot": list(LAND), "depth": 0.01,
              "phases": [{"t": 0, "image": "empty.png", "id": "empty"}] + [{"t": r["t"], "image": r["image"], "id": r["id"]} for r in RIP]})

parts += [
    {"id": "hair", "name": "Волосы на ветру", "effect": "flex", "host": "body",
     "shape": {"poly": [[228, 92], [300, 78], [380, 74], [425, 82], [418, 120], [412, 160], [404, 206], [370, 214], [320, 198], [272, 168], [236, 138]]},
     "pivot": [415, 140], "target": [235, 118], "amp": 10, "feather": 8, "mode": "loop", "period": 1.9},
    {"id": "skirt", "name": "Юбка качается", "effect": "flex", "host": "body",
     "shape": {"poly": [[300, 470], [520, 470], [558, 580], [566, 602], [532, 620], [465, 625], [390, 624], [315, 616], [258, 600], [258, 560], [280, 500]]},
     "pivot": [440, 400], "target": [620, 440], "amp": 4, "feather": 10, "mode": "loop", "period": 1.4},
    {"id": "sea-far", "name": "Море даль", "effect": "wave", "shape": {"rect": [0, 459, 1456, 545]}, "amp": 55, "feather": 10, "mode": "loop", "period": 2.4},
    {"id": "sea-mid", "name": "Море", "effect": "wave", "shape": {"poly": [[0, 540], [1456, 540], [1456, 668], [760, 668], [520, 650], [300, 642], [0, 618]]},
     "amp": 95, "feather": 12, "mode": "loop", "period": 1.8},
    {"id": "sea-near", "name": "Прибой", "effect": "wave", "shape": {"poly": [[640, 664], [1456, 660], [1456, 816], [1010, 816], [860, 766], [720, 712]]},
     "amp": 95, "feather": 14, "mode": "loop", "period": 1.5},
    {"id": "foam-left", "name": "Пена у берега", "effect": "wave", "shape": {"poly": [[0, 588], [330, 610], [330, 650], [0, 640]]},
     "amp": 80, "feather": 8, "mode": "loop", "period": 1.7},
    {"id": "sun", "name": "Солнце", "effect": "light", "shape": {"ellipse": [1121, 250, 184, 184]}, "amp": 18, "feather": 24, "mode": "loop", "period": 4.0},
]
for i, (x, y, per) in enumerate([(297, 318, 2.3), (568, 312, 3.1), (1238, 58, 2.7), (1353, 58, 3.6), (1383, 342, 2.9)]):
    parts.append({"id": f"star{i + 1}", "name": f"Звезда {i + 1}", "effect": "light", "shape": {"ellipse": [x, y, 12, 12]},
                  "amp": 90, "feather": 8, "mode": "loop", "period": per, "phase": int(i * 11)})
for i, (x, y) in enumerate([(124, 150), (858, 337)]):
    parts.append({"id": f"moon{i + 1}", "name": f"Месяц {i + 1}", "effect": "light", "shape": {"ellipse": [x, y, 20, 20]},
                  "amp": 45, "feather": 10, "mode": "loop", "period": 4.4 + i})

CAM0, CAM1, ZOOM = TL + 0.15, 12.5, 1.6
CEN = (W / 2, H / 2)
def cam(t):
    u = sm((t - CAM0) / (CAM1 - CAM0)) if t > CAM0 else 0.0
    s_ = 1 + (ZOOM - 1) * u + (0.06 * (t - CAM1) / (DUR - CAM1) if t > CAM1 else 0)
    return s_ * u * (LAND[0] - CEN[0]), s_ * u * (LAND[1] - CEN[1]), 0.0, s_
CAMKEYS = [{"t": k["t"], "dx": k["dx"], "dy": k["dy"], "zoom": k["scale"]} for k in keys(cam)]

spec = {"name": "Девочка с раковиной", "id": "girlshell01", "painting": "painting.png", "plate": "plate.png", "ratio": "auto",
        "seconds": DUR, "exposure": 2,
        "notes": (f"v5. Рыбки плывут, море волнуется. Девочка переступает с ноги на ногу (0,15-4,2 с), замах 4,35 с, бросок, "
                  f"раковина отпущена на {TR:.2f} с и падает в море под солнцем на {TL:.2f} с, круги на воде, рыбы шарахаются. Камера наплывает на круги до 1,6."),
        "parts": parts, "tiers": tiers, "camera": {"keys": CAMKEYS, "curve": "linear"}}
json.dump(spec, open("spec.json", "w"), ensure_ascii=False, indent=0)
json.dump({"TR": TR, "TL": TL, "LAND": LAND}, open("timing.json", "w"))
print(f"release {TR:.3f} s, landing {TL:.3f} s (frame {TL * FPS:.1f}), tiers {len(tiers)}, parts {len(parts)}")
