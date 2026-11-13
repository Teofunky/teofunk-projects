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
        self.calls = []         # прочие методы API: (method, params)
        self.typed = 0

    def send(self, chat_id, text):
        self.sent.append((chat_id, text))

    def typing(self, chat_id):
        self.typed += 1

    def call(self, method, **params):
        self.calls.append((method, params))

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


def msg(chat, text=None, date=None, voice=None, mid=None):
    m = {"chat": {"id": chat}, "date": date or 0}
    if mid:
        m["message_id"] = mid
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


class Down(iv.FakeClient):
    """API недоступно, пока down=True."""
    def __init__(self, script=None):
        super().__init__(script)
        self.down = True

    def create(self, **kw):
        if self.down:
            raise RuntimeError("overloaded")
        return super().create(**kw)


def test_message_before_first_question_does_not_crash():
    # случай 13.11.2026: первый вопрос не задан из-за сбоя API, герой пишет, бот падал в flush
    iv.time.sleep = lambda _s: None
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.client = Down()
        bot.handle(msg(5, "/start INV"))
        bot.handle(msg(5, "здравствуйте, я тут", date=clock.t))
        clock.t += 41
        bot.tick()                                           # раньше: IndexError
        assert bot.s["before_first"] == ["здравствуйте, я тут"] and not bot.s["turns"]
        bot.client.down = False
        clock.t += b.RETRY_AFTER + 1
        bot.tick()
        assert tg.to(5)[-1] == "Здравствуйте. Первый вопрос?"
        assert "здравствуйте, я тут" in bot.client.calls[-1]["messages"][0]["content"]


def test_message_while_next_question_fails_is_appended():
    iv.time.sleep = lambda _s: None
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(5, "/start INV"))
        bot.client = Down()
        bot.handle(msg(5, "ответ", date=clock.t))
        clock.t += 41
        bot.tick()                                           # ответ записан, второй вопрос не задан
        bot.handle(msg(5, "и ещё", date=clock.t))
        clock.t += 41
        bot.tick()
        assert bot.s["turns"][0]["answer"] == "ответ\n\nи ещё"      # не затёрт
        bot.client.down = False
        clock.t += b.RETRY_AFTER + 1
        bot.tick()
        assert tg.to(5)[-1] == "Второй вопрос?"


def test_resume_asks_waiting_hero():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.s["hero_chat_id"] = 5                            # герой зашёл, вопроса не получил
        bot.resume_hero()
        assert tg.to(5) == ["Здравствуйте. Первый вопрос?"]
        bot.resume_hero()                                    # вопрос уже отправлен: не дублировать
        assert tg.to(5) == ["Здравствуйте. Первый вопрос?"]


def test_api_call_shape_for_opus_5_5():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(5, "/start INV"))
        kw = bot.client.calls[0]
        assert kw["tool_choice"] == {"type": "auto"}         # "tool"/"any" Opus 5.5 отклоняет
        assert kw["max_tokens"] >= 8000                      # размышление входит в max_tokens


def test_no_tool_call_retries_with_nudge():
    from types import SimpleNamespace as NS

    class Chatty(iv.FakeClient):
        def create(self, **kw):
            if not self.calls:
                self.calls.append(kw)
                return NS(content=[NS(type="text", text="Здравствуйте!")], stop_reason="end_turn",
                          usage=NS(input_tokens=1, output_tokens=1))
            return super().create(**kw)

    class Bad(Exception):
        status_code = 400

    class Rejecting(iv.FakeClient):
        def create(self, **kw):
            self.calls.append(kw)
            raise Bad("invalid_request_error")

    iv.time.sleep = lambda _s: None
    s = {"hero": "Д", "turns": []}
    c = Chatty()
    decision, _ = iv.next_turn(c, "m", [], s)
    assert decision["next_question"] == "Здравствуйте. Первый вопрос?"
    assert c.calls[1]["messages"][0]["content"].endswith(iv.NUDGE)
    r = Rejecting()
    try:
        iv.next_turn(r, "m", [], s)
        raise AssertionError("ошибка 400 должна пробрасываться")
    except Bad:
        pass
    assert len(r.calls) == 1                                 # 400 не повторяем


def test_pending_answer_survives_restart():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(5, "/start INV"))
        bot.handle(msg(5, "половина ответа", date=clock.t))
        # бот упал до окончания тишины: поднимаем новый из файла
        s = json.loads((Path(d) / "session.json").read_text(encoding="utf-8"))
        bot2 = b.Bot(tg, iv.FakeClient(), "fake", bot.system, s, Path(d), quiet=40, clock=clock)
        assert bot2.pending == ["половина ответа"]
        clock.t += 41
        bot2.tick()
        assert bot2.s["turns"][0]["answer"] == "половина ответа" and tg.to(5)[-1] == "Второй вопрос?"


def test_voice_original_saved_and_forwarded():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(9, "/start ED"))
        bot.handle(msg(5, "/start INV"))
        bot.handle(msg(5, voice="v1", date=clock.t, mid=77))
        clock.t += 41
        bot.tick()
        t0 = bot.s["turns"][0]
        assert t0["audio"] == ["audio/01-77.ogg"]
        assert (Path(d) / "audio" / "01-77.ogg").read_bytes() == b"OGGv1"
        assert ("forwardMessage", {"chat_id": 9, "from_chat_id": 5, "message_id": 77}) in tg.calls


def test_voice_saved_even_if_transcription_fails():
    def broken(_audio):
        raise RuntimeError("нет ключа")
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d, stt=broken)
        bot.handle(msg(5, "/start INV"))
        bot.handle(msg(5, voice="v2", date=clock.t, mid=78))
        assert (Path(d) / "audio" / "01-78.ogg").exists()


def test_session_write_is_atomic():
    with tempfile.TemporaryDirectory() as d:
        bot, tg, clock = make(d)
        bot.handle(msg(5, "/start INV"))
        assert not list(Path(d).glob("*.tmp"))
        json.loads((Path(d) / "session.json").read_text(encoding="utf-8"))


if __name__ == "__main__":
    for name, fn in list(globals().items()):
        if name.startswith("test_"):
            fn()
            print("ok", name)
