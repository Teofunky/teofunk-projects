"""Круги на воде по физике глубокой воды (задача Коши-Пуассона): расширяющаяся группа волн,
гребни бегут вдвое быстрее группы (рождаются у внутреннего края, гаснут у внешнего), в центре
спокойная вода. Плюс всплеск: корона капель и тонкая струя. Фазы яруса на двойках."""
import json, math, cv2, numpy as np
W, H, FPS = 1456, 816, 24
tm = json.load(open('timing.json')); TL, DUR = tm['TL'], tm['DUR']; LX, LY = tm['LAND']
HOR = 461                         # горизонт: выше кругов нет
VG = 27.0                         # скорость группы, px/с
LAM = 18.0                        # длина волны в середине группы, px
G = 8 * math.pi * VG ** 2 / LAM   # «g» в пикселях: фаза = G t² / 4r
SS = 2
x0, x1, y0, y1 = 0, W, HOR - 2, min(H, int(LY + 150))
xs = (np.arange((x1 - x0) * SS) + 0.5) / SS + x0
ys = (np.arange((y1 - y0) * SS) + 0.5) / SS + y0
X, Y = np.meshgrid(xs, ys)
dy = Y - LY
q = np.where(dy < 0, 0.17, 0.23)                 # перспектива: дальняя сторона сжата сильнее
R = np.sqrt((X - LX) ** 2 + (dy / q) ** 2)
near = np.where(dy < 0, 0.55, 1.0)               # дальняя сторона тусклее и тоньше
sea = np.clip((Y - HOR) / 8, 0, 1); sea = sea * sea * (3 - 2 * sea)
foam = np.array([226, 236, 230], np.float32)     # BGR, как белые гребни на картине
im = cv2.imread('painting.png').astype(np.float32)
water = cv2.GaussianBlur(im, (0, 0), 3)
rng = np.random.default_rng(5)
drops = [((d - 3) * 14 + rng.normal(0, 2), -95 - 25 * (3 - abs(3 - d))) for d in range(7)]

def field(t):
    rg = VG * t
    sig = 9 + 0.27 * rg
    env = np.exp(-((R - rg) / sig) ** 2) * math.exp(-t / 15) / np.sqrt(1 + R / 45)
    env *= np.clip(R / 4, 0, 1)                   # в самой точке без гребня
    ph = G * t * t / (4 * (R + 3))
    c = np.cos(ph)
    light = np.clip(c, 0, 1) ** 6 * env * near
    dark = np.clip(-c, 0, 1) ** 3 * env * near
    return light, dark

def splash(acc, t):
    if t > 0.7: return
    k = SS
    for vx, vy in drops:                         # корона капель
        x = LX + vx * t; y = LY + vy * t + 430 * t * t
        if y > LY + 0.5: continue
        cv2.circle(acc, (int((x - x0) * k), int((y - y0) * k)), max(1, int(k * 1.6)), float(1 - t / 0.7), -1, cv2.LINE_AA)
    if 0.12 < t < 0.62:                          # струя Уортингтона
        u = (t - 0.12) / 0.5; h = 24 * math.sin(math.pi * u)
        cv2.line(acc, (int((LX - x0) * k), int((LY - y0) * k)), (int((LX - x0) * k), int((LY - h - y0) * k)), 0.9, max(1, int(k * 1.6)), cv2.LINE_AA)
        if u > 0.55:
            cv2.circle(acc, (int((LX - x0) * k), int((LY - h - 6 * (u - 0.55) - y0) * k)), max(1, int(k * 1.5)), 0.9, -1, cv2.LINE_AA)
    if t < 0.35:
        cv2.ellipse(acc, (int((LX - x0) * k), int((LY - y0) * k)), (int(k * (4 + 30 * t)), int(k * (1.5 + 6 * t))), 0, 180, 360,
                    float(0.9 * (1 - t / 0.35)), max(1, k), cv2.LINE_AA)

out = []
f0 = int(math.ceil(TL * FPS / 2) * 2)
for f in range(f0, int(DUR * FPS), 2):
    t = f / FPS - TL
    light, dark = field(t)
    acc = np.zeros_like(light, np.float32); splash(acc, t)
    a = np.clip(1.35 * light + acc, 0, 0.92) * sea
    dk = np.clip(0.55 * dark, 0, 0.5) * sea * (1 - a)
    a = cv2.resize(a, (x1 - x0, y1 - y0), interpolation=cv2.INTER_AREA)
    dk = cv2.resize(dk, (x1 - x0, y1 - y0), interpolation=cv2.INTER_AREA)
    alpha = a + dk
    if alpha.max() < 0.02: continue
    wat = water[y0:y1, x0:x1]
    col = foam * a[..., None] + wat * 0.45 * dk[..., None]
    rgb = np.where(alpha[..., None] > 1e-4, col / np.maximum(alpha[..., None], 1e-4), 0)
    rgb += rng.normal(0, 5, rgb.shape[:2] + (1,)).astype(np.float32)
    rgba = np.zeros((H, W, 4), np.uint8)
    rgba[y0:y1, x0:x1, :3] = np.clip(rgb, 0, 255)
    rgba[y0:y1, x0:x1, 3] = np.clip(alpha * 255, 0, 255)
    rgba[rgba[..., 3] < 2] = 0
    name = f'rip/r{f:03d}.png'
    cv2.imwrite(name, rgba, [cv2.IMWRITE_PNG_COMPRESSION, 9])
    out.append({"t": f / FPS, "image": name, "id": f"rip{f:03d}"})
json.dump(out, open('ripples.json', 'w'))
print(len(out), 'фаз с кадра', f0, 'G', round(G))
