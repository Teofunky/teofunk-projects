"""Монтаж превью 720p из мастер-плана 1080p: планы кропом (лист планов ниже), склейки на акцентах,
одна панорама и один наплыв. Звук: синтез моря и ветра, всплеск на падении, -23 LUFS."""
import json, math, subprocess, sys
import cv2, numpy as np

FPS, OUT_W, OUT_H = 24, 1280, 720
tm = json.load(open('timing.json')); TR, TL = tm['TR'], tm['TL']
K = 1920 / 1456; OY = (1080 - 816 * K) / 2           # пиксели картины → пиксели мастера

def sm(x):
    x = min(1.0, max(0.0, x)); return x * x * (3 - 2 * x)

# лист планов: (начало, конец, крупность, описание, кадр в начале (cx, cy, w), кадр в конце или None)
SHOTS = [
    (0.0, 5.0, "общий", "ночь, море, рыбки, ветер", (728, 408, 1456), None),
    (5.0, 8.4, "средний", "девочка с раковиной, волосы на ветру", (430, 300, 760), None),
    (8.4, 12.6, "крупный", "ноги: переступает с ноги на ногу", (430, 664, 540), None),
    (12.6, TR, "средний", "замолкла, замах, бросок", (600, 290, 900), None),
    (TR, 17.2, "общий", "полёт к солнцу, всплеск, рыбы шарахаются", (728, 408, 1456), None),
    (17.2, 23.0, "средний → крупный", "круги: наплыв с 18,5 с", (992, 500, 700), (992, 506, 520)),
    (23.0, 27.0, "средний", "панорама по морю к девочке: она смотрит на круги", (992, 500, 700), (470, 330, 760)),
    (27.0, 30.0, "общий", "круги расходятся, девочка смотрит", (728, 408, 1456), None),
]
HOLD = {5: 1.3}   # в плане 6 первые 1,3 с камера стоит, потом наплыв

def view(t):
    for i, (a, b, size, note, s0, s1) in enumerate(SHOTS):
        if a <= t < b or i == len(SHOTS) - 1:
            if s1 is None: return s0
            u = sm((t - a - HOLD.get(i, 0)) / (b - a - HOLD.get(i, 0)))
            return tuple(s0[j] + (s1[j] - s0[j]) * u for j in range(3))

def crop(frame, cx, cy, w):
    h = w * 9 / 16
    cx = min(max(cx, w / 2), 1456 - w / 2); cy = min(max(cy, h / 2), 816 - h / 2)
    s = OUT_W / (w * K)                                  # масштаб мастер → выход
    M = np.float32([[s, 0, OUT_W / 2 - s * (cx * K)], [0, s, OUT_H / 2 - s * (cy * K + OY)]])
    interp = cv2.INTER_AREA if s < 1 else cv2.INTER_CUBIC
    return cv2.warpAffine(frame, M, (OUT_W, OUT_H), flags=interp, borderMode=cv2.BORDER_REFLECT)

def audio(path, dur):
    sr = 48000; n = int(dur * sr); t = np.arange(n) / sr
    rng = np.random.default_rng(3)
    def brown(seed):
        w = np.random.default_rng(seed).normal(0, 1, n); b = np.cumsum(w); b -= np.convolve(b, np.ones(4801) / 4801, 'same'); return b / np.abs(b).max()
    surf = np.stack([brown(1), brown(2)])
    env = 0.55 + 0.25 * np.sin(2 * np.pi * t / 1.9) + 0.2 * np.sin(2 * np.pi * t / 2.5 + 1) + 0.15 * np.sin(2 * np.pi * t / 7.3)
    sig = surf * np.clip(env, 0.1, 1)
    hiss = rng.normal(0, 1, (2, n)); hiss = hiss - np.roll(hiss, 1, axis=1)
    wind = hiss * (0.05 + 0.03 * np.sin(2 * np.pi * t / 5.1))
    x = sig * 0.8 + wind
    # всплеск: шлепок, низкий удар, капли
    i0 = int(TL * sr); L = int(0.6 * sr); tt = np.arange(L) / sr
    burst = rng.normal(0, 1, L) * np.exp(-tt / 0.07) * (1 - np.exp(-tt / 0.004))
    burst = np.convolve(burst, np.hanning(9) / 4, 'same')
    thump = np.sin(2 * np.pi * 140 * tt * (1 - 0.4 * tt)) * np.exp(-tt / 0.06)
    drops = np.zeros(L)
    for d in (0.18, 0.27, 0.33, 0.41, 0.52):
        j = int(d * sr); m = int(0.03 * sr); dt = np.arange(m) / sr
        drops[j:j + m] += 0.5 * np.sin(2 * np.pi * (1400 + 900 * rng.random()) * dt) * np.exp(-dt / 0.008)
    sp = 1.8 * burst + 1.2 * thump + drops
    e = min(n, i0 + L) - i0
    x[:, i0:i0 + e] += sp[:e] * np.array([[0.8], [1.0]])
    fade = np.clip(t / 1.5, 0, 1) * np.clip((dur - t) / 2.5, 0, 1)
    x = x * fade / np.abs(x).max() * 0.7
    import wave
    with wave.open(path, 'wb') as wv:
        wv.setnchannels(2); wv.setsampwidth(2); wv.setframerate(sr)
        wv.writeframes((x.T * 32767).astype(np.int16).tobytes())

if __name__ == "__main__":
    master, out = sys.argv[1], sys.argv[2]
    cap = cv2.VideoCapture(master)
    n = int(cap.get(cv2.CAP_PROP_FRAME_COUNT)); dur = n / FPS
    audio('amb_raw.wav', dur)
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', 'amb_raw.wav', '-af', 'loudnorm=I=-23:TP=-2:LRA=11,volume=2.3dB,alimiter=limit=0.89', '-ar', '48000', 'amb.wav'], check=True)
    ff = subprocess.Popen(['ffmpeg', '-v', 'error', '-y', '-f', 'rawvideo', '-pix_fmt', 'bgr24', '-s', f'{OUT_W}x{OUT_H}', '-r', str(FPS), '-i', '-',
                           '-i', 'amb.wav', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p',
                           '-c:a', 'aac', '-b:a', '160k', '-shortest', '-movflags', '+faststart', out], stdin=subprocess.PIPE)
    i = 0
    while True:
        ok, fr = cap.read()
        if not ok: break
        cx, cy, w = view(i / FPS)
        ff.stdin.write(crop(fr, cx, cy, w).tobytes()); i += 1
    ff.stdin.close(); ff.wait()
    json.dump([dict(start=round(a, 2), end=round(b, 2), size=s, note=nt, frame0=f0, frame1=f1) for a, b, s, nt, f0, f1 in SHOTS],
              open('shots.json', 'w'), ensure_ascii=False, indent=1)
    print(out, i, 'кадров')
