# teofunk-projects

Все проекты Teofunk в одном приватном репозитории: исходники, история версий, правила работы.

## Карта

| Папка | Что внутри |
|---|---|
| [`kozlovclub/plugins/`](kozlovclub/plugins) | плагины WordPress обоих сайтов клуба, у каждой версии свой коммит |
| [`kozlovclub/themes/`](kozlovclub/themes) | тема Kozlov Club 2026, снимок темы текущего сайта и правки к ней |
| [`kozlovclub/apps/`](kozlovclub/apps) | Концертный барометр (с историей коммитов) и его ранний прототип, конкурентный радар, Козловск, J and Joy PWA |
| [`kozlovclub/prototypes/`](kozlovclub/prototypes) | прототипы: концепции сайта и Джаз-навигатора, главная сцена, фасад, календарь, поиск, Новый год 2027, кроссворд, настольные игры, макеты нового сайта |
| [`kozlovclub/pages/`](kozlovclub/pages), [`kozlovclub/brand/`](kozlovclub/brand) | страница корпоративных мероприятий, логотипы и графика |
| [`kozlovclub/docs/`](kozlovclub/docs) | аудиты сайта, SEO и аналитики, брифы и ТЗ, материалы страницы БДО, проверка Codex |
| [`kozlovclub/jazzeta-archive/`](kozlovclub/jazzeta-archive) | архив Джазеты: ранние версии плагина 0.1.0–0.5.11, черновики выпусков, ТЗ, кроссворды, календарь памятных дат |
| [`kozlovclub/tools/`](kozlovclub/tools), [`kozlovclub/tests/`](kozlovclub/tests) | диагностика, служебные скрипты, тестовые стенды плагинов |
| [`jazzartclub/themes/jazzart`](jazzartclub/themes/jazzart) | тема нового сайта jazzartclub.ru |
| [`apps/`](apps) | Konnakol Master (с историей коммитов), Reel Practice, метроном PULSE, гитарные инструменты (Tone Lab, ЛЮФТ, Rig Lab, JSFX), SmartFind (умный поиск и уборка файлов на Mac) |
| [`sites/`](sites) | другие сайты: HATIK, AgroLook, Илья Вартанян, implantarm, Kontiky Film Lab, Mousework |
| [`texts/`](texts) | «НеСелинджер», «Кто написал Баха» |

Подробная таблица плагинов с версиями для каждого сайта: [`kozlovclub/README.md`](kozlovclub/README.md).

«Ежедневная Джазета» хранится в отдельном репозитории [Teofunky/jazzeta-pipeline](https://github.com/Teofunky/jazzeta-pipeline): у неё свои автоматические проверки и выпуски.

## Что сюда не кладём

- пароли, ключи API, `wp-config.php`, `.env`;
- дампы базы (`*.sql`) и выгрузки из них: в них данные гостей, артистов и сотрудников;
- видео, музыку и медиатеку сайта (картинки прототипов, бренда и сайтов лежат рядом с ними);
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
