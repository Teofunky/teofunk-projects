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
DB = [TL + 1.952 * i for i in range(8)]   # сильные доли трека после всплеска (0:37,8 → всплеск)
SHOTS = [
    (0.0, 5.0, "общий", "ночь, море, рыбки, ветер", (728, 408, 1456), None),
    (5.0, 8.4, "средний", "девочка с раковиной, волосы на ветру", (430, 300, 760), None),
    (8.4, 12.6, "крупный", "ноги: переступает с ноги на ногу", (430, 664, 540), None),
    (12.6, TR, "средний", "замолкла, замах, бросок", (600, 290, 900), None),
    (TR, DB[1], "общий", "полёт к солнцу, всплеск на сильную долю, рыбы бросаются к кругам", (728, 408, 1456), None),
    (DB[1], DB[4], "средний → крупный", "рыбы над кругами клюют в долю: наплыв", (992, 470, 760), (995, 482, 560)),
    (DB[4], DB[6], "средний", "панорама по морю к девочке", (995, 482, 700), (470, 330, 760)),
    (DB[6], 30.0, "общий", "рыбы кормятся над кругами, девочка смотрит", (728, 408, 1456), None),
]
HOLD = {5: 0.98}   # в плане 6 первые 1,3 с камера стоит, потом наплыв

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

def ambient(path, dur):
    sr = 48000; n = int(dur * sr); t = np.arange(n) / sr
    rng = np.random.default_rng(3)
    def brown(seed):
        w = np.random.default_rng(seed).normal(0, 1, n); b = np.cumsum(w); b -= np.convolve(b, np.ones(4801) / 4801, 'same'); return b / np.abs(b).max()
    env = 0.55 + 0.25 * np.sin(2 * np.pi * t / 1.9) + 0.2 * np.sin(2 * np.pi * t / 2.5 + 1) + 0.15 * np.sin(2 * np.pi * t / 7.3)
    x = np.stack([brown(1), brown(2)]) * np.clip(env, 0.1, 1) * 0.8
    hiss = rng.normal(0, 1, (2, n)); x += (hiss - np.roll(hiss, 1, axis=1)) * (0.05 + 0.03 * np.sin(2 * np.pi * t / 5.1))
    i0 = int(TL * sr); L = int(0.6 * sr); tt = np.arange(L) / sr
    burst = np.convolve(rng.normal(0, 1, L) * np.exp(-tt / 0.07) * (1 - np.exp(-tt / 0.004)), np.hanning(9) / 4, 'same')
    sp = 1.8 * burst + 1.2 * np.sin(2 * np.pi * 140 * tt * (1 - 0.4 * tt)) * np.exp(-tt / 0.06)
    e = min(n, i0 + L) - i0; x[:, i0:i0 + e] += sp[:e]
    duck = 1 - 0.55 * np.clip((t - TL) / 0.8, 0, 1)            # под музыкой море тише
    x = x * duck * np.clip(t / 1.5, 0, 1) * np.clip((dur - t) / 2.5, 0, 1)
    x = x / np.abs(x).max() * 0.7
    import wave
    with wave.open(path, 'wb') as wv:
        wv.setnchannels(2); wv.setsampwidth(2); wv.setframerate(sr); wv.writeframes((x.T * 32767).astype(np.int16).tobytes())

def audio(path, dur):
    """Море (-23 LUFS до музыки) + Tales for Alexandra: вступление 0:00 до всплеска, на всплеске
    сильная доля грува 0:37,8 (склейка в музыке, пока нет полного файла)."""
    ambient('amb_raw.wav', dur)
    A, B = 'audio_A_0-00_0-20_vstuplenie_plus15dB.mp3', 'audio_B_0-36_0-56_groove.mp3'
    def norm(src, dst, lufs, extra=''):
        af = (extra + ',' if extra else '') + f'loudnorm=I={lufs}:TP=-3,aresample=48000'
        subprocess.run(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-i', src, '-af', af, '-ac', '2', '-ar', '48000', dst], check=True)
    norm('amb_raw.wav', 'm_amb.wav', -23)
    norm(A, 'm_a.wav', -24, f'atrim=0:{TL:.3f}')
    norm(B, 'm_b.wav', -17, 'atrim=start=1.814,asetpts=N/SR/TB')
    import wave
    def rd(p):
        with wave.open(p) as w:
            return np.frombuffer(w.readframes(w.getnframes()), np.int16).reshape(-1, 2).astype(np.float32) / 32768
    sr = 48000; n = int(dur * sr); out = np.zeros((n, 2), np.float32)
    amb = rd('m_amb.wav')[:n]; out[:len(amb)] += amb
    a = rd('m_a.wav'); fa = np.ones(len(a)); k = int(2 * sr); fa[:k] = np.linspace(0, 1, k); k = int(0.35 * sr); fa[-k:] = np.linspace(1, 0, k)
    out[:min(n, len(a))] += (a * fa[:, None])[:n]
    b = rd('m_b.wav'); i0 = int(TL * sr); m = min(len(b), n - i0)
    fb = np.ones(m); k = int(0.04 * sr); fb[:k] = np.linspace(0, 1, k); k = int(2.5 * sr); fb[-k:] = np.minimum(fb[-k:], np.linspace(1, 0, k))
    out[i0:i0 + m] += b[:m] * fb[:, None]
    out = np.clip(out, -0.95, 0.95)
    with wave.open(path, 'wb') as w:
        w.setnchannels(2); w.setsampwidth(2); w.setframerate(sr); w.writeframes((out * 32767).astype(np.int16).tobytes())

if __name__ == "__main__":
    master, out = sys.argv[1], sys.argv[2]
    cap = cv2.VideoCapture(master)
    n = int(cap.get(cv2.CAP_PROP_FRAME_COUNT)); dur = n / FPS
    audio('amb.wav', dur)
    ff = subprocess.Popen(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'rawvideo', '-pix_fmt', 'bgr24', '-s', f'{OUT_W}x{OUT_H}', '-r', str(FPS), '-i', '-',
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
