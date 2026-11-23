"""Девочка с раковиной v6: мастер-план 30 с (камера стоит, планы режутся в монтаже кропом).
Ноги из двух частей (бедро, голень), перенос веса как у ребёнка: сначала таз на опорную ногу,
потом пятка, потом носок; колено вперёд, стопа назад и вверх."""
import json, math, os
import numpy as np

FPS, DUR = 24, 30.0
N = int(DUR * FPS)
T = [f / FPS for f in range(0, N, 2)]
W, H = 1456, 816

def sm(x):
    x = min(1.0, max(0.0, x)); return x * x * (3 - 2 * x)
def bump(t, a, b):
    if t <= a or t >= b: return 0.0
    return math.sin(math.pi * (t - a) / (b - a)) ** 2
def interp(t, pts, ease=sm):
    if t <= pts[0][0]: return pts[0][1]
    for (t0, v0), (t1, v1) in zip(pts, pts[1:]):
        if t <= t1: return v0 + (v1 - v0) * ease((t - t0) / (t1 - t0))
    return pts[-1][1]
def keys(fn):
    out = []
    for t in T:
        dx, dy, ang, sc = fn(t)
        out.append({"t": round(t, 5), "dx": round(dx, 3), "dy": round(dy, 3), "angle": round(ang, 4), "scale": round(sc, 5)})
    return out

# ---------- переносы веса ----------
# (нога, начало, длительность, сила): сила 1 = шаг на месте с подъёмом стопы, 0.3 = только качнулась
SHIFT = 0.62
SHIFTS = [("legB", 2.4, 1.1, 0.25), ("legF", 5.3, 1.1, 0.3)]                       # в покое лениво качнулась
t0 = 8.3
for i in range(7):                                                                  # переступает 8,3-12,4 с
    SHIFTS.append(("legB" if i % 2 == 0 else "legF", t0 + i * SHIFT, SHIFT, 1.0))
SHIFTS += [("legF", 24.4, 0.9, 0.7), ("legB", 25.4, 0.9, 0.7), ("legF", 26.6, 1.0, 0.5)]   # смотрит на круги

def phases(t, a, d):
    """Фазы одного переноса: w — таз над опорной ногой (0..1), lift — стопа в воздухе (0..1)."""
    u = (t - a) / d
    w = bump(u, -0.15, 1.05)
    lift = sm((u - 0.22) / 0.22) * (1 - sm((u - 0.62) / 0.25))
    heel = sm((u - 0.12) / 0.15) * (1 - sm((u - 0.8) / 0.12))   # пятка уходит раньше и приходит позже
    return w, lift, heel

def leg_lift(name):
    def fn(t):
        dy = 0.0
        for n, a, d, s in SHIFTS:
            if n != name: continue
            w, lift, heel = phases(t, a, d)
            dy += -2.0 * lift * s
        return 0.0, dy, 0.0, 1.0
    return fn

def lift_frames(name, strong=0.4):
    """Кадры пика подъёма стопы: акценты изгиба голени (колено на месте, стопа назад и вверх плавной дугой)."""
    return [int(round((a + d * 0.42) * FPS)) for n, a, d, s in SHIFTS if n == name and s >= strong]

BODY_PIV = (433, 770)
WU, TH, FT = 13.3, 13.8, 14.1
def body_pose(t):
    ang = dx = dy = 0.0
    for n, a, d, s in SHIFTS:
        w, lift, heel = phases(t, a, d)
        side = 1 if n == "legB" else -1               # поднимается левая (задняя) нога → таз вправо
        dx += 4.0 * side * w * s; ang += 0.7 * side * w * s
        dy += 1.6 * w * s - 1.2 * lift * s            # опорная сторона проседает, на подъёме чуть вверх
    ang += interp(t, [(WU, 0), (TH, -0.8), (FT, 1.6), (FT + 0.6, 0.6), (FT + 2.4, 0.0)])
    dy += 1.0 * math.sin(2 * math.pi * t / 3.4)
    return dx, dy, ang, 1.0

ELBOW = (491, 342)
def arm_angle(t):
    base = 2.0 * math.sin(2 * math.pi * t / 3.4)
    if t < WU: return base
    if t < TH: u = sm((t - WU) / (TH - WU)); return base * (1 - u) - 14 * u
    if t < FT: u = (t - TH) / (FT - TH); return -14 + 66 * (u ** 2.2)
    if t < FT + 0.2: return 52 + 8 * sm((t - FT) / 0.2)
    return interp(t, [(FT + 0.2, 60), (FT + 1.3, 44), (FT + 6.0, 18), (DUR, 10)]) + 1.5 * math.sin(2 * math.pi * t / 2.9)

def rot(p, c, deg):
    r = math.radians(deg); x, y = p[0] - c[0], p[1] - c[1]
    return (c[0] + x * math.cos(r) - y * math.sin(r), c[1] + x * math.sin(r) + y * math.cos(r))
def body_map(p, t):
    dx, dy, ang, _ = body_pose(t); q = rot(p, BODY_PIV, ang); return (q[0] + dx, q[1] + dy)

SHELL_C, LAND = (507, 224), (992, 494)
TR = next(t for t in [TH + i / 1000 for i in range(400)] if arm_angle(t) >= 20)
FLIGHT = 1.45
TL = TR + FLIGHT
def shell_world(t):
    tt = min(t, TR)
    c = body_map(rot(SHELL_C, ELBOW, arm_angle(tt)), tt)
    ang = body_pose(tt)[2] + arm_angle(tt)
    if t <= TR: return c, ang, 1.0
    u = min(1.0, (t - TR) / FLIGHT); v = 1 - (1 - u) ** 1.35
    p0, p3 = c, LAND; p1 = (p0[0] + 120, p0[1] - 150); p2 = (p3[0] - 55, 40)
    x = (1-v)**3*p0[0] + 3*(1-v)**2*v*p1[0] + 3*(1-v)*v*v*p2[0] + v**3*p3[0]
    y = (1-v)**3*p0[1] + 3*(1-v)**2*v*p1[1] + 3*(1-v)*v*v*p2[1] + v**3*p3[1]
    return (x, y), ang + 620 * v, 1.0 - 0.8 * v ** 0.8
def shell_pose(t):
    (x, y), ang, sc = shell_world(t); return x - SHELL_C[0], y - SHELL_C[1], ang, sc

# ---------- рыбы ----------
FISH = {"f01": (229, 67, 116), "f02": (585, 58, 85), "f03": (689, 67, 71), "f04": (925, 63, 115), "f05": (750, 137, 62),
        "f06": (622, 177, 73), "f07": (58, 185, 59), "f08": (845, 216, 65), "f09": (181, 250, 67), "f10": (680, 286, 52),
        "f11": (1332, 119, 54)}
FISH_TOP = {k: int(np.nonzero(np.load(f"alpha_{k}.npy") > 0.05)[0].min()) for k in FISH}
rng = np.random.default_rng(11)
FP = {k: dict(v=rng.uniform(2.2, 3.6), ph=rng.uniform(0, 6.28), bob=rng.uniform(3.0, 5.0), per=rng.uniform(2.4, 3.4),
              tail=rng.uniform(0.6, 0.9), tph=rng.uniform(0, 1)) for k in FISH}
# после всплеска рыбы плывут к кругам: думают, что это еда; над водой клюют в долю музыки
BEATS = [TL + (tau - 37.814) for tau, b in json.load(open("beats_B.json")) if tau >= 37.8]
DOWN = [TL + (tau - 37.814) for tau, b in json.load(open("beats_B.json")) if tau >= 37.8 and b == 1]
TARGETS = [(842, 452, .62), (888, 478, .66), (905, 507, .72), (930, 440, .58), (965, 498, .70), (1005, 455, .60),
           (1048, 488, .66), (1075, 515, .74), (1090, 446, .58), (1130, 472, .62), (1170, 500, .68)]
order = sorted(FISH, key=lambda k: FISH[k][0])
TGT = {k: TARGETS[i] for i, k in enumerate(order)}          # слева направо: пути почти не пересекаются
dist = {k: math.hypot(FISH[k][0] - LAND[0], FISH[k][1] - LAND[1]) for k in FISH}
rank = {k: i for i, k in enumerate(sorted(FISH, key=lambda k: dist[k]))}
REACT = {k: TL + 0.25 + 0.07 * rank[k] for k in FISH}
DURS = {k: min(3.6, max(1.6, dist[k] / 330 + 0.9)) for k in FISH}
MIRROR = {"f11"}

def pre(k, t):
    p = FP[k]
    return (p["v"] * t, p["bob"] * math.sin(2 * math.pi * t / p["per"] + p["ph"]), -3.0 * math.cos(2 * math.pi * t / p["per"] + p["ph"]))

def peck(k, t, i):
    """Клевок: нос вниз и к воде на доле, у каждой рыбы свои доли."""
    v = 0.0
    for j, b in enumerate(BEATS):
        if b < ARRIVE[k] + 0.2: continue
        if (j + i) % 3 == 0 or (b in DOWN and i % 2 == 0):
            d = t - b
            if -0.1 < d < 0.4:
                v = max(v, sm((d + 0.1) / 0.1) * (1 - sm((d - 0.05) / 0.35)))
    return v

ARRIVE = {k: REACT[k] + DURS[k] for k in FISH}
def fish_fn(k):
    cx, cy, w = FISH[k]; i = order.index(k); tx, ty, ts_ = TGT[k]; mir = k in MIRROR
    def fn(t):
        dx, dy, ang = pre(k, t)
        if t <= REACT[k]:
            return dx, dy, ang, 1.0
        pdx, pdy, pang = pre(k, REACT[k])
        P0 = (cx + pdx, cy + pdy); P2 = (tx, ty)
        P1 = ((P0[0] + P2[0]) / 2, min(P0[1], P2[1]) - 45)
        u = min(1.0, (t - REACT[k]) / DURS[k]); e = u * u * u * (u * (6 * u - 15) + 10)
        bx = (1 - e) ** 2 * P0[0] + 2 * (1 - e) * e * P1[0] + e * e * P2[0]
        by = (1 - e) ** 2 * P0[1] + 2 * (1 - e) * e * P1[1] + e * e * P2[1]
        e2 = min(1.0, e + 0.02)
        vx = 2 * (1 - e2) * (P1[0] - P0[0]) + 2 * e2 * (P2[0] - P1[0]); vy = 2 * (1 - e2) * (P1[1] - P0[1]) + 2 * e2 * (P2[1] - P1[1])
        head = math.degrees(math.atan2(-vy, -vx) if mir else math.atan2(vy, vx))
        head = max(-35, min(40, head)) * (1 - sm((u - 0.8) / 0.2)) * sm(u / 0.1)
        sc = 1 + (ts_ - 1) * e
        jump = -10 * bump(t, REACT[k] - 0.15, REACT[k] + 0.25)   # встрепенулась: «еда!»
        if t > ARRIVE[k]:
            h = t - ARRIVE[k]
            rx, ry = tx - LAND[0], ty - LAND[1]; rn = math.hypot(rx, ry) or 1
            drift = min(30, 3.0 * h)                                  # тянутся за расходящимися кругами
            bx += drift * rx / rn; by += drift * ry / rn * 0.3
            by += 3 * ts_ * math.sin(2 * math.pi * h / FP[k]["per"] + FP[k]["ph"])
            pk = peck(k, t, i)
            by += 9 * ts_ * pk; head += (-1 if mir else 1) * 28 * pk
            head += (-1 if mir else 1) * 4 * math.sin(2 * math.pi * h / 2.1 + i)
        return bx - cx, by - cy + jump, head, sc
    return fn
parts, tiers = [], []
for k, (cx, cy, w) in FISH.items():
    tier = {"id": k, "name": f"Рыбка {k[1:]}", "image": f"L_{k}.png", "pivot": [cx, cy], "depth": 0.02, "keys": keys(fish_fn(k)), "curve": "linear"}
    if k in MIRROR:
        tier.pop("image")
        tier["phases"] = [{"t": 0, "image": f"L_{k}.png", "id": f"{k}-0"}, {"t": round(REACT[k] + 0.12, 4), "image": f"L_{k}_m.png", "id": f"{k}-m"}]
    tiers.append(tier)
    a = np.load(f"alpha_{k}.npy"); ys, xs = np.nonzero(a > 0.05)
    x0, x1 = int(xs.min()), int(xs.max()); cut = round(x0 + 0.34 * (x1 - x0), 1)
    col = ys[(xs > cut - 2) & (xs < cut + 2)]; py = float(col.mean()) if len(col) else cy
    y0, y1 = int(ys.min()), int(ys.max())
    parts.append({"id": f"tail-{k}", "name": f"Хвост {k[1:]}", "effect": "hinge", "shape": {"poly": [[x0 - 4, y0 - 4], [cut, y0 - 4], [cut, y1 + 4], [x0 - 4, y1 + 4]]},
                  "pivot": [cut, round(py, 1)], "angle": 14, "amp": 100, "feather": 3, "mode": "loop", "period": FP[k]["tail"],
                  "phase": int(FP[k]["tph"] * 24), "host": k})

tiers += [
    {"id": "legB", "name": "Левая нога", "image": "L_legB.png", "pivot": [402, 765], "depth": 0.05, "keys": keys(leg_lift("legB")), "curve": "linear"},
    {"id": "legF", "name": "Правая нога", "image": "L_legF.png", "pivot": [440, 778], "depth": 0.06, "keys": keys(leg_lift("legF")), "curve": "linear"},
    {"id": "body", "name": "Девочка", "image": "L_body.png", "pivot": list(BODY_PIV), "depth": 0.1, "keys": keys(body_pose), "curve": "linear"},
    {"id": "hand", "name": "Рука с кистью", "image": "L_hand.png", "pivot": list(ELBOW), "depth": 0.12, "parent": "body",
     "keys": keys(lambda t: (0, 0, arm_angle(t), 1.0)), "curve": "linear"},
    {"id": "shell", "name": "Раковина", "pivot": list(SHELL_C), "depth": 0.11, "keys": keys(shell_pose), "curve": "linear",
     "phases": [{"t": 0, "image": "L_shell_v8.png", "id": "shell-0"}, {"t": round(TL, 4), "image": "empty.png", "id": "empty"}]},
]
RIP = json.load(open("ripples.json")) if os.path.exists("ripples.json") else []
tiers.append({"id": "ripples", "name": "Круги на воде", "pivot": list(LAND), "depth": 0.01,
              "phases": [{"t": 0, "image": "empty.png", "id": "empty"}] + [{"t": r["t"], "image": r["image"], "id": r["id"]} for r in RIP]})

# юбка догоняет таз: два изгиба в разные стороны с запаздыванием, каждый только в свой перенос
skirt = [[300, 470], [520, 470], [558, 580], [566, 602], [532, 620], [465, 625], [390, 624], [315, 616], [258, 600], [258, 560], [280, 500]]
evR = [int(round((a + d * 0.55) * FPS)) for n, a, d, s in SHIFTS if n == "legF" and s > 0.4]   # таз влево → подол вправо
evL = [int(round((a + d * 0.55) * FPS)) for n, a, d, s in SHIFTS if n == "legB" and s > 0.4]
parts += [
    {"id": "bend-legB", "name": "Левая голень сгибается", "effect": "flex", "host": "legB",
     "shape": {"poly": [[370, 662], [424, 662], [448, 700], [452, 806], [346, 806], [346, 700]]},
     "pivot": [400, 664], "target": [356, 600], "amp": 60, "feather": 6, "mode": "accents", "frames": lift_frames("legB"),
     "attack": 5, "hold": 2, "release": 7},
    {"id": "bend-legF", "name": "Правая голень сгибается", "effect": "flex", "host": "legF",
     "shape": {"poly": [[404, 662], [458, 662], [496, 700], [500, 808], [386, 808], [384, 700]]},
     "pivot": [434, 664], "target": [418, 584], "amp": 52, "feather": 6, "mode": "accents", "frames": lift_frames("legF"),
     "attack": 5, "hold": 2, "release": 7},
    {"id": "skirt-r", "name": "Подол вправо", "effect": "flex", "host": "body", "shape": {"poly": skirt}, "pivot": [440, 400],
     "target": [640, 450], "amp": 4, "feather": 10, "mode": "accents", "frames": evR, "attack": 6, "release": 10},
    {"id": "skirt-l", "name": "Подол влево", "effect": "flex", "host": "body", "shape": {"poly": skirt}, "pivot": [440, 400],
     "target": [240, 450], "amp": 4, "feather": 10, "mode": "accents", "frames": evL, "attack": 6, "release": 10},
    {"id": "hair", "name": "Волосы на ветру", "effect": "flex", "host": "body",
     "shape": {"poly": [[228, 92], [300, 78], [380, 74], [425, 82], [418, 120], [412, 160], [404, 206], [370, 214], [320, 198], [272, 168], [236, 138]]},
     "pivot": [415, 140], "target": [235, 118], "amp": 9, "feather": 8, "mode": "loop", "period": 1.9},
    {"id": "sea-far", "name": "Море даль", "effect": "wave", "shape": {"rect": [0, 459, 1456, 545]}, "amp": 50, "feather": 10, "mode": "loop", "period": 2.5},
    {"id": "sea-mid", "name": "Море", "effect": "wave", "shape": {"poly": [[0, 540], [1456, 540], [1456, 668], [760, 668], [520, 650], [300, 642], [0, 618]]},
     "amp": 85, "feather": 12, "mode": "loop", "period": 1.9},
    {"id": "sea-near", "name": "Прибой", "effect": "wave", "shape": {"poly": [[640, 664], [1456, 660], [1456, 816], [1010, 816], [860, 766], [720, 712]]},
     "amp": 85, "feather": 14, "mode": "loop", "period": 1.6},
    {"id": "foam-left", "name": "Пена у берега", "effect": "wave", "shape": {"poly": [[0, 588], [330, 610], [330, 650], [0, 640]]},
     "amp": 75, "feather": 8, "mode": "loop", "period": 1.8},
    {"id": "sun", "name": "Солнце", "effect": "light", "shape": {"ellipse": [1121, 250, 184, 184]}, "amp": 16, "feather": 24, "mode": "loop", "period": 4.0},
]
for i, (x, y, per) in enumerate([(297, 318, 2.3), (568, 312, 3.1), (1238, 58, 2.7), (1353, 58, 3.6), (1383, 342, 2.9)]):
    parts.append({"id": f"star{i + 1}", "name": f"Звезда {i + 1}", "effect": "light", "shape": {"ellipse": [x, y, 12, 12]},
                  "amp": 85, "feather": 8, "mode": "loop", "period": per, "phase": int(i * 11)})
for i, (x, y) in enumerate([(124, 150), (858, 337)]):
    parts.append({"id": f"moon{i + 1}", "name": f"Месяц {i + 1}", "effect": "light", "shape": {"ellipse": [x, y, 20, 20]},
                  "amp": 40, "feather": 10, "mode": "loop", "period": 4.4 + i})

spec = {"name": "Девочка с раковиной · мастер 30 с · v3", "id": "girlshell30c", "painting": "painting.png", "plate": "plate.png", "ratio": "auto",
        "seconds": DUR, "exposure": 2,
        "notes": (f"Мастер-план 30 с, камера стоит, планы режутся в монтаже. Переступает 8,3-12,4 с, замах {WU} с, "
                  f"раковина отпущена на {TR:.2f} с, всплеск {TL:.2f} с, круги до конца, рыбы плывут к кругам, думают, что это еда, клюют в долю (трек Tales for Alexandra с 0:37,8)."),
        "parts": parts, "tiers": tiers}
json.dump(spec, open("spec.json", "w"), ensure_ascii=False, indent=0)
json.dump({"TR": TR, "TL": TL, "LAND": LAND, "DUR": DUR}, open("timing.json", "w"))
print(f"release {TR:.3f} s, landing {TL:.3f} s, tiers {len(tiers)}, parts {len(parts)}")
