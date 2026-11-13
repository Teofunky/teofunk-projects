"""Проверка ключей перед интервью, без Telegram: python3 check_apis.py

1. Claude: тот же вызов, что делает бот (досье и план Илугдина, инструмент next_turn).
   Печатает первый вопрос. Стоит как один ход интервью.
2. OpenAI: macOS озвучивает короткую фразу командой say, бот расшифровывает её тем же кодом,
   что и голосовые героя.

Ключи берутся из окружения (source ~/.neurojournalist.env) и не печатаются.
"""
import argparse
import os
import subprocess
import sys
import tempfile
import urllib.error
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import bot as b  # noqa: E402  (подключает сертификаты macOS)
import interviewer as iv  # noqa: E402

PILOT = HERE.parent / "pilot" / "ilugdin"


def check_claude(dossier: Path, plan: Path) -> bool:
    print("1. Claude API: готовлю первый вопрос (10–60 секунд)...", flush=True)
    if not os.environ.get("ANTHROPIC_API_KEY"):
        print("   НЕТ: в окружении нет ANTHROPIC_API_KEY. Выполните source ~/.neurojournalist.env")
        return False
    try:
        import anthropic
        text = dossier.read_text(encoding="utf-8")
        system = iv.build_system(text, plan.read_text(encoding="utf-8") if plan.exists() else "")
        session = {"hero": iv.hero_name(text), "turns": []}
        decision, usage = iv.next_turn(anthropic.Anthropic(), iv.DEFAULT_MODEL, system, session)
    except Exception as e:
        print(f"   НЕТ: {e}")
        return False
    print(f"   ДА. Модель {iv.DEFAULT_MODEL}. Первый вопрос:\n   {decision.get('next_question', '').strip()}")
    print(f"   Токены: {usage}")
    return True


def check_openai() -> bool:
    print("2. OpenAI (голосовые): расшифровываю тестовую фразу...", flush=True)
    if not os.environ.get("OPENAI_API_KEY"):
        print("   НЕТ: в окружении нет OPENAI_API_KEY.")
        return False
    with tempfile.TemporaryDirectory() as d:
        wav = Path(d) / "check.wav"
        phrase = "Проверка связи. Раз, два, три"
        fmt = ["--data-format=LEI16@16000", "-o", str(wav)]
        try:
            ok = subprocess.run(["say", "-v", "Milena", *fmt, phrase], capture_output=True).returncode == 0
            if not ok:                         # нет русского голоса: подойдёт любой
                phrase = "Testing one two three"
                subprocess.run(["say", *fmt, phrase], check=True, capture_output=True)
        except Exception as e:
            print(f"   Не удалось записать тестовый звук командой say: {e}")
            return False
        try:
            text = b.transcribe_openai(wav.read_bytes(), "check.wav")
        except urllib.error.HTTPError as e:
            body = e.read().decode("utf-8", "replace")[:300]
            print(f"   НЕТ: OpenAI ответил {e.code}: {body}")
            return False
        except Exception as e:
            print(f"   НЕТ: {e}")
            return False
    print(f"   ДА. Сказано: «{phrase}». Расшифровано: «{text}»")
    return True


def main(argv=None):
    ap = argparse.ArgumentParser(description="Проверка ключей Claude и OpenAI")
    ap.add_argument("--dossier", type=Path, default=PILOT / "dossier.md")
    ap.add_argument("--plan", type=Path, default=PILOT / "plan.md")
    a = ap.parse_args(argv)
    c = check_claude(a.dossier, a.plan)
    print()
    o = check_openai()
    print()
    if c and o:
        print("Итог: оба ключа работают. Бот готов к интервью.")
    else:
        print("Итог: есть проблема, пришлите этот вывод (ключей в нём нет).")
    return 0 if c and o else 1


if __name__ == "__main__":
    sys.exit(main())
