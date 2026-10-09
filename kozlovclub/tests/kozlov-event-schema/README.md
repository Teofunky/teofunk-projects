# Тесты Kozlov Event Schema 1.1.2

Окружение, на котором прогонялись тесты: WordPress 6.7.2, MariaDB 10.11, PHP 8.3, WP-CLI 2.11, тема Twenty Sixteen, часовой пояс сайта Europe/Moscow, ЧПУ `/%postname%/`.

## Подготовка

1. Поставить WordPress 6.7.2, включить `WP_DEBUG` и `WP_DEBUG_LOG`.
2. Скопировать `mu-plugins/fake-ai1ec.php` в `wp-content/mu-plugins/` (регистрирует тип записи `ai1ec_event` со slug `event`, как в All-in-One Event Calendar).
3. Создать таблицу календаря по схеме из исходников All-in-One Event Calendar (`app/controller/front.php`, метод `get_current_db_schema`):

```sql
CREATE TABLE wp_ai1ec_events (
 post_id bigint(20) NOT NULL, start int(10) UNSIGNED NOT NULL, end int(10) UNSIGNED, timezone_name varchar(50),
 allday tinyint(1) NOT NULL, instant_event tinyint(1) NOT NULL DEFAULT 0, recurrence_rules longtext, exception_rules longtext,
 recurrence_dates longtext, exception_dates longtext, venue varchar(255), country varchar(255), address varchar(255),
 city varchar(255), province varchar(255), postal_code varchar(32), show_map tinyint(1), contact_name varchar(255),
 contact_phone varchar(32), contact_email varchar(128), contact_url varchar(255), cost varchar(255), ticket_url varchar(255),
 ical_feed_url varchar(255), ical_source_url varchar(255), ical_organizer varchar(255), ical_contact varchar(255),
 ical_uid varchar(255), show_coordinates tinyint(1), latitude decimal(20,15), longitude decimal(20,15),
 force_regenerate tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (post_id), KEY feed_source (ical_feed_url)) CHARACTER SET utf8;
```

4. Импортировать две картинки и при необходимости поправить их ID в `seed.php` (поле `img`: 4 = 800×600 с кириллическим именем файла, 5 = 400×300):
   `wp media import "Саша-Алмазова-октябрь.jpg"` и `wp media import small.jpg`.
5. Установить плагин из zip: `wp plugin install kozlov-event-schema-1.1.2.zip --activate`.

## Запуск

```bash
wp eval-file tests/seed.php                         # 31 тестовый анонс, пишет tests/ids.json
wp server --host=127.0.0.1 --port=8089 &            # адрес зашит в check.py
python3 tests/check.py off                          # вывод выключен: ни одного блока
wp option update kozlov_event_schema_settings '{"enabled":1}' --format=json
YOAST=0 python3 tests/check.py on                   # 31 страница, все ожидания
# с «Yoast»: mu-плагин с define('WPSEO_VERSION','21.0'); затем
YOAST=1 python3 tests/check.py on
wp eval-file tests/status.php                       # блок статуса: nonce, права, значения, прежняя дата
wp eval-file tests/admin.php                        # страница проверки: пишет tests/admin.html
wp eval-file tests/bulk.php && wp eval-file tests/admin_perf.php   # 320 событий, время и число запросов
```

Ожидаемый результат: `FAILS: 0` во всех прогонах `check.py`, `status tests fails: 0`, пустой `debug.log` (кроме предупреждений об обновлениях с wordpress.org).

## Что проверяет check.py

Для каждой страницы: статус ответа, ровно один блок, отсутствие сырых тегов внутри JSON, тип (MusicEvent / EducationEvent), `@type` площадки `[Place, MusicVenue]` и её `@id`, отсутствие `availability`, организатор (с `@id #organization` только при Yoast), адрес. Плюс точечные ожидания по каждому случаю: время +03:00 при поясе UTC, Europe/Moscow и пустом; депозит, вход свободный, «от N», «N руб.», неоднозначная цена; без сцены, две сцены, Мясницкая; маленькая картинка, кириллица в имени файла; экранирование `</script>`; весь день; без окончания; окончание раньше начала; метки WPGlobus `[:ru]` и `{:ru}`; жанры одной строкой и несколькими; лекция; отменён, отложен, перенесён (с прежней датой и без); отложенный и отменённый после исходной даты (выводятся), перенесённый и прошедший (не выводится), пароль, черновик, прошедший, повторяющийся, без строки календаря (не выводятся).
