#!/usr/bin/env python3
"""Нейрожурналист: интервью через Telegram-бота, который работает на Mac редактора.

Бот сам опрашивает Telegram (long polling), поэтому не нужны ни сервер, ни туннель.
Герой входит по личной ссылке-приглашению и отвечает текстом или голосовыми.
Голосовые расшифровывает OpenAI. Редактор может наблюдать по своей ссылке.

Окружение:
    ANTHROPIC_API_KEY   ключ Claude API
    TELEGRAM_BOT_TOKEN  токен бота от @BotFather
    OPENAI_API_KEY      для голосовых (без него бот попросит писать текстом)

Запуск:
    caffeinate -i python3 bot.py --dossier ../pilot/ilugdin/dossier.md --plan ../pilot/ilugdin/plan.md
Продолжить после перезапуска (ссылки сохраняются):
    caffeinate -i python3 bot.py --resume ../pilot/ilugdin/sessions/<папка>
"""
import argparse
import datetime as dt
import json
import os
import secrets
import sys
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import interviewer as iv  # noqa: E402

# Python с python.org на Mac не видит системные сертификаты, и HTTPS к Telegram и OpenAI падает
# с CERTIFICATE_VERIFY_FAILED. truststore (ставится вместе с anthropic) берёт сертификаты из macOS.
try:
    import truststore
    truststore.inject_into_ssl()
except Exception:  # нет библиотеки: остаются сертификаты Python по умолчанию
    pass

QUIET = 40                       # секунд тишины, после которых ответ считается законченным
RETRY_AFTER = 60                 # повтор хода после сбоя API
STT_MODEL = os.environ.get("NJ_STT_MODEL", "gpt-4o-transcribe")

HINT = ("Отвечайте как удобно: текстом или голосовыми, можно несколькими сообщениями подряд. "
        "Следующий вопрос придёт примерно через {q} секунд после вашего последнего сообщения. "
        "Любой вопрос можно пропустить, написав «дальше». Закончить: «стоп».")
FOREIGN = "Это личный бот редакции «Ежедневной Джазеты». Для разговора нужна ссылка-приглашение."


# ---------- Telegram и расшифровка

class Telegram:
    def __init__(self, token: str):
        self.base = f"https://api.telegram.org/bot{token}"
        self.files = f"https://api.telegram.org/file/bot{token}"

    def call(self, method: str, timeout: int = 30, **params):
        req = urllib.request.Request(f"{self.base}/{method}", json.dumps(params).encode("utf-8"),
                                     {"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=timeout) as r:
            res = json.load(r)
        if not res.get("ok"):
            raise RuntimeError(f"Telegram {method}: {res}")
        return res["result"]

    def me(self):
        return self.call("getMe")

    def updates(self, offset, wait: int):
        """Long polling: Telegram держит запрос до wait секунд, пока нет сообщений."""
        params = {"timeout": wait, "allowed_updates": ["message"]}
        if offset is not None:
            params["offset"] = offset
        req = urllib.request.Request(f"{self.base}/getUpdates", json.dumps(params).encode("utf-8"),
                                     {"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=wait + 15) as r:
            res = json.load(r)
        if not res.get("ok"):
            raise RuntimeError(f"Telegram getUpdates: {res}")
        return res["result"]

    def send(self, chat_id, text: str):
        for i in range(0, len(text), 4000):            # лимит Telegram 4096 символов
            self.call("sendMessage", chat_id=chat_id, text=text[i:i + 4000])

    def typing(self, chat_id):
        try:
            self.call("sendChatAction", chat_id=chat_id, action="typing")
        except Exception:
            pass

    def download(self, file_id: str) -> bytes:
        f = self.call("getFile", file_id=file_id)
        with urllib.request.urlopen(f"{self.files}/{f['file_path']}", timeout=120) as r:
            return r.read()


def transcribe_openai(audio: bytes, filename: str = "voice.ogg") -> str:
    """Расшифровка через OpenAI; при ошибке модели по умолчанию пробует whisper-1."""
    key = os.environ.get("OPENAI_API_KEY")
    if not key:
        raise RuntimeError("нет OPENAI_API_KEY")
    last = None
    for model in dict.fromkeys([STT_MODEL, "whisper-1"]):
        boundary = uuid.uuid4().hex
        parts = []
        for name, value in (("model", model), ("language", "ru"), ("response_format", "json")):
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\n'
                     f'Content-Type: audio/ogg\r\n\r\n'.encode() + audio + b"\r\n")
        parts.append(f"--{boundary}--\r\n".encode())
        req = urllib.request.Request("https://api.openai.com/v1/audio/transcriptions", b"".join(parts),
                                     {"Authorization": f"Bearer {key}",
                                      "Content-Type": f"multipart/form-data; boundary={boundary}"})
        try:
            with urllib.request.urlopen(req, timeout=180) as r:
                return json.load(r)["text"].strip()
        except urllib.error.HTTPError as e:
            last = e
            if e.code in (400, 404) and model != "whisper-1":
                continue
            raise
    raise last


# ---------- логика бота

class Bot:
    def __init__(self, tg, client, model, system, session, folder,
                 stt=transcribe_openai, quiet=QUIET, clock=time.time):
        self.tg, self.client, self.model, self.system = tg, client, model, system
        self.s, self.folder, self.stt, self.quiet, self.clock = session, folder, stt, quiet, clock
        self.pending, self.kinds, self.last_at = [], [], None
        self.retry_at = None

    # служебное
    def save(self):
        iv.write_outputs(self.s, self.folder)

    def editor(self, text: str):
        if self.s.get("editor_chat_id"):
            self.tg.send(self.s["editor_chat_id"], text)

    def ask(self, turn: dict):
        self.tg.send(self.s["hero_chat_id"], turn["question"])
        turn["sent_at"] = self.clock()
        self.save()
        self.editor(f"Вопрос {turn['n']}: {turn['question']}")

    def next_question(self):
        self.tg.typing(self.s["hero_chat_id"])
        try:
            turn = iv.step(self.client, self.model, self.system, self.s, self.folder)
        except Exception as e:
            print(f"[ошибка хода: {e}; повтор через {RETRY_AFTER} с]", file=sys.stderr)
            self.retry_at = self.clock() + RETRY_AFTER
            self.editor(f"Сбой при подготовке вопроса: {e}. Повтор через {RETRY_AFTER} с.")
            return
        self.retry_at = None
        self.ask(turn)
        if self.s.get("finished"):
            self.editor("Интервью завершено. Файлы на Mac: " + str(self.folder))

    # входящие сообщения
    def handle(self, msg: dict):
        chat = msg["chat"]["id"]
        text = (msg.get("text") or "").strip()
        if text.startswith("/start"):
            arg = text.split(maxsplit=1)[1] if " " in text else ""
            if arg and arg == self.s["invite"] and self.s.get("hero_chat_id") in (None, chat):
                return self.start_hero(chat)
            if arg and arg == self.s["editor_token"]:
                self.s["editor_chat_id"] = chat
                self.save()
                return self.tg.send(chat, f"Вы наблюдаете за интервью: {self.s['hero']}. Сюда будут приходить вопросы и ответы.")
        if chat == self.s.get("hero_chat_id"):
            return self.hero_message(msg, text)
        if chat == self.s.get("editor_chat_id"):
            return self.tg.send(chat, "Вы наблюдатель: ответы принимаются только от героя.")
        self.tg.send(chat, FOREIGN)

    def start_hero(self, chat):
        first = self.s.get("hero_chat_id") is None
        self.s["hero_chat_id"] = chat
        self.save()
        if first:
            self.tg.send(chat, HINT.format(q=self.quiet))
            self.editor(f"{self.s['hero']} открыл бота.")
        if not self.s["turns"]:
            return self.next_question()
        if self.s.get("finished"):
            return self.tg.send(chat, "Интервью завершено. Спасибо!")
        last = self.s["turns"][-1]
        if last.get("answer") is None:
            self.ask(last)                     # повторить текущий вопрос

    def hero_message(self, msg: dict, text: str):
        if self.s.get("finished"):
            return self.tg.send(msg["chat"]["id"], "Интервью завершено. Спасибо!")
        kind = "text"
        media = msg.get("voice") or msg.get("audio")
        if media:
            kind = "voice"
            try:
                text = self.stt(self.tg.download(media["file_id"]))
            except Exception as e:
                print(f"[расшифровка не удалась: {e}]", file=sys.stderr)
                return self.tg.send(msg["chat"]["id"], "Не получилось расшифровать голосовое. "
                                                       "Повторите, пожалуйста, или напишите текстом.")
            self.editor(f"(голосовое, расшифровка) {text}")
        elif not text:
            return self.tg.send(msg["chat"]["id"], "Пока принимаю текст и голосовые сообщения.")
        turns = self.s["turns"]
        last = turns[-1] if turns else None
        # сообщение написано, пока интервьюер готовил вопрос: это дополнение к прошлому ответу
        if (last and last.get("answer") is None and last.get("sent_at")
                and msg.get("date", self.clock()) < last["sent_at"] - 1 and len(turns) > 1):
            prev = turns[-2]
            prev["answer"] = (prev.get("answer") or "") + "\n\n" + text
            prev.setdefault("notes", []).append("дополнение пришло, пока готовился следующий вопрос")
            self.save()
            return
        self.pending.append(text)
        self.kinds.append(kind)
        self.last_at = self.clock()
        if kind == "text":
            self.editor(f"Ответ: {text}")

    def ready(self) -> bool:
        if not self.pending:
            return False
        if self.pending[-1].strip().lower().strip(".!") in iv.STOP_WORDS:
            return True
        return self.clock() - self.last_at >= self.quiet

    def flush(self):
        text, voice = "\n\n".join(self.pending), "voice" in self.kinds
        self.pending, self.kinds, self.last_at = [], [], None
        turns = self.s["turns"]
        if not turns:
            # первый вопрос ещё не задан (например, сбой API): сохраняем как реплику до интервью
            self.s.setdefault("before_first", []).append(text)
        elif turns[-1].get("answer") is not None:
            # ответ уже записан, а следующий вопрос не задан из-за сбоя: дописываем, не затираем
            last = turns[-1]
            last["answer"] += "\n\n" + text
            last.setdefault("notes", []).append("дополнение пришло до следующего вопроса")
        else:
            last = turns[-1]
            last["answer"] = text
            last["answered_at"] = iv.now()
            if voice:
                last.setdefault("notes", []).append(f"ответ голосом, расшифровка OpenAI ({STT_MODEL})")
        self.save()
        self.next_question()

    def resume_hero(self):
        """После перезапуска: если герой уже привязан и ждёт вопроса, задать его."""
        if not self.s.get("hero_chat_id") or self.s.get("finished"):
            return
        turns = self.s["turns"]
        if not turns or turns[-1].get("answer") is not None:
            self.next_question()
        elif not turns[-1].get("sent_at"):
            self.ask(turns[-1])

    def tick(self):
        """Вызывается после каждого опроса Telegram."""
        if self.ready():
            self.flush()
        elif self.retry_at and self.clock() >= self.retry_at:
            self.next_question()

    def wait_seconds(self) -> int:
        if self.pending:
            return max(1, int(self.quiet - (self.clock() - self.last_at)) + 1)
        if self.retry_at:
            return max(1, int(self.retry_at - self.clock()) + 1)
        return 50


def poll_forever(bot: Bot):
    offset = bot.s.get("tg_offset")
    while True:
        try:
            updates = bot.tg.updates(offset, min(50, bot.wait_seconds()))
        except Exception as e:
            print(f"[Telegram недоступен: {e}; повтор через 5 с]", file=sys.stderr)
            time.sleep(5)
            continue
        for u in updates:
            offset = u["update_id"] + 1
            bot.s["tg_offset"] = offset
            if "message" in u:
                try:
                    bot.handle(u["message"])
                except Exception as e:
                    print(f"[ошибка обработки сообщения: {e}]", file=sys.stderr)
        bot.save()
        try:
            bot.tick()
        except Exception as e:                 # бот не должен падать посреди интервью
            print(f"[ошибка хода бота: {e}]", file=sys.stderr)


def main(argv=None):
    ap = argparse.ArgumentParser(description="Нейрожурналист: интервью через Telegram-бота")
    ap.add_argument("--dossier", type=Path)
    ap.add_argument("--plan", type=Path)
    ap.add_argument("--out", type=Path)
    ap.add_argument("--resume", type=Path)
    ap.add_argument("--model", default=iv.DEFAULT_MODEL)
    ap.add_argument("--quiet", type=int, default=QUIET, help="секунд тишины до следующего вопроса")
    a = ap.parse_args(argv)

    for var in ("ANTHROPIC_API_KEY", "TELEGRAM_BOT_TOKEN"):
        if not os.environ.get(var):
            sys.exit(f"Нет {var} в окружении.")
    if not os.environ.get("OPENAI_API_KEY"):
        print("Внимание: нет OPENAI_API_KEY, голосовые расшифровываться не будут.")

    if a.resume:
        folder = a.resume
        s = json.loads((folder / "session.json").read_text(encoding="utf-8"))
        dossier = Path(s["dossier"]).read_text(encoding="utf-8")
        plan = Path(s["plan"]).read_text(encoding="utf-8") if s.get("plan") else ""
    else:
        if not a.dossier:
            ap.error("нужен --dossier или --resume")
        dossier = a.dossier.read_text(encoding="utf-8")
        plan = a.plan.read_text(encoding="utf-8") if a.plan else ""
        folder = a.out or (a.dossier.parent / "sessions" / dt.datetime.now().strftime("%Y%m%d-%H%M"))
        s = {"hero": iv.hero_name(dossier), "model": a.model, "started": iv.now(),
             "dossier": str(a.dossier.resolve()), "plan": str(a.plan.resolve()) if a.plan else "",
             "turns": []}
    s.setdefault("invite", secrets.token_urlsafe(12))
    s.setdefault("editor_token", secrets.token_urlsafe(12))

    import anthropic
    tg = Telegram(os.environ["TELEGRAM_BOT_TOKEN"])
    bot = Bot(tg, anthropic.Anthropic(), s.get("model", a.model), iv.build_system(dossier, plan),
              s, folder, quiet=a.quiet)
    bot.save()
    print("Подключаюсь к Telegram...", flush=True)
    try:
        name = tg.me()["username"]
    except Exception as e:
        sys.exit(f"Не удалось подключиться к Telegram: {e}\n"
                 "Проверьте токен бота (TELEGRAM_BOT_TOKEN) и интернет.")
    print(f"\nБот @{name} работает.")
    print(f"Ссылка для героя:     https://t.me/{name}?start={s['invite']}")
    print(f"Ссылка наблюдателя:   https://t.me/{name}?start={s['editor_token']}")
    print(f"Файлы сессии: {folder}")
    print("Остановить: Ctrl+C. Продолжить: python3 bot.py --resume <папка>\n")
    try:
        bot.resume_hero()
        poll_forever(bot)
    except KeyboardInterrupt:
        bot.save()
        print(f"\nОстановлено. Продолжить: caffeinate -i python3 bot.py --resume {folder}")


if __name__ == "__main__":
    main()
