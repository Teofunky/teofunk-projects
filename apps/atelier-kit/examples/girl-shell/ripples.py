"""Круги на воде и маленький всплеск: фазы яруса (прозрачные PNG на холсте картины), на двойках."""
import json, math, cv2, numpy as np
W, H, FPS = 1456, 816, 24
tm = json.load(open('timing.json')); TL = tm['TL']; LX, LY = tm['LAND']
DUR = 12.0
im = cv2.imread('painting.png').astype(np.float32)
rng = np.random.default_rng(5)
SS = 3  # суперсэмплинг для гладких линий
noise = [rng.uniform(0, 6.28, 5) for _ in range(6)]
foam = np.array([228, 238, 232], np.float32)   # BGR, как белые гребни волн на картине
def ring_layer(tau):
    acc = np.zeros((H * SS, W * SS), np.float32)    # альфа светлого штриха
    dark = np.zeros_like(acc)
    for k in range(4):
        tk = tau - 0.34 * k
        if tk <= 0: continue
        rx = 6 + 58 * tk ** 0.75; ry = rx * 0.24
        fade = 1.25 * max(0.0, 1 - tk / 5.0) ** 1.2 * (1 - 0.15 * k)
        if fade <= 0.01: continue
        n = 220
        th = np.linspace(0, 2 * np.pi, n, endpoint=False)
        ph = noise[k]
        broken = 0.55 + 0.45 * np.clip(np.sin(3 * th + ph[0]) + 0.6 * np.sin(7 * th + ph[1]) + 0.4 * np.sin(13 * th + ph[2]), -1, 1)
        near = 0.55 + 0.45 * np.sin(th)        # ближняя (нижняя) сторона ярче, дальняя тоньше
        xs = LX + rx * np.cos(th); ys = LY + ry * np.sin(th)
        for i in range(n):
            j = (i + 1) % n
            a = float(fade * broken[i] * near[i])
            if a < 0.04: continue
            p0 = (int(xs[i] * SS), int(ys[i] * SS)); p1 = (int(xs[j] * SS), int(ys[j] * SS))
            th_px = max(1, int(round(SS * (1.2 + 1.3 * near[i]))))
            cv2.line(acc, p0, p1, a, th_px, cv2.LINE_AA)
            q0 = (p0[0], p0[1] - SS * 2); q1 = (p1[0], p1[1] - SS * 2)   # тень над гребнем
            cv2.line(dark, q0, q1, a * 0.5, th_px, cv2.LINE_AA)
    # всплеск: капли вверх и вниз первые 0,5 с
    if tau < 0.5:
        for d in range(7):
            vx = (d - 3) * 14 + rng.normal(0, 1.5); vy = -110 - 30 * (3 - abs(3 - d))
            x = LX + vx * tau; y = LY + vy * tau + 420 * tau * tau
            if y > LY + 1: continue
            r = SS * (2.2 - 0.2 * abs(3 - d))
            cv2.circle(acc, (int(x * SS), int(y * SS)), int(r), float(0.9 * (1 - tau / 0.5)), -1, cv2.LINE_AA)
        cv2.ellipse(acc, (int(LX * SS), int(LY * SS)), (int(SS * (4 + 18 * tau)), int(SS * (1.5 + 3 * tau))), 0, 180, 360,
                    float(0.8 * (1 - tau / 0.5)), max(1, SS), cv2.LINE_AA)
    a = cv2.resize(acc, (W, H), interpolation=cv2.INTER_AREA)
    dk = cv2.resize(dark, (W, H), interpolation=cv2.INTER_AREA)
    a = np.clip(a, 0, 0.92); dk = np.clip(dk, 0, 0.6) * (1 - a)
    # цвет: светлый штрих пеной, тень темнее воды под ним
    water = cv2.GaussianBlur(im, (0, 0), 3)
    col = foam * a[..., None] + water * 0.45 * dk[..., None]
    alpha = a + dk
    rgb = np.where(alpha[..., None] > 1e-4, col / np.maximum(alpha[..., None], 1e-4), 0)
    grain = rng.normal(0, 6, (H, W, 1)).astype(np.float32)
    rgba = np.dstack([np.clip(rgb + grain, 0, 255), np.clip(alpha * 255, 0, 255)]).astype(np.uint8)
    rgba[alpha < 1e-3] = 0
    return rgba
out = []
f0 = int(math.ceil(TL * FPS / 2) * 2)
for f in range(f0, int(DUR * FPS), 2):
    tau = f / FPS - TL
    rg = ring_layer(tau)
    if rg[..., 3].max() == 0: continue
    name = f'rip/r{f:03d}.png'
    cv2.imwrite(name, rg, [cv2.IMWRITE_PNG_COMPRESSION, 9])
    out.append({"t": f / FPS, "image": name, "id": f"rip{f:03d}"})
json.dump(out, open('ripples.json', 'w'))
cv2.imwrite('empty.png', np.zeros((H, W, 4), np.uint8))
print(len(out), 'phases from frame', f0)
