#!/bin/zsh
# Двойной щелчок в Finder: запускает бота интервью с Дмитрием Илугдиным (продолжает сессию ilugdin-1).
# Сам останавливает другой запущенный экземпляр бота, обновляет код и запускает бота.
cd "$(dirname "$0")" || exit 1
SESSION="../pilot/ilugdin/sessions/ilugdin-1"

echo "== Нейрожурналист: бот интервью с Дмитрием Илугдиным =="
echo "Это окно бота. Не закрывайте его, пока идёт интервью. Можно свернуть (Cmd+M)."
echo

if pkill -f "bot.py --resume" 2>/dev/null; then
  echo "Остановил предыдущий экземпляр бота."
  sleep 3
fi

if [ ! -f ~/.neurojournalist.env ]; then
  echo "Нет файла с ключами ~/.neurojournalist.env"; read -r; exit 1
fi
source ~/.neurojournalist.env

if [ ! -d .venv ]; then
  python3 -m venv .venv && .venv/bin/pip install -q -r requirements.txt
fi
source .venv/bin/activate

git pull --ff-only -q && echo "Код обновлён." || echo "Обновить код не удалось, запускаю текущую версию."
echo

if [ ! -f "$SESSION/session.json" ]; then
  echo "Не найдена сессия $SESSION"; read -r; exit 1
fi
echo "Если первые секунды мелькает 409 (Conflict), это старый бот отпускает Telegram, подождите."
caffeinate -i python3 bot.py --resume "$SESSION"
echo
echo "Бот остановлен. Запустить снова: двойной щелчок по start-ilugdin.command"
read -r
