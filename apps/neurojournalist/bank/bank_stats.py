#!/usr/bin/env python3
"""Проверка и статистика банка вопросов «Нейрожурналиста».

Запуск из папки bank:
    python3 bank_stats.py            статистика в Markdown
    python3 bank_stats.py --check    только проверка, код выхода 1 при ошибках

Проверяет: уникальность id, семьи, темы и места в интервью; ссылки evidence
и questions.yaml на существующие источники и приёмы; номера реплик в пределах
turns для полностью размеченных интервью (coded).
Считает: длину интервью, долю новых тем (coded), частоту приёмов,
покрытие разметкой, вопросы по темам и приёмам.
"""
import statistics
import sys
from collections import Counter
from pathlib import Path

import yaml

HERE = Path(__file__).resolve().parent
PLACEMENTS = {"start", "early", "middle", "late", "end", "any"}


def load(name):
    return yaml.safe_load((HERE / name).read_text(encoding="utf-8"))


def check(tech, src, qs):
    errors = []
    coded = {s["id"]: s for s in src["coded"]}
    selected = {s["id"]: s for s in src.get("selected", [])}
    formats = {s["id"]: s for s in src.get("formats", [])}
    all_ids = [*coded, *selected, *formats]
    errors += [f"повтор id источника {i}" for i, n in Counter(all_ids).items() if n > 1]
    known = set(all_ids)

    for s in src["coded"]:
        bad = [n for n in s["new_topics"] if not 1 <= n <= s["turns"]]
        if bad:
            errors.append(f"{s['id']}: new_topics вне 1..{s['turns']}: {bad}")

    tids = Counter(t["id"] for t in tech["techniques"])
    errors += [f"повтор id приёма {i}" for i, n in tids.items() if n > 1]
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
            if sid not in known:
                errors.append(f"{tid}: неизвестный источник {sid}")
                continue
            if len(set(turns)) != len(turns):
                errors.append(f"{tid}: {sid} повтор номеров")
            if sid in coded:
                bad = [n for n in turns if not 1 <= n <= coded[sid]["turns"]]
                if bad:
                    errors.append(f"{tid}: {sid} реплики вне 1..{coded[sid]['turns']}: {bad}")
            elif sid in formats and turns:
                errors.append(f"{tid}: у формата {sid} не бывает номеров реплик")

    qids = Counter(q["id"] for q in qs["questions"])
    errors += [f"повтор id вопроса {i}" for i, n in qids.items() if n > 1]
    for q in qs["questions"]:
        if q.get("technique") not in tids:
            errors.append(f"{q['id']}: неизвестный приём {q.get('technique')}")
        if q.get("theme") not in tech["themes"]:
            errors.append(f"{q['id']}: неизвестная тема {q.get('theme')}")
        if q.get("src") not in known:
            errors.append(f"{q['id']}: неизвестный источник {q.get('src')}")
        elif q["src"] in coded:
            bad = [n for n in q.get("ref", []) if not 1 <= n <= coded[q["src"]]["turns"]]
            if bad:
                errors.append(f"{q['id']}: {q['src']} реплики вне диапазона: {bad}")
        if "—" in q.get("q", ""):
            errors.append(f"{q['id']}: длинное тире в вопросе")
    return errors


def report(tech, src, qs):
    coded = src["coded"]
    selected = src.get("selected", [])
    formats = src.get("formats", [])
    out = []

    out.append("## Длина интервью\n")
    out.append("| Интервью | Вопросов интервьюера | Разметка |")
    out.append("|---|---|---|")
    lengths = []
    for s in coded:
        out.append(f"| {s['id']} | {s['turns']} | полная |")
        lengths.append(s["turns"])
    for s in src["counted_only"]:
        out.append(f"| {s['id']} | {s['turns']} | только счёт (замер ТЗ) |")
        lengths.append(s["turns"])
    for s in selected:
        mark = "отбор, примерно" if s.get("in_length_stats") else "отбор, не в статистике длины"
        out.append(f"| {s['id']} | {s['turns']} | {mark} |")
        if s.get("in_length_stats"):
            lengths.append(s["turns"])
    q1, q2, q3 = statistics.quantiles(lengths, n=4)
    out.append("")
    out.append(f"В статистике длины {len(lengths)} интервью: минимум {min(lengths)}, "
               f"нижняя четверть {q1:g}, медиана {q2:g}, верхняя четверть {q3:g}, максимум {max(lengths)}.")
    short = sum(1 for n in lengths if n <= 35)
    out.append(f"35 вопросов или меньше: {short} из {len(lengths)}.")
    total_new = sum(len(s["new_topics"]) for s in coded)
    total_turns = sum(s["turns"] for s in coded)
    out.append(f"Доля новых тем (только полная разметка): {total_new}/{total_turns} = {total_new / total_turns:.0%}.")

    coded_ids = {s["id"] for s in coded}
    out.append("\n## Частота приёмов\n")
    out.append("Пилот: сколько реплик шести полностью размеченных интервью помечены приёмом. "
               "Источников: в скольких источниках (всех пакетов и форматов) приём встретился.\n")
    out.append("| Код | Приём | Пилот, реплик | Источников |")
    out.append("|---|---|---|---|")
    rows = []
    covered = {s["id"]: set() for s in coded}
    for t in tech["techniques"]:
        ev = t.get("evidence") or {}
        pilot = sum(len(v) for k, v in ev.items() if k in coded_ids)
        rows.append((len(ev), pilot, t["id"], t["name"]))
        for sid, turns in ev.items():
            if sid in covered:
                covered[sid].update(turns)
    for n_src, pilot, tid, name in sorted(rows, key=lambda r: (-r[0], -r[1], r[2])):
        out.append(f"| {tid} | {name} | {pilot} | {n_src} |")

    out.append("\n## Покрытие разметкой пилота\n")
    out.append("| Интервью | Помечено | Из |")
    out.append("|---|---|---|")
    for s in coded:
        out.append(f"| {s['id']} | {len(covered[s['id']])} | {s['turns']} |")

    fam = Counter(t["family"] for t in tech["techniques"])
    out.append("\n## Семьи приёмов\n")
    out.append(", ".join(f"{k} {tech['families'][k]}: {fam[k]}" for k in tech["families"]))

    out.append("\n## Образцы вопросов\n")
    by_theme = Counter(q["theme"] for q in qs["questions"])
    out.append(f"Всего вопросов: {len(qs['questions'])}. По темам: "
               + ", ".join(f"{tech['themes'][k].split(' (')[0]} {n}" for k, n in by_theme.most_common()) + ".")
    used = {q["technique"] for q in qs["questions"]}
    missing = [t["id"] for t in tech["techniques"] if t["id"] not in used]
    out.append(f"Приёмов с образцами: {len(used)} из {len(tech['techniques'])}. Без образцов: {', '.join(missing)}.")

    out.append(f"\nИсточников: полная разметка {len(coded)}, отбор {len(selected)}, "
               f"форматы {len(formats)}, только счёт {len(src['counted_only'])}, недоступны {len(src['blocked'])}.")
    out.append(f"Всего приёмов: {len(tech['techniques'])}.")
    return "\n".join(out)


def main():
    tech, src, qs = load("techniques.yaml"), load("sources.yaml"), load("questions.yaml")
    errors = check(tech, src, qs)
    if errors:
        print("ОШИБКИ:")
        print("\n".join(errors))
        sys.exit(1)
    if "--check" in sys.argv:
        print("Проверка пройдена.")
        return
    print(report(tech, src, qs))


if __name__ == "__main__":
    main()
