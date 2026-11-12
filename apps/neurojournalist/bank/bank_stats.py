#!/usr/bin/env python3
"""Проверка и статистика банка приёмов «Нейрожурналиста».

Запуск: python3 bank_stats.py            (из папки bank)
        python3 bank_stats.py --check    (только проверка, код выхода 1 при ошибках)

Проверяет: уникальность id, существование семей и тем, ссылки evidence
на разобранные источники, номера реплик в пределах turns.
Считает: частоты приёмов, покрытие реплик разметкой, долю новых тем,
число реплик в интервью.
"""
import statistics
import sys
from collections import Counter
from pathlib import Path

import yaml

HERE = Path(__file__).resolve().parent
PLACEMENTS = {"start", "early", "middle", "late", "end", "any"}


def load():
    tech = yaml.safe_load((HERE / "techniques.yaml").read_text(encoding="utf-8"))
    src = yaml.safe_load((HERE / "sources.yaml").read_text(encoding="utf-8"))
    return tech, src


def check(tech, src):
    errors = []
    coded = {s["id"]: s for s in src["coded"]}
    for s in src["coded"]:
        bad = [n for n in s["new_topics"] if not 1 <= n <= s["turns"]]
        if bad:
            errors.append(f"{s['id']}: new_topics вне 1..{s['turns']}: {bad}")
    ids = Counter(t["id"] for t in tech["techniques"])
    errors += [f"повтор id {i}" for i, n in ids.items() if n > 1]
    for t in tech["techniques"]:
        tid = t["id"]
        for key in ("name", "family", "goal", "template", "placement", "evidence"):
            if key not in t:
                errors.append(f"{tid}: нет поля {key}")
        if t.get("family") not in tech["families"]:
            errors.append(f"{tid}: неизвестная семья {t.get('family')}")
        if not str(tid).startswith(str(t.get("family"))):
            errors.append(f"{tid}: код не совпадает с семьёй {t.get('family')}")
        if t.get("placement") not in PLACEMENTS:
            errors.append(f"{tid}: неизвестное место {t.get('placement')}")
        for th in t.get("themes") or []:
            if th not in tech["themes"]:
                errors.append(f"{tid}: неизвестная тема {th}")
        for sid, turns in (t.get("evidence") or {}).items():
            if sid not in coded:
                errors.append(f"{tid}: источник {sid} не разобран или не существует")
                continue
            bad = [n for n in turns if not 1 <= n <= coded[sid]["turns"]]
            if bad:
                errors.append(f"{tid}: {sid} реплики вне 1..{coded[sid]['turns']}: {bad}")
            if len(set(turns)) != len(turns):
                errors.append(f"{tid}: {sid} повтор номеров реплик")
    return errors


def report(tech, src):
    coded = src["coded"]
    out = []
    out.append("## Реплики интервьюера\n")
    out.append("| Интервью | Реплик | Новых тем | Доля новых тем |")
    out.append("|---|---|---|---|")
    for s in coded:
        n, k = s["turns"], len(s["new_topics"])
        out.append(f"| {s['id']} | {n} | {k} | {k / n:.0%} |")
    all_turns = [s["turns"] for s in coded] + [s["turns"] for s in src["counted_only"]]
    total_new = sum(len(s["new_topics"]) for s in coded)
    total_turns = sum(s["turns"] for s in coded)
    out.append("")
    out.append(f"Разобрано интервью: {len(coded)}, реплик: {total_turns}, "
               f"доля новых тем в среднем по репликам: {total_new}/{total_turns} = {total_new / total_turns:.0%}.")
    out.append(f"Вместе с замером из ТЗ интервью: {len(all_turns)}; реплик: "
               f"минимум {min(all_turns)}, медиана {statistics.median(all_turns):g}, "
               f"среднее {statistics.mean(all_turns):.1f}, максимум {max(all_turns)}.")

    out.append("\n## Частота приёмов\n")
    out.append("Реплик: сколько реплик интервьюера помечены приёмом. "
               "Интервью: в скольких из разобранных интервью он встретился.\n")
    out.append("| Код | Приём | Реплик | Интервью |")
    out.append("|---|---|---|---|")
    rows = []
    covered = {s["id"]: set() for s in coded}
    for t in tech["techniques"]:
        ev = t.get("evidence") or {}
        n_turns = sum(len(v) for v in ev.values())
        rows.append((n_turns, len(ev), t["id"], t["name"]))
        for sid, turns in ev.items():
            covered[sid].update(turns)
    for n_turns, n_src, tid, name in sorted(rows, key=lambda r: (-r[0], r[2])):
        out.append(f"| {tid} | {name} | {n_turns} | {n_src} |")

    out.append("\n## Покрытие разметкой\n")
    out.append("Сколько реплик каждого интервью отнесено хотя бы к одному приёму "
               "(остальное: короткие реакции и служебные реплики).\n")
    out.append("| Интервью | Помечено | Из |")
    out.append("|---|---|---|")
    for s in coded:
        out.append(f"| {s['id']} | {len(covered[s['id']])} | {s['turns']} |")

    fam = Counter()
    for t in tech["techniques"]:
        fam[t["family"]] += 1
    out.append("\n## Семьи\n")
    out.append(", ".join(f"{k} {tech['families'][k]}: {fam[k]}" for k in tech["families"]))
    out.append(f"\nВсего приёмов: {len(tech['techniques'])}.")
    return "\n".join(out)


def main():
    tech, src = load()
    errors = check(tech, src)
    if errors:
        print("ОШИБКИ:")
        print("\n".join(errors))
        sys.exit(1)
    if "--check" in sys.argv:
        print("Проверка пройдена.")
        return
    print(report(tech, src))


if __name__ == "__main__":
    main()
