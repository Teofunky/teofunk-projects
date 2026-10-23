# Контракт «Ежедневного стандарта» — что новая версия обязана сохранить

Версия документа: 0.5.0 · 22.10.2026 (раздел 10 — что добавила 0.5.0).
Основание: исходный код `kozlov-jazzeta-0.3.5.zip` (SHA-256 `e3691ae0…f8f72`), прочитан полностью; мастер-ТЗ; `03_CHANGE_OF_DIRECTION_AND_CONSTRAINTS.md`.
Метки: **[код 0.3.5]** — взято из исходников; **[сайт]** — наблюдение на kozlovclub.ru в режиме только чтения; **[решение 0.4]** — решение новой версии.

## 1. Главное решение по данным

**[решение 0.4]** Новая версия работает с **теми же таблицами, колонками, опциями, ролями и правами**, что и 0.3.5. Схема БД не меняется, данные не перекладываются, номера и адреса не пересчитываются.

Следствия:
- «Миграция» 0.3.x → 0.4.0 — это замена кода. Активация вызывает тот же идемпотентный `dbDelta` (те же `CREATE TABLE`) и ничего не удаляет и не переименовывает.
- Перед заменой кода и после неё запускается **проверка целостности (dry-run)**: только чтение, отчёт о количестве выпусков, статей, опубликованных снимков, номерах, адресах, ошибках JSON. Ничего не пишет.
- **Откат:** деактивировать 0.4.0 → загрузить ZIP 0.3.5 → активировать. 0.3.5 читает те же данные. Всё, что создала 0.4.0, совместимо с форматом 0.3.5 (новые ключи в `es_appearance` 0.3.5 игнорирует).

## 2. Таблицы (префикс `$wpdb->prefix . 'es_'`, InnoDB) — [код 0.3.5]

| Таблица | Колонки | Ключи |
|---|---|---|
| `es_issues` | id, edition_date DATE, number NULL, state ('draft' \| 'published' \| 'withdrawn'), version, public_version, draft_json, public_json NULL, import_hash CHAR(64), human_touched, created_by, created_at, updated_at, published_at NULL, public_updated_at NULL, approved_by NULL | PK id; UNIQUE edition_date; UNIQUE number; KEY public_archive(state, edition_date, id) |
| `es_articles` | id, issue_id, external_id, slug, position, active, story_group, public_story_group, draft_json, public_json NULL, reviewed_hash, reviewed_by NULL, reviewed_at NULL, review_note, new_fact | PK id; UNIQUE (issue_id, external_id); UNIQUE slug; KEY (issue_id, active, position); KEY (public_story_group, issue_id) |
| `es_revisions` | id, issue_id, version, actor_id, action, created_at, snapshot `{document, reviews[]}` | KEY (issue_id, id) |
| `es_state` | name, value | PK name. Строки: `number` (счётчик номеров), `catalog` (версия кэша архива), `write` (строка блокировки записи), `rate_{user_id}` (лимит импорта) |
| `es_events` | event_id, title, metadata, created_at | PK event_id |
| `es_publications` | id, url_hash, canonical_url, publisher, title, external_key, content_hash, metadata, created_at | UNIQUE url_hash |
| `es_article_sources` | article_id, source_id, publication_id, external_key, content_hash, layer ('draft' \| 'public') | PK (article_id, source_id, layer) |
| `es_identities` | article_id, layer, event_id, text_hash, title_hash | PK (article_id, layer) |
| `es_relations` | article_id, related_article_id, layer, relation_type, reason, method, confidence, reviewed_by | PK (article_id, related_article_id, layer) |
| `es_imports` | id, issue_id, actor_id, request_key NULL, payload_hash | UNIQUE request_key; UNIQUE payload_hash |

Форматы внутри колонок:
- `es_issues.draft_json` — документ JSON 1.1 **без** `articles` (статьи лежат в `es_articles.draft_json`).
- `es_issues.public_json` — `{edition_date, number, articles[], social[]}`; статьи в публичной проекции (п. 6).
- `es_articles.slug` = `{edition_date}-{external_id}`, присваивается при первом появлении статьи и **никогда не меняется**.
- `es_articles.public_json` — публичная проекция статьи + `slug`.
- `reviewed_hash` = SHA-256 от `wp_json_encode(article, UNESCAPED_UNICODE|UNESCAPED_SLASHES)`; проверка действительна только для этой версии текста.
- Действия журнала (`es_revisions.action`): `import`, `create`, `edit_article`, `review_article`, `remove_article`, `edit_issue`, `restore_draft`, `publish`, `withdraw`, `before_manual_import`, `manual_import`.

## 3. Опции, роли, права — [код 0.3.5]

- `es_runtime` = `{mode: closed|public|paused, changed_by, changed_at}`; `es_runtime_fault` — признак аварийной остановки.
- `es_security` = `{publisher_id}` — единственный владелец, который публикует.
- `es_appearance` — название, подзаголовок, девиз, логотип, ссылки клуба, цвета, шрифт. **[решение 0.4]** добавляются ключи `typeface` и `design_version`; старые ключи сохраняются.
- `es_db_version` — версия кода, под которую активированы таблицы.
- Транзиенты: `es_archive_{catalog}_{page}` (5 минут).
- Роли: `es_importer` (read, es_import), `es_editor` (read, es_edit). Администратору добавляются es_edit, es_import, es_publish.
- Публиковать, снимать с публикации, открывать газету посетителям может только `Runtime::owner()`: es_edit + es_publish + ID = `publisher_id`. Публикация — только POST из админки: cookie-сессия, nonce, галочка подтверждения, совпадение версии; не REST и не cron.

## 4. Публичные адреса — [код 0.3.5], менять нельзя

| Адрес | Что |
|---|---|
| `/gazeta/` | последний опубликованный выпуск |
| `/gazeta/YYYY-MM-DD/` | выпуск по дате |
| `/gazeta/issue/YYYY-MM-DD/` | старый адрес → 301 на новый (только опубликованный) |
| `/gazeta/material/{slug}/` | статья, slug = `[a-z0-9_-]{1,160}` |
| `/gazeta/archive/`, `/gazeta/archive/page/N/` | подшивка, 12 выпусков на странице |
| `/gazeta/preview/{issue_id}/?_esnonce=…[&article=slug]` | предпросмотр черновика (es_edit + nonce, привязанный к версии) |
| `/gazeta/sitemap.xml`, `/gazeta/sitemap-(issues\|articles)-N.xml` | карты сайта (только public и blog_public) |
| `/gazeta/search/?q=…`, `/gazeta/search/page/N/?q=…` | **0.5.1, новый адрес:** поиск по опубликованным материалам; `noindex, follow`, в картах сайта нет. **0.5.5:** параметр `tema=<ключ рубрики или темы>` (например `?tema=releases`), можно вместе с `q`; неизвестный ключ игнорируется |
| `/gazeta/crossword/`, `/gazeta/crossword/YYYY-MM-DD/` | **0.5.2, новые адреса:** все кроссворды; кроссворд номера (только опубликованного) |

Query-переменные `es_view`, `es_key`, `es_page`; без ЧПУ — `/?es_view=…`. Строка `Sitemap:` добавляется в robots.txt только в публичном режиме. Шорткод `[kozlov_jazzeta]` выводит ссылку на газету.

## 5. REST — [код 0.3.5]

- `POST /wp-json/ezhednevny-standard/v1/import` — право es_import; `Content-Type: application/json` (иначе 415); ≤ 256 КиБ (413); глубина ≤ 32; ≤ 10 запросов в минуту на пользователя (429); `If-Match: "N"` — числовая версия (иначе 400). Ответ `{id, version, status:"draft", unchanged}` + `ETag`, 201 или 200.
  Повтор того же содержимого — 200 unchanged; тот же `import_id` с другим содержимым — 409; обновление без `If-Match` — 428; чужая версия — 409; черновик, который правил человек, или выпуск, который публиковался, — 409.
- `GET /wp-json/ezhednevny-standard/v1/latest` — публичный снимок последнего выпуска; виден только тем, кто может читать газету в текущем режиме.
- Публикации через REST нет и не будет. Режим `paused` закрывает namespace (404). Ответы namespace: `private, no-store`, `Vary: Cookie`, `X-Robots-Tag: noindex`.

## 6. Проверки и публичная проекция — [код 0.3.5]

- Валидатор: строгая схема `schemas/issue.schema.json` (лишние поля запрещены) + нормализация (п. 1.1 → event_id обязателен; ID только `[a-z0-9_-]`; HTTPS-ссылки без логина и не на локальные адреса; разрешённый HTML абзацев: p, br, strong, em, b, i, blockquote, ul, ol, li, a[href,title]).
- Блокеры статьи: example.com в источниках; факт `unverified`; нет даты проверки источника; `verified_primary` без официального/организатора/дистрибьютора; `verified_independent` без двух независимых цепочек; `attributed_single` без заметки; цитата без оригинала и перевода; фото без прав/подписи или не из медиатеки сайта.
- Блокеры выпуска: 4–7 материалов; дата не в будущем по Москве; `ready_for_human_review` и нет блокеров; ровно один `lead`; каждая статья подтверждена человеком именно в текущей версии; повтор сюжета за 14 дней без нового факта; дубли внутри номера и по архиву (точные).
- Публичная проекция статьи: id, region, category, placement, headline, lead, body_paragraphs, summary, event_date, conflict_disclosure, media_rights, previous_article_ids, development_note, sources (title, publisher, url, published_at, type), verification (список уровней). Внутренние заметки, claims и проверки читателю не уходят.
- Номер выдаётся при первой публикации из счётчика `es_state.number` в той же транзакции; повторное утверждение номер сохраняет. Снятие (`withdrawn`) скрывает выпуск, снимок не удаляется.

## 7. Безопасность и кэш — [код 0.3.5], сохраняется

- `DISABLE-GAZETA` в папке плагина или `KOZLOV_GAZETA_DISABLED` — проверка до загрузки модулей; режим переводится в `paused`.
- Ошибка модуля переводит в `paused` и пишет `es_runtime_fault`; возврат — только в закрытый режим, вручную.
- Закрытые и черновые просмотры: `Cache-Control: private, no-store`, `X-Robots-Tag: noindex`, `Vary: Cookie`, `Referrer-Policy: no-referrer`, CSP без внешних скриптов (в теме клуба жёстко прописаны Метрика с вебвизором, Top.Mail.Ru и VK — **[сайт]**). Для всех запросов `/gazeta/*` и namespace ставится `DONOTCACHEPAGE`.
- Деактивация возвращает режим `closed`; удаление плагина ничего не удаляет.

## 8. Что меняется в 0.4.0 — [решение 0.4]

1. Код написан заново: модули по ответственности, читаемый стиль, совместимость с PHP 7.4 и MySQL 5.7 (проверяется `tools/php74-check.php`).
2. **Газета — самостоятельная страница.** По решению владельца (21.10.2026) шапка, меню, подвал, стили, скрипты и чат-виджет темы клуба на `/gazeta/*` не выводятся. Плагин сам выдаёт документ `<html lang="ru">`; `wp_head()` и `wp_footer()` сохранены, поэтому работают админ-панель WordPress, подтверждения Яндекса/Google от Yoast и ресурсы других плагинов. Выход на сайт клуба — ссылка «← Клуб Алексея Козлова · главная страница сайта» вверху и «← На главную сайта клуба» в выходных данных; адрес — `es_appearance.club_home_url`.
3. SEO без вырезания HTML регулярными выражениями: собственные title, description, canonical, robots, Open Graph и JSON-LD; у Yoast остаются только верификация и граф Organization/WebSite (фильтры `wpseo_frontend_presenters`, `wpseo_schema_graph`); `rel_canonical`, `wp_robots` и shortlink ядра на страницах газеты отключены.
4. Метрика: раньше счётчик приходил из темы. Так как тема на страницах газеты больше не выводится, плагин сам подключает Яндекс.Метрику (по умолчанию № 44419291, вебвизор) **только в открытом режиме**; номер меняется или отключается в «Оформлении». Top.Mail.Ru и VK-пиксель темы на страницы газеты не попадают — их нужно добавить отдельно, если они нужны.
5. Новый дизайн читательской газеты в утверждённом стиле и новый редакционный пульт. Адреса, данные, права и порядок публикации — прежние.
6. Исправлена ошибка, унаследованная от 0.3.5: разбиение многострочных полей (блокеры, заметки) регулярным выражением без флага `u` разрезало букву «х» (байт 0x85) и ломало сохранение. Теперь `/\R/u`.

## 9. Риски и чего не хватает

| Что | Почему важно | Что нужно |
|---|---|---|
| Реальный стек WP 6.7.2 / PHP 7.4.33 / MySQL 5.7.21 недоступен в среде разработки (нет сети к wordpress.org, packagist, docker) | слой данных проверен на эмуляции MySQL поверх SQLite и на совместимость с кодом 0.3.5, но блокировки, строгий режим MySQL, Yoast, W3TC и WPGlobus так не проверить | staging на Beget или разрешение домена в настройках, чтобы поднять стек |
| Содержимое боевых `es_*` таблиц | проверка миграции на настоящих данных | дамп только таблиц `wp_es_*` со staging/prod (персональных данных там нет, кроме ID пользователей) |
| Правила W3 Total Cache | кэш до WordPress может отдать закрытую страницу | скрин настроек Page Cache или исключения `/gazeta/*` |
| Защита Beget от ботов **[сайт]** | запросы без cookie `beget=begetok` получают JS-заглушку (200, 274 байта). Это касается сборщика, который шлёт REST-запросы | проверить с сервера сборщика; не обходить, а согласовать доступ |
| Причина отключения 0.3.4 | **[сайт]** к 22:15 МСК 21.10 плагин 0.3.4 снова активен, режим «Закрытый тест», выпуск № 1 опубликован | сообщение администратора или журнал, если причина важна |

## 10. Что добавляет 0.5.0 — [решение 0.5]

1. **Одна аддитивная таблица** `es_meta`: `object_type` ('issue' | 'article'), `object_id` (id строки `es_issues` / `es_articles`), `layer` ('draft' | 'public'), `meta_key`, `meta_value` (JSON), `updated_at`, `updated_by`; PK (object_type, object_id, layer, meta_key). Ключи: у выпуска `cover` {kind: photo | plate | none, attachment_id, alt, credit, plate}; у статьи `brief` {why, context, next} — только непустые, до 600 знаков, обычный текст; `facts_visible` (1); в публичном слое ещё `facts` {confirmed[], single[]} — строится при публикации из claims со статусами verified_primary / verified_independent и attributed_single.
2. Прежние десять таблиц, их колонки, ключи и форматы `draft_json` / `public_json` **не меняются**. JSON 1.1 и REST-импорт не меняются. `reviewed_hash` считается как раньше; правка блоков снимает отметку проверки тем же путём, что и правка текста.
3. Публикация копирует черновой слой `es_meta` выпуска и активных статей в публичный в той же транзакции, что и публичные снимки. Снимок версии (`es_revisions.snapshot`) получил ключ `meta`; старые версии его игнорируют, «Вернуть версию» в 0.5.0 восстанавливает и его.
4. Новые ключи в `es_appearance`: `design` (gazeta | modern | brief — вид для новых читателей) и `photo_mono` (0 | 1). 0.3.x и 0.4.0 их не читают.
5. Новое значение `es_db_version` = 0.5.0; без «Завершить обновление» (или повторной активации) газета не считается готовой — как при переходе на 0.4.0.
6. Откат на 0.4.0 / 0.3.x: таблица `es_meta` остаётся и не мешает; читатели на старой версии не видят обложку и блоки. При возврате на 0.5.0 данные на месте (проверено, см. `TEST-REPORT.md`).

## 11. Что добавляет 0.5.1 — [решение 0.5.1]

1. **Поиск.** Новые правила адресов `^gazeta/search/?$` и `^gazeta/search/page/([1-9][0-9]*)/?$` (вид `search`); правила 0.3.5 не меняются (проверяется тестом символ в символ). Пока правила не пересохранены после обновления, ссылка «Поиск» ведёт на `/?es_view=search` — этот адрес работает всегда.
2. **Таблица `es_search`** — производные данные, не источник правды: `article_id` (PK), `issue_id`, `edition_date`, `title`, `body` (нормализованный текст), `indexed_at`. Её можно очистить и пересобрать в любой момент. Каждый запрос поиска соединяется с `es_issues.state='published'` и `es_articles.public_json IS NOT NULL`, поэтому снятое не показывается даже при отставшем указателе.
3. **Строка `search` в `es_state`** хранит значение счётчика `catalog`, которому соответствует указатель. Все версии с 0.3.0 увеличивают `catalog` при публикации и снятии; 0.5.1 обновляет указатель в той же транзакции. Если версии разошлись (откат и возврат), указатель пересобирается при «Завершить обновление», при открытии «Пульта» и через WP-Cron (`es_search_rebuild`).
4. **Кадр фото.** В `es_meta` новый ключ статьи `focus` {x, y} (0–100 %, центр не хранится) и поле `focus` в обложке-фото. Копируется в публичный слой при публикации, входит в снимок версии. Смена кадра — отдельное действие (`edit_focus` в журнале): меняет рабочую версию выпуска, но не снимает проверку текста.
5. Новое значение `es_db_version` = 0.5.1. Откат на 0.5.0 / 0.4.0 / 0.3.x: `es_search` и ключ `focus` старые версии не читают и не трогают (0.5.0 при повторном одобрении копирует `focus` вместе с черновым слоем); при возврате указатель пересобирается (проверено, см. `TEST-REPORT.md`).

## 12. Что добавляет 0.5.2 — [решение 0.5.2]

1. **Адреса:** `^gazeta/crossword/?$` (вид `crosswords`) и `^gazeta/crossword/([0-9]{4}-[0-9]{2}-[0-9]{2})/?$` (вид `crossword`); правила 0.3.5 не меняются. До пересохранения правил ссылки ведут на `/?es_view=crossword…`. Предпросмотр — тот же подписанный адрес выпуска с `&crossword=1` (и `&answers=1` для ответов).
2. **Данные:** ключ `crossword` выпуска в `es_meta` (черновой и публичный слои). Значение — JSON: `v`, `id` (постоянный), `title`, `intro`, `author`, `words[{answer, letters, enum, clue, source, note}]`, `seed`, `rows`, `cols`, `placements[{w, r, c, d: a|d, n}]`, `unplaced[]`, `key`, `warnings[]`, `built_at`. При публикации `note` и `warnings` убираются. Каждое чтение заново проверяет сетку (`Crossword::clean`).
3. **Входной формат** `es-crossword-1.0` (`schemas/crossword.schema.json`, `docs/CROSSWORD.md`). Сетку строит `Domain\CrosswordBuilder` детерминированно по `seed`.
4. **Журнал:** действие `edit_crossword`; снимок версии получил ключ `meta.crossword`. Снимки до 0.5.2 его не содержат, и при восстановлении такого снимка текущий кроссворд не трогается.
5. **Ответы** в странице до срока закодированы сдвигом по алфавиту (не защита: приза нет). Открываются через 7 дней (Europe/Moscow) или с выходом более нового кроссворда.
6. **`es_db_version` = 0.5.2.** Откат на 0.5.1 / 0.5.0 / 0.4.0 / 0.3.x и возврат проверены (см. `TEST-REPORT.md`).

## 13. Что добавляет 0.5.5 — [решение 0.5.5]

1. **Темы не входят в JSON 1.1.** Формат обмена, валидатор и схема не меняются (`additionalProperties: false` остаётся). Темы вычисляет `Domain\Topics` из заголовка, лида и текста. Список тем и правила — в коде, ключи: `releases`, `concerts`, `festivals`, `awards`, `interviews`, `anniversaries`, `memorial`, `screen_books`. Ключи тем не совпадают с ключами рубрик, кроме `festivals`: это рубрика и тема, читатель видит одну.
2. **Ручной выбор** — `wp_es_meta`, `object_type='article'`, `meta_key='tags'`, значение `{"mode":"manual","tags":[ключи]}`, не больше двух ключей, пустой список значит «без тем». Нет строки — темы автоматические. Слои и публикация — как у блоков 0.5.0. Ключ входит в снимок версии (`meta.tags`). Снимки до 0.5.5 его не содержат, и восстановление такого снимка текущий выбор не трогает.
3. **`es_search.topics`** — `varchar(255) NOT NULL DEFAULT ''`, ключи рубрики и тем через пробел с пробелами по краям (` jazz releases `). Производные данные, как вся таблица. `Installer::install()` с 0.5.5 всегда пересобирает указатель: темы считаются при индексации, а строки, записанные старой версией, их не содержат.
4. **Адрес** `?tema=` (не `tag`: это зарезервированная переменная WordPress). Значение проходит `sanitize_key` и белый список (`Topics::isTopic`), в SQL — только через `prepare` и `esc_like`.
5. **Разметка номера:** `data-es-topics` у материала, `data-es-topic` у чипа, `data-es-filter-empty` и `data-es-filter-status`. Скрытый материал — `data-es-hidden` (как в 0.5.0), правило скрытия с `!important`.
6. Новое значение `es_db_version` = 0.5.5. Откат на 0.5.4 и раньше: столбец `topics` и ключ `tags` старые версии не читают; их вставки в `es_search` перечисляют свои столбцы и проходят благодаря значению по умолчанию (проверено для 0.5.4, см. `TEST-REPORT.md`).

## 14. Что меняет 0.5.6 — [решение 0.5.6]

1. **Только вывод.** Данные, JSON 1.1, таблицы, ключи `wp_es_meta`, адреса и REST не меняются. Значки достоверности (`verification`) и дата события по-прежнему хранятся и видны в редакции. Читателю они не показываются.
2. **Источники фото** — `Domain\Credit::isImageSource()`: хосты `commons.wikimedia.org`, `upload.wikimedia.org`, `creativecommons.org`, `flickr.com`, `unsplash.com`, `pexels.com`, `pixabay.com` и страницы файлов `*.wikipedia.org/wiki/File:`. Они не входят в «Источники», в `citation` JSON-LD и в поисковый указатель. Они дают ссылки в подписи к фото.
3. **Подпись к фото** строится из `media_rights.credit`:
   - предложения со служебными пометками (`/кадрирован|в ссылках к материал|источник и лицензи/i`) отбрасываются;
   - лицензия CC узнаётся по написанию «CC BY 2.0», «CC BY-SA 4.0», «CC0 1.0». Ссылка ведёт на источник с `creativecommons.org`, если он есть, иначе на канонический адрес;
   - HTML собирается из экранированных частей (`Presenter::creditHtml`).
4. **Подсказки по стилю** — `Domain\StyleHints::check()`. Это чистая функция; результат только показывается редактору и нигде не хранится.

