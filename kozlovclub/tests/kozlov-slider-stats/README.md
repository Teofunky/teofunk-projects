# Проверки Kozlov Slider Stats

- `wptest.php`, `httptest.sh`: локальный WordPress 7.1.5 на SQLite (пути `/home/claude/wp`, тестовые пользователи admin и editor1 создаются при установке тестового WordPress, к сайту клуба отношения не имеют).
- `t_desktop.py`, `t_mobile.py`, `t_next.py`: живая страница kozlovclub.ru в Chrome через Playwright. Конфиг и скрипт плагина подставляются в HTML локально, номер счётчика заменён на фиктивный, запросы к Метрике, mail.ru, vk.com и iframe Ticketscloud заблокированы. На сайт ничего не ставится, в Метрику ничего не уходит.
- `run_all.sh` запускает всё, `results-1.0.0.log` результат прогона версии 1.0.0.
