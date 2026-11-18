---
name: "higgsfield-seedance"
description: "Run a Seedance 2.5 video generation on Higgsfield in Teofunk's Chrome: upload reference paintings, write a safe prompt, launch paid 480p, download the result into the workspace."
---

# Seedance 2.5 на Higgsfield

Браузер: Claude in Chrome (скилл chrome-browser). Страница `https://higgsfield.ai/ai/video?model=seedance_2_5`, пользователь уже залогинен. Цена: 60 кредитов за 20 с, 16:9, 480p. Запускать только после его явного «да» на конкретную генерацию.

## Шаги в панели
1. **Очистить референсы**: JS-клик по каждой `button[aria-label="Remove media"]` с паузой 300 мс, проверить, что осталось 0.
2. **Открыть выбор**: координаты кнопки «Add references» найти через JS (getBoundingClientRect), кликнуть по центру. Затем `find` «hidden file input in references picker».
3. **Загрузка**: `file_upload`, по одному файлу за вызов, пауза 4 с, не больше 3.8 МБ. Работают только пути из /mnt/user-data/uploads (оригиналы картин); файлы из outputs отклоняются.
4. **Порядок**: новые загрузки встают в начало сетки в обратном порядке. Загружай в обратном порядке, тогда плитки (567,247), (712,247), (857,247), (425,391), (567,391) идут как нужно; кликай слева направо. Главный герой первым референсом.
5. **Проверить** зумом области [345,170,935,465], закрыть выбор по (906,109).
6. **Промпт**: JS focus на `[contenteditable="true"]`, cmd+a, Backspace, затем `type` одной строкой.
7. **Проверка через JS** перед кликом: длина текста, число референсов, Duration 20s, 16:9, 480p, переключатель Unlimited aria-checked=false, текст кнопки ровно `/^Generate\s*60$/`. Кликать Generate только при совпадении всего.
8. Сразу записать в журнал: время, референсы, промпт, +60 кредитов.

Параллельные платные генерации работают (проверено 4), каждая около 5 мин. Кнопки отмены нет. Unlimited идёт по одной, и ошибочный запуск не отменить: однажды ушла генерация со старым промптом.

## Забрать результат
1. На странице найти миниатюры `hf_YYYYMMDD_HHMMSS_<uuid>_thumbnail.webp`; видео: `https://d8j0ntlcm91z4.cloudfront.net/user_3M6mSGoPst0IOhPpUUaDpOLd7Sz/hf_<...>.mp4`.
2. На вкладке cloudfront вставить через JS ссылку `<a download>` с этим адресом и кликнуть её настоящим кликом. Chrome сохраняет без вопроса в ~/Downloads.
3. `device_stage_files` переносит файл в /mnt/user-data/uploads/Downloads/. Сам контейнер cloudfront не скачивает (прокси 403).

## Шаблон промпта (рабочий)
Начало: «A 20-second sequence that brings the provided paintings to life. The paintings are the binding source for composition, characters, colors, brushwork and texture: weathered fresco on plaster and handmade paper, scratched botanical etchings, mineral pigments of rust red, faded turquoise, grey blue, bone white and pale ochre. Every frame must look like these paintings; never photorealism, never 3D. Keep each painting's own composition and framing as a 16:9 crop, and bring everything in it to life from the very first frame: strong living wind in every shot ...; never a still frame. Each shot stays inside one painting and shots are linked by soft painterly dissolves; never paste a figure from one painting into another. No new people, no new animals or birds beyond those already painted. No text, no speech, no music.»
Дальше по планам с таймкодами: «Shot 1 (0-7s): the painting of ...: what moves». В конце: «Sound only: ...».
Полезные фразы: «blinks with both eyes together, never one eye», «stays the painted fox with the same face», «framed as in its painting, no extreme close-up».
Запрещено: любые слова про дополнительные фигуры («a few tiny bison», «a flock»), слияние двух картин в одно пространство, просьбы о полёте птиц.
Примеры: animation-toolkit/seedance/prompts_v5.json, prompts_v6_E10.json.

## Известные слабости Seedance и что делать
| Проблема | Что делать |
| --- | --- |
| Первые 3-5 с статичны | Процедурный ветер на начало (скилл painting-film) |
| Выдумывает птиц и бизонов | Вырезать или стереть процедурно; убрать провоцирующие слова |
| Коллаж из двух картин | Одна картина на план |
| Крупный план уходит в фотореализм | «no extreme close-up», герой первым референсом |
| Моргание одним глазом | Вырезать участок |
| Петля текстуры по 1 с, шаг по 4 кадра | Промежуточные кадры по оптическому потоку |
| Полёт птиц ненатурален | Сцену вырезать |
Лиса и дятел (одна картина на план, ветер, дыхание) получились отлично с первого раза.