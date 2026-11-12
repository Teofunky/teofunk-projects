"""Тесты ядра интервьюера без API: python3 -m pytest test_interviewer.py (или python3 test_interviewer.py)."""
import json
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import interviewer as iv  # noqa: E402

DOSSIER = iv.APP / "pilot" / "ilugdin" / "dossier.md"
PLAN = iv.APP / "pilot" / "ilugdin" / "plan.md"


def test_system_has_all_context_and_cache():
    system = iv.build_system(DOSSIER.read_text(encoding="utf-8"), PLAN.read_text(encoding="utf-8"))
    assert len(system) == 2 and system[1]["cache_control"] == {"type": "ephemeral"}
    ctx = system[1]["text"]
    for marker in ("# Редакционные принципы", "# Досье героя", "# План интервью", "## Банк приёмов",
                   "M17 |", "NJ-15-04 |", "Q60 |", "Prelude No. 5"):
        assert marker in ctx, marker


def test_hero_name():
    assert iv.hero_name(DOSSIER.read_text(encoding="utf-8")) == "Дмитрий Илугдин"


def test_short_streak_and_render():
    s = {"hero": "Д", "turns": [
        {"question": "q1", "answer": "длинный ответ из многих слов про музыку и про жизнь вообще"},
        {"question": "q2", "answer": "сложился сам"},
        {"question": "q3", "answer": "нашёл своё"},
    ]}
    assert iv.short_streak(s["turns"]) == 2
    msg = iv.render_for_model(s)
    assert "Коротких ответов подряд (меньше 8 слов): 2." in msg
    assert "Номер следующего хода: 4." in msg
    s["turns"].append({"question": "q4", "answer": "Стоп."})
    assert "заверши интервью" in iv.render_for_model(s)


def test_clean_question_removes_em_dash():
    q, notes = iv.clean_question("Что изменилось — она или вы?")
    assert "—" not in q and notes


def test_full_dry_run_writes_files_and_resumes():
    with tempfile.TemporaryDirectory() as d:
        folder = Path(d) / "s"
        answers = iter(["первый ответ"])
        out = []

        def read(_prompt):
            try:
                return next(answers)
            except StopIteration:
                raise EOFError

        client = iv.FakeClient()
        system = iv.build_system("# Досье: Тест\n", "")
        session = {"hero": "Тест", "model": "fake", "started": iv.now(), "dossier": "", "plan": "", "turns": []}
        iv.run(client, "fake", session, folder, system, read=read, show=out.append)
        # открытие, ответ, второй вопрос; затем EOF: сессия сохранена без ответа на второй вопрос
        data = json.loads((folder / "session.json").read_text(encoding="utf-8"))
        assert [t["n"] for t in data["turns"]] == [1, 2]
        assert data["turns"][0]["answer"] == "первый ответ" and data["turns"][1]["answer"] is None
        assert client.calls[0]["tool_choice"] == {"type": "tool", "name": "next_turn"}
        assert "**Тест:** первый ответ" in (folder / "transcript.md").read_text(encoding="utf-8")
        assert "`next_action`: deepen" in (folder / "log.md").read_text(encoding="utf-8")

        # продолжение: тот же второй вопрос показывается снова, после ответа модель завершает
        answers2 = iter(["второй ответ"])
        iv.run(client, "fake", data, folder, system, read=lambda _p: next(answers2), show=out.append)
        data2 = json.loads((folder / "session.json").read_text(encoding="utf-8"))
        assert data2["turns"][-1]["decision"]["next_action"] == "end" and "finished" in data2
        assert len(client.calls) == 3


if __name__ == "__main__":
    for name, fn in list(globals().items()):
        if name.startswith("test_"):
            fn()
            print("ok", name)
