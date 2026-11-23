"""Рендер проекта .atelier2 движком ATELIER.

python3 render.py проект.atelier2 --frames 0,48,176 --out кадры/ [--height 1080]
python3 render.py проект.atelier2 --movie фильм.mp4 [--height 1080] [--first 0 --count 240]
"""
import argparse
import asyncio
import sys
import time

from studio import Studio


async def main(a):
    async with Studio(a.html) if a.html else Studio() as s:
        info = await s.load(a.project)
        print(f"{info['name']}: {info['frames']} кадров, {info['ratio']}, планов {len(info['scenes'])}")
        if a.frames:
            fr = [int(v) for v in a.frames.split(",")]
            for p in await s.frames(fr, a.out or "frames", a.height):
                print(p)
        if a.movie:
            t = time.time()
            await s.movie(a.movie, a.height, a.first, a.count)
            print(f"{a.movie} за {time.time() - t:.0f} с")
        if s.errors:
            print("Ошибки страницы:", *s.errors[:10], sep="\n  ", file=sys.stderr)
            return 1


if __name__ == "__main__":
    p = argparse.ArgumentParser()
    p.add_argument("project")
    p.add_argument("--frames")
    p.add_argument("--out")
    p.add_argument("--movie")
    p.add_argument("--height", type=int, default=1080)
    p.add_argument("--first", type=int, default=0)
    p.add_argument("--count", type=int)
    p.add_argument("--html")
    sys.exit(asyncio.run(main(p.parse_args())) or 0)
