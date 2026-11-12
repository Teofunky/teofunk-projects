#!/usr/bin/env python3
"""Нейрожурналист, этап 3.1: ядро интервьюера в терминале.

Ведёт интервью по досье, плану, принципам и банку приёмов. После каждого ответа
Claude заполняет журнал решения и пишет ровно один следующий вопрос (инструмент next_turn).
Всё сохраняется в папку сессии: session.json (источник), transcript.md, log.md.

Запуск (нужен ANTHROPIC_API_KEY в окружении):
    python3 interviewer.py --dossier ../pilot/ilugdin/dossier.md --plan ../pilot/ilugdin/plan.md
Продолжить прерванную сессию:
    python3 interviewer.py --resume ../pilot/ilugdin/sessions/<папка>
Проверка без API (подставной клиент):
    python3 interviewer.py --dossier ... --dry-run
"""
import argparse
import datetime as dt
import json
import os
import re
import sys
import time
from pathlib import Path

import yaml

HERE = Path(__file__).resolve().parent
APP = HERE.parent                      # apps/neurojournalist
BANK = APP / "bank"
DEFAULT_MODEL = os.environ.get("NJ_MODEL", "claude-opus-5-5")
SHORT_WORDS = 8
STOP_WORDS = {"стоп", "stop"}
ACTIONS = ["open", "clarify", "deepen", "listen_to_track", "respectful_challenge",
           "switch_topic", "pause", "end"]

TURN_TOOL = {
    "name": "next_turn",
    "description": "Журнал решения после последнего ответа собеседника и следующая реплика интервьюера.",
    "input_schema": {
        "type": "object",
        "properties": {
            "claim": {"type": "string", "description": "главное проверяемое утверждение последнего ответа"},
            "new_detail": {"type": "string", "description": "новый факт, эпизод, музыкальное решение"},
            "unexplained_term": {"type": "string", "description": "неточное или необычное слово"},
            "tension": {"type": "string", "description": "противоречие с прежним высказыванием или досье"},
            "emotion": {"type": "string", "description": "чувствительная тема, без диагноза"},
            "editorial_value": {"type": "string", "description": "что нового узнает читатель"},
            "next_action": {"type": "string", "enum": ACTIONS},
            "line": {"type": "string", "description": "номер или название линии плана"},
            "techniques": {"type": "array", "items": {"type": "string"},
                           "description": "коды приёмов банка, например C2, G12, M4"},
            "next_question": {"type": "string",
                              "description": "реплика интервьюера: ровно один вопрос (в финале без вопроса)"},
            "reason": {"type": "string", "description": "короткое редакционное основание"},
        },
        "required": ["next_action", "next_question", "reason"],
    },
}


# ---------- подготовка контекста

def bank_compact(bank_dir: Path) -> str:
    """Банк в компактном виде: по строке на приём и на вопрос."""
    tech = yaml.safe_load((bank_dir / "techniques.yaml").read_text(encoding="utf-8"))
    qs = yaml.safe_load((bank_dir / "questions.yaml").read_text(encoding="utf-8"))
    out = ["## Банк приёмов", "Код | название | шаблон | место в интервью"]
    for t in tech["techniques"]:
        out.append(f"{t['id']} | {t['name']} | {t['template']} | {t['placement']}")
    out += ["", "## Разделы"]
    out += [f"{k}: {v}" for k, v in qs["topics"].items()]
    out += ["", "## Образцы вопросов",
            "id | приём | раздел | вопрос ({...} подставляется из досье; [skip] можно пропустить)"]
    for q in qs["questions"]:
        skip = " [skip]" if q.get("skip") else ""
        out.append(f"{q['id']} | {q['technique']} | {q['topic']} | {q['q']}{skip}")
    return "\n".join(out)


def build_system(dossier: str, plan: str) -> list:
    prompt = (HERE / "prompts" / "interviewer_system.md").read_text(encoding="utf-8")
    principles = (APP / "principles.md").read_text(encoding="utf-8")
    context = "\n\n".join([
        "# Редакционные принципы\n\n" + principles,
        "# Досье героя\n\n" + dossier,
        "# План интервью\n\n" + (plan or "Плана нет: построй линии по досье сам."),
        "# Банк\n\n" + bank_compact(BANK),
    ])
    # Большой неизменный блок кешируется: каждый ход платим за него дёшево.
    return [{"type": "text", "text": prompt},
            {"type": "text", "text": context, "cache_control": {"type": "ephemeral"}}]


def hero_name(dossier: str) -> str:
    m = re.search(r"^#\s*Досье:\s*(.+)$", dossier, re.M)
    return m.group(1).strip() if m else "Собеседник"


# ---------- сессия

def now() -> str:
    return dt.datetime.now().isoformat(timespec="seconds")


def words(text: str) -> int:
    return len(re.findall(r"\w+", text or ""))


def short_streak(turns: list) -> int:
    n = 0
    for t in reversed([t for t in turns if t.get("answer") is not None]):
        if words(t["answer"]) < SHORT_WORDS:
            n += 1
        else:
            break
    return n


def render_for_model(session: dict) -> str:
    turns = session["turns"]
    if not turns:
        return "Разговор ещё не начат. Сделай первый ход (next_action: open)."
    lines = []
    for t in turns:
        lines.append(f"Интервьюер: {t['question']}")
        if t.get("answer") is not None:
            lines.append(f"{session['hero']}: {t['answer']}")
    last = turns[-1].get("answer", "")
    stop = (last or "").strip().lower().strip(".!") in STOP_WORDS
    tail = [f"Коротких ответов подряд (меньше {SHORT_WORDS} слов): {short_streak(turns)}.",
            f"Номер следующего хода: {len(turns) + 1}."]
    if stop:
        tail.append("Собеседник написал «стоп»: заверши интервью (next_action: end).")
    tail.append("Сделай следующий ход.")
    return "<transcript>\n" + "\n".join(lines) + "\n</transcript>\n\n" + "\n".join(tail)


def write_outputs(session: dict, folder: Path) -> None:
    folder.mkdir(parents=True, exist_ok=True)
    (folder / "session.json").write_text(json.dumps(session, ensure_ascii=False, indent=1), encoding="utf-8")
    tr = [f"# Расшифровка: {session['hero']}", "",
          f"Начато {session['started']}. Модель {session['model']}. Дословно, без правок.", ""]
    lg = [f"# Журнал решений: {session['hero']}", "", "Собеседник журнал не видит.", ""]
    for t in session["turns"]:
        tr += [f"**Интервьюер:** {t['question']}", ""]
        if t.get("answer") is not None:
            tr += [f"**{session['hero']}:** {t['answer']}", ""]
        d = t["decision"]
        lg.append(f"## Ход {t['n']}")
        for key in ("claim", "new_detail", "unexplained_term", "tension", "emotion", "editorial_value",
                    "next_action", "line", "techniques", "reason"):
            if d.get(key):
                val = ", ".join(d[key]) if isinstance(d[key], list) else d[key]
                lg.append(f"- `{key}`: {val}")
        u = t.get("usage")
        if u:
            lg.append(f"- `usage`: вход {u['input']}, из кеша {u['cache_read']}, в кеш {u['cache_write']}, выход {u['output']}")
        for note in t.get("notes", []):
            lg.append(f"- `note`: {note}")
        lg.append("")
    (folder / "transcript.md").write_text("\n".join(tr), encoding="utf-8")
    (folder / "log.md").write_text("\n".join(lg), encoding="utf-8")


# ---------- вызов модели

def next_turn(client, model: str, system: list, session: dict, retries: int = 2) -> tuple[dict, dict]:
    msg = render_for_model(session)
    for attempt in range(retries + 1):
        try:
            resp = client.messages.create(
                model=model, max_tokens=2000, system=system,
                messages=[{"role": "user", "content": msg}],
                tools=[TURN_TOOL], tool_choice={"type": "tool", "name": "next_turn"},
            )
            break
        except Exception as e:  # сеть, лимиты, перегрузка
            if attempt == retries:
                raise
            print(f"[ошибка API: {e}; повтор через {5 * (attempt + 1)} с]", file=sys.stderr)
            time.sleep(5 * (attempt + 1))
    block = next(b for b in resp.content if getattr(b, "type", "") == "tool_use")
    u = resp.usage
    usage = {"input": getattr(u, "input_tokens", 0), "output": getattr(u, "output_tokens", 0),
             "cache_read": getattr(u, "cache_read_input_tokens", 0) or 0,
             "cache_write": getattr(u, "cache_creation_input_tokens", 0) or 0}
    return dict(block.input), usage


def clean_question(text: str) -> tuple[str, list]:
    notes = []
    if "—" in text:
        text = text.replace(" — ", ", ").replace("—", ", ")
        notes.append("длинное тире в вопросе заменено запятой")
    return text.strip(), notes


def step(client, model: str, system: list, session: dict, folder: Path) -> dict:
    """Один ход интервьюера: спросить модель, добавить вопрос в сессию, сохранить файлы.

    Общая часть для терминала и веб-чата. Ответ на предыдущий вопрос должен быть уже записан.
    """
    decision, usage = next_turn(client, model, system, session)
    q, notes = clean_question(decision.get("next_question", ""))
    turn = {"n": len(session["turns"]) + 1, "decision": decision, "question": q,
            "answer": None, "asked_at": now(), "usage": usage, "notes": notes}
    session["turns"].append(turn)
    if decision.get("next_action") == "end":
        session["finished"] = now()
    write_outputs(session, folder)
    return turn


# ---------- подставной клиент для проверки без API

class FakeClient:
    """Возвращает заранее заданные ходы; нужен для --dry-run и тестов."""

    def __init__(self, script=None):
        self.calls = []
        self.script = script or [
            {"next_action": "open", "next_question": "Здравствуйте. Первый вопрос?", "reason": "тест", "techniques": ["A2"]},
            {"next_action": "deepen", "next_question": "Второй вопрос?", "reason": "тест", "claim": "ответ 1"},
            {"next_action": "end", "next_question": "Спасибо за разговор.", "reason": "тест"},
        ]
        self.messages = self

    def create(self, **kw):
        from types import SimpleNamespace as NS
        self.calls.append(kw)
        # шаг выбираем по расшифровке, как настоящая модель, а не по числу вызовов
        done = kw["messages"][0]["content"].count("Интервьюер:")
        step = self.script[min(done, len(self.script) - 1)]
        return NS(content=[NS(type="tool_use", name="next_turn", input=step)],
                  usage=NS(input_tokens=100, output_tokens=50, cache_read_input_tokens=0,
                           cache_creation_input_tokens=0))


# ---------- основной цикл

def run(client, model: str, session: dict, folder: Path, system: list, read=input, show=print) -> dict:
    while True:
        if session["turns"] and session["turns"][-1].get("answer") is None:
            turn = session["turns"][-1]          # вопрос задан, ответа ещё нет (после resume)
        else:
            turn = step(client, model, system, session, folder)
        show(f"\nИнтервьюер: {turn['question']}\n")
        if turn["decision"].get("next_action") == "end":
            session.setdefault("finished", now())
            write_outputs(session, folder)
            show(f"[интервью завершено; файлы: {folder}]")
            return session
        try:
            answer = read(f"{session['hero']}: ").strip()
        except (EOFError, KeyboardInterrupt):
            write_outputs(session, folder)
            show(f"\n[сессия сохранена, продолжить: --resume {folder}]")
            return session
        turn["answer"], turn["answered_at"] = answer, now()
        write_outputs(session, folder)


def main(argv=None):
    ap = argparse.ArgumentParser(description="Нейрожурналист: интервью в терминале (этап 3.1)")
    ap.add_argument("--dossier", type=Path, help="досье героя (Markdown)")
    ap.add_argument("--plan", type=Path, help="план интервью (Markdown), необязательно")
    ap.add_argument("--out", type=Path, help="папка сессии; по умолчанию рядом с досье, в sessions/")
    ap.add_argument("--resume", type=Path, help="продолжить сессию из папки")
    ap.add_argument("--model", default=DEFAULT_MODEL)
    ap.add_argument("--dry-run", action="store_true", help="без API, подставной клиент")
    a = ap.parse_args(argv)

    if a.resume:
        folder = a.resume
        session = json.loads((folder / "session.json").read_text(encoding="utf-8"))
        dossier = Path(session["dossier"]).read_text(encoding="utf-8")
        plan = Path(session["plan"]).read_text(encoding="utf-8") if session.get("plan") else ""
    else:
        if not a.dossier:
            ap.error("нужен --dossier или --resume")
        dossier = a.dossier.read_text(encoding="utf-8")
        plan = a.plan.read_text(encoding="utf-8") if a.plan else ""
        stamp = dt.datetime.now().strftime("%Y%m%d-%H%M")
        folder = a.out or (a.dossier.parent / "sessions" / stamp)
        session = {"hero": hero_name(dossier), "model": a.model, "started": now(),
                   "dossier": str(a.dossier.resolve()), "plan": str(a.plan.resolve()) if a.plan else "",
                   "turns": []}

    if a.dry_run:
        client = FakeClient()
    else:
        if not os.environ.get("ANTHROPIC_API_KEY"):
            sys.exit("Нет ANTHROPIC_API_KEY в окружении. Пример: export ANTHROPIC_API_KEY=...")
        import anthropic
        client = anthropic.Anthropic()

    run(client, session.get("model", a.model), session, folder, build_system(dossier, plan))


if __name__ == "__main__":
    main()
