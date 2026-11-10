# teofunk-projects

Все проекты Teofunk в одном приватном репозитории: исходники, история версий, правила работы.

## Карта

| Папка | Что внутри | Статус |
|---|---|---|
| [`kozlovclub/plugins/`](kozlovclub/plugins) | плагины WordPress обоих сайтов клуба: афиша, поиск, календарь, «Живой фасад», страницы концертов и артистов, разметка, Kozlov TV, StreamVi и другие | перенесено с историей версий |
| [`kozlovclub/themes/`](kozlovclub/themes) | тема Kozlov Club 2026 (новый сайт), снимок темы текущего сайта и правки к ней | перенесено |
| [`kozlovclub/tools/`](kozlovclub/tools), [`kozlovclub/tests/`](kozlovclub/tests) | диагностика, служебные скрипты, тестовые стенды плагинов | перенесено |
| [`jazzartclub/themes/jazzart`](jazzartclub/themes/jazzart) | тема нового сайта jazzartclub.ru | перенесено с историей версий |
| `kozlovclub/apps/`, `kozlovclub/prototypes/` | барометр, конкурентный радар, Козловск, прототипы страниц | этап 2 |
| `apps/` | приложения: Konnakol Master, Reel Practice, PULSE и другие | этап 2 |
| `sites/` | другие сайты: implantarm, HATIK, Вартанян, Kontiky Film Lab, Mousework | этап 2 |

Подробная таблица плагинов с версиями для каждого сайта: [`kozlovclub/README.md`](kozlovclub/README.md).

«Ежедневная Джазета» хранится в отдельном репозитории [Teofunky/jazzeta-pipeline](https://github.com/Teofunky/jazzeta-pipeline): у неё свои автоматические проверки и выпуски.

## Что сюда не кладём

- пароли, ключи API, `wp-config.php`, `.env`;
- дампы базы (`*.sql`) и выгрузки из них: в них данные гостей, артистов и сотрудников;
- фото, видео и медиатеку сайта;
- собранные ZIP: их собирают из исходников.

Перед каждым коммитом запускается проверка `scripts/check-secrets.sh`. Чтобы она срабатывала сама, один раз после клонирования выполните:

```bash
git config core.hooksPath .githooks
```

Проверить весь репозиторий: `scripts/check-secrets.sh --all`.

## Версии

Старые версии восстановлены из ZIP-архивов коммитами по порядку, поэтому по истории видно, что менялось от версии к версии. Список всех версий со ссылками на коммиты: [`VERSIONS.md`](VERSIONS.md). Новые версии отмечаются тегом `<папка>/v<версия>`, например `kozlov-calendar/v1.0.1`.

## Как работать

- В чате Claude: попросить «возьми плагин X из teofunk-projects», и Claude клонирует репозиторий в свою сессию.
- В Claude Code (вкладка Code в приложении для Mac): открыть папку репозитория, правила из `CLAUDE.md` подхватываются сами.
