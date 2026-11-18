# animation-toolkit (проект «Трава помнит», ноябрь 2026)

Место: `teofunk-projects/apps/animation-toolkit`. В новой сессии: клонировать teofunk-projects и брать скрипты отсюда.

Рабочие скрипты, которыми собран фильм из живописи Teofunk. Это эталонные реализации, а не библиотека:
пути к картинам и клипам внутри захардкожены (`/mnt/user-data/uploads/<id>-image.png`, `clips/E0x.mp4`),
перед повтором их нужно поменять на свои. Всё на Python 3 + OpenCV 5 (contrib: ximgproc, dnn_superres, xphoto) + ffmpeg.

| Папка | Что внутри |
| --- | --- |
| seedance/ | Промпты Seedance 2.5 по эпизодам (v5 = финальный шаблон «оживи картины, ничего не добавляй»), E10 лиса/дятел |
| procedural/ | Бесплатная анимация картин: ветер (wind_open*), течение реки по flow-map (fix_v3_river), удаление выдуманного объекта (fix_v3_crow), Ken Burns для повторов (fix_v2_e09), река каменеет в асфальт (valley_concrete), дятел клюёт в такт звуку (woodpecker), рыба-вырезка плывёт (fish_cutout, fix_e04b) |
| procedural/NEGATIVE_* | Как НЕ надо: птицы-вырезки «летают» масштабом, пользователь назвал ужасным |
| edit/ | Монтаж: монтажный лист по кадрам с переходами и точной подгонкой под длину трека (compose.py + edit_c.py), сборка версий под разные треки (build_versions.py + versions.py), проверка на застывшие кадры и громкость (chk_version.py) |
| lyrics/ | Снять текст песни с таймкодами: spleeter (вокал) + whisper medium.en через sherpa-onnx |
| hd/ | 1080p: ESPCN x2 + Lanczos + лёгкая резкость + зерно (hd.py); конвертер Real-ESRGAN pth в onnx (build_srvgg.py, результат «пластмассит» живопись) |
| norstein/ | Перекладка по Норштейну на «Трёх индианках»: zoom (сетки для ручной обводки), cut (контуры + привязка к краю), build (подложки, отделение трав), render (ярусы, калька, двойки), layout (раскладка деталей на столе) |
| norstein/NEGATIVE_owl_rig_autocut.py | Сова: автоматическая нарезка прямыми линиями, пользователь: «очень плохо вырезал» |
| skills/ | Копии трёх скиллов Claude (painting-film, higgsfield-seedance, norstein-cutout), рабочие версии хранятся в аккаунте claude.ai |
| log/production_log.md | Полный журнал производства с решениями, id генераций и замечаниями |
