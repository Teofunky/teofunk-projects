"""Обратный ход: .atelier2 → папка с spec.json и материалами.

Так проект, поправленный руками в студии, можно дальше править словами: распаковать,
изменить spec.json, собрать заново через project.py. Поддерживается один план на проект.

python3 unpack.py "Проект.atelier2" папка [--painting исходная_картина.png]
"""
import argparse
import json
import re
from pathlib import Path

from project import FPS, MIME, Frame, read

EXT = {v: k for k, v in MIME.items()}
SKIP = {"id", "name", "effect", "points", "pivot", "events", "hidden", "target", "tip", "rigid", "seam",
        "bend", "donor", "plate", "host", "follow", "link"}


def r1(v):
    return round(v, 2)


def unpack(project, out, painting=None):
    film, assets = read(project)
    if len(film["scenes"]) != 1:
        raise SystemExit("пока поддерживается проект из одного плана")
    sc = film["scenes"][0]
    out = Path(out)
    out.mkdir(parents=True, exist_ok=True)
    files = {}
    for aid, (data, name, mime, w, h) in assets.items():
        fn = re.sub(r"[^\w.-]+", "_", Path(name).stem)[:60] or aid
        f = f"{aid}__{fn}{EXT.get(mime, '.bin')}"
        (out / "media").mkdir(exist_ok=True)
        (out / "media" / f).write_bytes(data)
        files[aid] = "media/" + f
    base = assets[sc["asset"]]
    fr = Frame(base[3], base[4], film["ratio"])
    P = lambda q: [r1(v) for v in fr.back(q)]
    spec = {"name": film["name"], "id": film["id"], "painting": str(Path(painting).resolve()) if painting else files[sc["asset"]], "ratio": film["ratio"],
            "frames": sc["frames"], "exposure": sc.get("exposure", 1), "notes": sc.get("notes", ""),
            "scene_id": sc["id"], "shot_name": sc["name"], "base_id": sc["asset"], "base_name": base[1]}
    if painting:
        spec["plate"] = files[sc["asset"]]
    if film.get("audio"):
        spec["audio"] = {"file": files[film["audio"]], "bpm": film["bpm"], "zero": film["zero"], "trim": film["audioTrim"],
                         "volume": film["volume"], "snap": film["snap"]}
    take = sc["takes"][sc["active"]]
    if take.get("guards"):
        spec["guards"] = [{"poly": [P(q) for q in g]} for g in take["guards"]]
    parts = []
    for L in take["layers"]:
        p = {"id": L["id"], "name": L["name"], "effect": L["effect"], "shape": {"poly": [P(q) for q in L["points"]]},
             "pivot": P(L["pivot"])}
        if L.get("events"):
            p["frames"] = L["events"]
        if "target" in L:
            p["target"] = P(L["target"])
        for key in ("tip", "rigid"):
            if key in L:
                p[key] = P(L[key])
        if "seam" in L:
            p["seam"] = [P(q) for q in L["seam"]]
        if "bend" in L:
            p["bend"] = [r1(fr.back([0, L["bend"][0]])[1]), r1(fr.back([0, L["bend"][1]])[1])]
        if L.get("donor"):
            p["video"], p["video_id"] = files[L["donor"]], L["donor"]
        if L.get("plate"):
            p["plate"] = files[L["plate"]]
        for key in ("host", "follow", "link"):
            if key in L:
                p[key] = L[key]
        if L.get("hidden"):
            p["hidden"] = True
        p.update({k: v for k, v in L.items() if k not in SKIP})
        parts.append(p)
    spec["parts"] = parts

    def keys(track):
        def k(t, pose):
            return {"t": t, "dx": r1(pose["x"] * fr.W / fr.k), "dy": r1(pose["y"] * fr.H / fr.k), "angle": pose["angle"], "scale": pose["scale"]}
        ks = [k(0, track["start"])] + [k(q["frame"] / FPS, q["pose"]) for q in track.get("keys", [])]
        return ks + [k((sc["frames"] - 1) / FPS, track["end"])]

    st = sc.get("stand")
    if st:
        spec["tiers"] = []
        for t in st["layers"]:
            d = {"id": t["id"], "name": t["name"], "pivot": P(t["pivot"]), "depth": t["depth"], "keys": keys(t)}
            if t.get("parent"):
                d["parent"] = t["parent"]
            if t.get("curve"):
                d["curve"] = t["curve"]
            if t.get("opacity", 1) != 1:
                d["opacity"] = t["opacity"]
            if len(t["phases"]) == 1:
                d["image"] = files[t["phases"][0]["asset"]]
                d["phases"] = [{"t": 0, "image": d["image"], "id": t["phases"][0]["asset"], "name": assets[t["phases"][0]["asset"]][1]}]
                del d["image"]
            else:
                d["phases"] = [{"t": q["frame"] / FPS, "image": files[q["asset"]], "id": q["asset"], "name": assets[q["asset"]][1]} for q in t["phases"]]
            spec["tiers"].append(d)
        spec["camera"] = {"keys": keys(st["camera"]), "curve": st["camera"].get("curve", "smooth")}
    (out / "spec.json").write_text(json.dumps(spec, ensure_ascii=False, indent=1), encoding="utf-8")
    return out / "spec.json"


if __name__ == "__main__":
    a = argparse.ArgumentParser()
    a.add_argument("project")
    a.add_argument("out")
    a.add_argument("--painting", help="исходная картина, если основа проекта подложка")
    x = a.parse_args()
    print(unpack(x.project, x.out, x.painting))
