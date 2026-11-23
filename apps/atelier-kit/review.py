"""Просмотр готового ролика так, как его увидит зритель: кадры в полном размере выхода,
по два на картинку, с таймкодом. Крупные планы смотреть особенно: кроп увеличивает любой брак.

python3 review.py ролик.mp4 --out review/ [--every 1.0] [--from 8.4 --to 12.6]
"""
import argparse
from pathlib import Path
import cv2, numpy as np

p = argparse.ArgumentParser()
p.add_argument("movie"); p.add_argument("--out", default="review")
p.add_argument("--every", type=float, default=1.0); p.add_argument("--from", dest="t0", type=float, default=0)
p.add_argument("--to", dest="t1", type=float, default=1e9)
a = p.parse_args()
out = Path(a.out); out.mkdir(parents=True, exist_ok=True)
cap = cv2.VideoCapture(a.movie); fps = cap.get(cv2.CAP_PROP_FPS) or 24
want, i, picked = set(), 0, []
n = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
t = a.t0
while t < min(a.t1, n / fps):
    want.add(int(round(t * fps))); t += a.every
while True:
    ok, f = cap.read()
    if not ok: break
    if i in want:
        cv2.putText(f, f"{i / fps:.2f}s", (10, 32), cv2.FONT_HERSHEY_SIMPLEX, 0.9, (0, 255, 255), 2, cv2.LINE_AA); picked.append(f)
    i += 1
for k in range(0, len(picked), 2):
    row = picked[k:k + 2]
    if len(row) == 1: row.append(np.zeros_like(row[0]))
    cv2.imwrite(str(out / f"review_{k // 2:02d}.jpg"), np.hstack(row), [cv2.IMWRITE_JPEG_QUALITY, 88])
print(len(picked), "кадров →", out)
