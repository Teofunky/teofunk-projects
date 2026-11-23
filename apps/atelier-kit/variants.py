"""Быстрый подбор параметров одной партии: несколько вариантов рядом, крупно, на нужных кадрах.

python3 variants.py spec.json blink '{"A":{"feather":1.2},"B":{"shape":{"ellipse":[775,214.5,15,10.5]},"feather":2}}' \
    --frames 20,34 --box 725 175 825 255 --out варианты.jpg [--solo]

box в пикселях картины. --solo оставляет в проекте только эту партию (быстрее и чище видно).
"""
import argparse
import asyncio
import copy
import json
import sys
import tempfile
from pathlib import Path

import cv2
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))
from project import Builder, Frame, write  # noqa: E402
from studio import Studio  # noqa: E402


async def main(a):
    spec_path = Path(a.spec)
    spec = json.loads(spec_path.read_text(encoding="utf-8"))
    variants = json.loads(a.variants)
    frames = [int(v) for v in a.frames.split(",")]
    rows = []
    async with Studio() as s:
        with tempfile.TemporaryDirectory() as td:
            for name, over in variants.items():
                sp = copy.deepcopy(spec)
                part = next(p for p in sp["parts"] if p.get("id") == a.part)
                part.update(over)
                if a.solo:
                    sp["parts"] = [part]
                    if not part.get("host"):
                        sp.pop("tiers", None)
                        sp.pop("plate", None)
                film, assets, fr = Builder(sp, spec_path.parent).build()
                await s.load(write(film, assets, Path(td) / f"{name}.atelier2"))
                W, H = await s.size(a.height)
                k = min(W / fr.iw, H / fr.ih)
                ox, oy = (W - fr.iw * k) / 2, (H - fr.ih * k) / 2
                x0, y0, x1, y1 = (int(ox + a.box[0] * k), int(oy + a.box[1] * k), int(ox + a.box[2] * k), int(oy + a.box[3] * k))
                cells = []
                for f in frames:
                    im = cv2.imdecode(np.frombuffer(await s.frame_png(f, a.height), np.uint8), cv2.IMREAD_COLOR)
                    c = cv2.resize(im[y0:y1, x0:x1], None, fx=a.zoom, fy=a.zoom, interpolation=cv2.INTER_CUBIC)
                    cv2.putText(c, f"{name} f{f}", (5, 22), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 255), 2, cv2.LINE_AA)
                    cells.append(c)
                rows.append(np.hstack(cells))
    cv2.imwrite(a.out, np.vstack(rows), [cv2.IMWRITE_JPEG_QUALITY, 90])
    print(a.out)


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("spec")
    p.add_argument("part")
    p.add_argument("variants", help='JSON {"имя": {поля партии}}')
    p.add_argument("--frames", required=True)
    p.add_argument("--box", type=float, nargs=4, required=True)
    p.add_argument("--out", default="variants.jpg")
    p.add_argument("--height", type=int, default=1080)
    p.add_argument("--zoom", type=float, default=3)
    p.add_argument("--solo", action="store_true")
    asyncio.run(main(p.parse_args()))
