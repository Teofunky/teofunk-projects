"""Тесты бота без сети: подставные Telegram, Claude и расшифровка. python3 test_bot.py"""
import json
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import bot as b  # noqa: E402
import interviewer as iv  # noqa: E402


class FakeTG:
    def __init__(self):
        self.sent = []          # (chat_id, text)
        self.typed = 0

    def send(self, chat_id, text):
        self.sent.append((chat_id, text))

    def typing(self, chat_id):
        self.typed += 1

    def download(self, file_id):
        return b"OGG" + file_id.encode()

    def to(self, chat):
        return [t for c, t in self.sent if c == chat]


class Clock:
    def __init__(self):
        self.t = 1_000_000.0

    def __call__(self):
        return self.t


def make(tmp, script=None, stt=None):
    clock = Clock()
    s = {"hero": "Дмитрий Илугдин", "model": "fake", "started": iv.now(), "dossier": "", "plan": "",
         "turns": [], "invite": "INV", "editor_token": "ED"}
    tg = FakeTG()
    bot = b.Bot(tg, iv.FakeClient(script), "fake", iv.build_system("# Досье: Дмитрий Илугдин\n", ""),
                s, Path(tmp), stt=stt or (lambda audio: "расшифровка " + audio.decode()), quiet=40, clock=clock)
    return bot, tg, clock


def msg(chat, text=None, date=None, voice=None):
    m = {"chat": {"id": chat}, "date": date or 0}
    if text is not None:
        m["text"] = text
    if voice:
        m["voice"] = {"file_id": voice}
    return m


def test_access_control_and_start():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(7, "/start"))
        bot.handle(msg(7, "/start WRONG"))
        assert tg.to(7) == [b.FOREIGN, b.FOREIGN]
        bot.handle(msg(9, "/start ED"))                       # редактор
        bot.handle(msg(5, "/start INV"))                      # герой
        assert bot.s["hero_chat_id"] == 5 and bot.s["editor_chat_id"] == 9
        hero = tg.to(5)
        assert hero[0].startswith("Отвечайте как удобно") and hero[1] == "Здравствуйте. Первый вопрос?"
        assert any(t.startswith("Вопрос 1:") for t in tg.to(9))
        bot.handle(msg(7, "/start INV"))                      # чужой с той же ссылкой после привязки
        assert tg.to(7)[-1] == b.FOREIGN
        bot.handle(msg(9, "ответ от редактора"))
        assert "наблюдатель" in tg.to(9)[-1]


def test_quiet_window_joins_parts_and_voice():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(5, "/start INV"))
        bot.handle(msg(5, "первая часть", date=clock.t + 5))
        clock.t += 10
        bot.handle(msg(5, voice="v1", date=clock.t))
        clock.t += 39
        bot.tick()
        assert bot.s["turns"][0]["answer"] is None          # тишина ещё не 40 секунд
        clock.t += 2
        bot.tick()
        t0 = bot.s["turns"][0]
        assert t0["answer"] == "первая часть\n\nрасшифровка OGGv1"
        assert any("голосом" in n for n in t0["notes"])
        assert tg.to(5)[-1] == "Второй вопрос?"
        data = json.loads((Path(d) / "session.json").read_text(encoding="utf-8"))
        assert data["turns"][1]["question"] == "Второй вопрос?"


def test_late_message_appends_to_previous_answer():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(5, "/start INV"))
        bot.handle(msg(5, "ответ", date=clock.t))
        clock.t += 41
        bot.tick()                                           # задан второй вопрос в момент clock.t
        bot.handle(msg(5, "и ещё забыл сказать", date=clock.t - 20))
        assert bot.s["turns"][0]["answer"] == "ответ\n\nи ещё забыл сказать"
        assert bot.pending == []


def test_stop_ends_immediately():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(5, "/start INV"))
        bot.handle(msg(5, "ответ", date=clock.t))
        clock.t += 41
        bot.tick()
        bot.handle(msg(5, "Стоп", date=clock.t + 1))
        bot.tick()                                           # без ожидания тишины
        assert bot.s.get("finished") and tg.to(5)[-1] == "Спасибо за разговор."
        bot.handle(msg(5, "ещё что-то", date=clock.t + 2))
        assert tg.to(5)[-1] == "Интервью завершено. Спасибо!"


def test_voice_failure_asks_to_repeat():
    def broken(_audio):
        raise RuntimeError("нет ключа")
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d, stt=broken)
        bot.handle(msg(5, "/start INV"))
        bot.handle(msg(5, voice="v1", date=clock.t))
        assert "расшифровать" in tg.to(5)[-1] and bot.pending == []


def test_api_failure_retries_later():
    class Flaky(iv.FakeClient):
        """Отказывает три раза подряд: ядро исчерпывает свои два повтора, дальше повторяет бот."""
        def __init__(self):
            super().__init__()
            self.fails = 3

        def create(self, **kw):
            if self.fails:
                self.fails -= 1
                raise RuntimeError("overloaded")
            return super().create(**kw)

    iv.time.sleep = lambda _s: None          # не ждать между повторами в тесте
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.client = Flaky()
        bot.handle(msg(5, "/start INV"))
        assert bot.retry_at and not bot.s["turns"]
        clock.t += b.RETRY_AFTER + 1
        bot.tick()
        assert bot.s["turns"] and tg.to(5)[-1] == "Здравствуйте. Первый вопрос?"


if __name__ == "__main__":
    for name, fn in list(globals().items()):
        if name.startswith("test_"):
            fn()
            print("ok", name)
