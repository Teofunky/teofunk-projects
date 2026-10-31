# КЛУБ КОЗЛОВА — «БАЛ, КОТОРЫЙ НИКТО НЕ ВИДЕЛ»
## Производственный сценарий и промпты для Pika H3 Flex

**Формат:** 9:16, 75 секунд, 24 fps, вертикальный новогодний ролик к 2027 году.
**Стиль:** костюмированная кинокомедия в духе роскошного бала середины XIX века. Контраст классического придворного этикета и раскрепощённого современного танца. Тёплая золотисто-розовая цветопередача, свечи, блеск атласа, бархат, хрусталь, камера на уровне участников бала. Реальная архитектура Клуба Козлова должна оставаться узнаваемой.
**Сюжет:** в полночь пустой клуб превращается в магический новогодний бал. Из четырёх рам на стене выходят Луи Армстронг, Майлз Дэвис, Джон Колтрейн и Джо Завинул. Они коротко спорят о новой джазовой сцене, а затем сами превращают торжественный вальс в джаз-фанк. На рассвете все снова оказываются в портретах.

## Референс — что берём из загруженного Reel
- Мужские наряды: бордовые бархатные фраки, чёрные брюки, белые рубашки, бабочки, богато расшитые жилеты и платки. Отдельный выразительный образ — пудрово-розовый фрак с золотым орнаментом на тёмном жилете.
- Женские наряды: кринолины и корсажи, огромные шелковые юбки оттенков голубого, жемчужного, мятного, розового, причёски с локонами, серьги и бальные перчатки.
- Пространство: хрусталь, зелёный бархат, позолота, золотистые свечи и праздничный свет. В нашем фильме эти праздничные элементы лишь добавляются поверх **реальных** интерьеров клуба: не превращать московский клуб в чужой дворец.
- Хореография: комическое смещение от чинных проходок и поклона к современным энергичным танцевальным жестам, динамике и музыкальному драйву.
- В референсе **нет** выходящих из портретов призраков: этот эффект создаём специально для сценария.

## Постоянные персонажи — costume bible
1. **Louis Armstrong:** улыбающийся Луи Армстронг, узнаваемое лицо; винно-бордовый бархатный фрак с чёрными атласными лацканами; белая рубашка; чёрная бабочка; тонкая золотая цепочка часов; латунная труба. Самый жизнерадостный персонаж.
2. **Miles Davis:** узнаваемый Майлз Дэвис; строгий чёрный длиннополый фрак, глубокие бордовые атласные лацканы, тонкий белый жилет, белый шёлковый шейный платок; труба с сурдиной. Сдержанная ирония, минимальные движения.
3. **John Coltrane:** узнаваемый Джон Колтрейн; тёмно-синий фрак глубокого ночного оттенка, слоновая кость для жилета, кремовый галстук, лаковые туфли; тенор-саксофон. Вдумчивый, наблюдательный.
4. **Joe Zawinul:** узнаваемый Джо Завинул; театральный пудрово-розовый фрак, бордовый жилет с золотой вышивкой, тёмно-красный шейный платок; остаётся рядом с клавишными. Главный инициатор смены вальса на джаз-фанк.
5. **Бал:** 10–20 второстепенных гостей (не знаменитости), мужчины во фраках бордо, чёрного и кремового цвета, женщины в больших пастельных платьях на кринолинах, по рисунку тканей и фактуре как в референсе.

## Правила генерации
- Использовать H3 Flex → Reference to Video, 9:16, 768p для финальных клипов; 480p для тестов. Один клип — 5–15 секунд.
- В каждом запросе обозначать источники как «Image 1 = actual Kozlov Club interior», «Image 2 = character», «Video 1 = ONLY motion/camera/ballroom rhythm»; не копировать лица актёров из Instagram.
- Важно: в каждой сцене использовать одно и то же изображение интерьера и постоянные портреты персонажей. Смены локаций допускаются только при наличии новых фотографий клуба.
- Для сохранения лиц предпочтительнее средние планы с 1–2 главными персонажами; большой танец — отдельным общим планом.
- Внутри генерации НЕ пытаться получить идеальный русский синхрон губ четырёх персонажей. Диалоги записать отдельно голосами актёров и наложить при монтаже; не имитировать реальные архивные голоса.
- В сценах нет титров и текста; новогодний титр добавить позже при монтаже.
- У короткого ролика должна быть **своя оригинальная музыка**, не аудио из Instagram.

## Покадровая история и готовые английские промпты

### 01 — 00:00–00:08 — «До двенадцати» (8 s)
**Картинка:** пустая Главная сцена Клуба Козлова. Перевернутый бокал? Нет, бокалы на столиках после праздника; остатки конфетти; часы переходят через полночь. Портреты неподвижны. Рояль звучит сам.
**Аудио:** часы, лёгкий шелест и один тихий аккорд.
**Референсы:** Image 1 — фотография настоящего пустого зала, желательно с одной стеной и сценой.
**Prompt:**
> Eight-second photorealistic vertical cinematic shot. The real Alexey Kozlov Club in Moscow, based EXACTLY on Image 1: retain the actual architecture, stage, walls, furniture and proportions. It is New Year's Eve, midnight, after closing; empty tables and a few champagne glasses, tasteful candlelit decorations, delicate golden fairy lights, framed black-and-white jazz portraits on the existing wall. Camera slowly glides along the tables, moves toward the portraits. The room clock reaches midnight; a piano key softly presses itself. 19th-century ballroom film atmosphere, luxurious warm amber candlelight, champagne highlights, velvety shadows, elegant, lighthearted anticipation. No people yet, no new architecture, no fantasy palace, no text, no dialogue, no cartoon ghosts. One continuous camera shot, 9:16.

### 02 — 00:08–00:16 — «Луи выходит из рамы» (8 s)
**Картинка:** на стене чёрно-белый портрет Армстронга; он оживает и в винно-бордовом фраке перешагивает нижний край рамы; краски появляются от света помещения.
**Референсы:** Image 1 — именно эта стена клуба; Image 2 — лицензированный портрет Армстронга; Image 3 — стоп-кадр мужского костюма из Reel.
**Prompt:**
> Eight-second magical-realist period ballroom comedy shot IN THE EXACT REAL Kozlov Club interior of Image 1. A framed black-and-white portrait of Louis Armstrong hangs on the wall, matching Image 2 for facial identity. The photograph becomes alive: a subtle smile, the eyes glance across the room; then Louis Armstrong steps naturally, physically out of the picture frame, dressed in a sumptuous burgundy velvet 19th-century long-tail evening coat with black satin lapels, immaculate white shirt, black bow tie and pocket-watch chain, carrying his trumpet. At first he is black and white, then the club's warm golden lighting brings his clothes and skin into natural color. His body and shadow obey physical space. A charismatic, amused expression. Camera gently tracks backward so we see his full-body step. No glowing portal, no blue supernatural fog, no plastic skin, no distorted legs, no redesign of club, no text. 9:16.

### 03 — 00:16–00:26 — «Гости собираются» (10 s)
**Картинка:** выход Майлза, Колтрейна, Завинула — через три быстрых отдельных ракурса и общая встреча. Эффект из рам как у Армстронга.
**Референсы:** тот же интерьер; портреты трёх джазменов, отделённые от кадров фильма. Лучше разбить на 3 отдельных генерации по 5 сек, если лица смешиваются.
**Prompt:**
> Ten-second cinematic montage within the EXACT SAME real Kozlov Club architecture, elegant 19th-century costume ball realism. Three separate jazz portraits become animated in sequence, and their recognizable subjects step out of their frames: Miles Davis in a sophisticated black long-tail coat with burgundy satin lapels and a muted trumpet; John Coltrane in a midnight-navy tailcoat, ivory embroidered waistcoat, carrying a tenor saxophone; Joe Zawinul in an extravagant powder-pink tailcoat, ornate burgundy-and-gold brocade waistcoat, silk cravat. Each emerges physically from a framed photograph and becomes naturally colored by warm golden club lighting. Fast elegant cinematic cuts between three portraits, end with the four legends exchanging amused glances. Respect their distinct recognizable faces and relative identities; no face mixing, no duplicate people, no magical particles, no redesigned architecture, no dialogue, no text. 9:16.

### 04 — 00:26–00:35 — «Начинается бал» (9 s)
**Картинка:** главный зал расширяется визуально, но остается узнаваемым. Гирлянды становятся похожими на свечи, появляются гости в кринолинах. Торжественный вальс.
**Референсы:** настоящий зал; кадр бальных платьев; 11-секундный отрывок костюмов из Reel (при желании, только один видеофайл не длиннее 15 секунд).
**Prompt:**
> Nine-second luxurious 19th-century New Year ballroom sequence unfolding *inside the actual Kozlov Club* from Image 1; preserve its stage, room footprint and visual architecture. Warm crystal-and-candle reflections gradually fill the room as ten to fifteen elegantly dressed phantom guests appear around existing tables, as though invited from old framed photos. Women wear immense silk crinoline gowns in powder blue, silver mint, pale rose and ivory, narrow fitted corseted bodices, puffed sleeves and carefully arranged curls. Men wear wine-red velvet tailcoats, white shirts, embroidered waistcoats and silk neckwear. Slow dignified ballroom waltz begins, sweeping skirts across the floor. The camera glides between dancers at human height, lush theatrical period-film production value, playful and magical, no spooky ghosts or excessive VFX. No text, no altered club architecture. 9:16.

### 05 — 00:35–00:45 — «Что играют сегодня?» (10 s)
**Картинка:** у столика Луи, Майлз и Колтрейн с лёгкой иронией наблюдают за балом. Реплики добавить на монтаже.
**Диалог (вымышленный):** Луи: «Господа, у них тут четыре сцены!» Майлз: «Главное, чтобы не играли как мы». Колтрейн: «Пусть ищут своё».
**Референсы:** зал и портреты персонажей.
**Prompt:**
> Ten-second lively 19th-century ballroom comedy shot in the exact real Kozlov Club interior. Louis Armstrong in burgundy velvet tailcoat, Miles Davis in black and burgundy satin tailcoat, and John Coltrane in midnight-blue tailcoat sit at a small table by the stage, holding their instruments nearby, watching the magical ballroom. They exchange subtle smiles and dry, amused looks: Louis laughs warmly; Miles raises one eyebrow with restrained irony; Coltrane nods thoughtfully and looks toward the musicians on stage. Elegant cinematic medium shots and two tasteful reaction cuts. Crystal chandeliers and soft candle glow are decorative additions, but the actual club architecture, furniture and layout remain identifiable. The dancing happens softly in the background. No audible speech, leave space for separately recorded Russian dialogue, no subtitles or text. 9:16.

### 06 — 00:45–00:55 — «Завинул вмешивается» (10 s)
**Картинка:** Завинул подходит к клавишным, нажимает одну клавишу, ритм вальса ломается, возникает мощный оригинальный джаз-фанк. Ироничная реплика: «А танцевать они умеют?»
**Референсы:** реальная сцена клуба; портрет Завинула; фото мужского пудрово-розового фрака.
**Prompt:**
> Ten-second cinematic playful period-ballroom comedy inside the exact real stage of Alexey Kozlov Club. Joe Zawinul, fully recognizable and dressed in a flamboyant powder-pink 19th-century tailcoat, ornate burgundy waistcoat with gold embroidery, deep red silk cravat, approaches the actual keyboard setup. He looks at the formal waltzing guests, gives a mischievous grin, then confidently strikes a syncopated chord. At that instant the score pivots from a stately classical ballroom waltz into a fresh original infectious jazz-funk groove with electric keys, tight drums and syncopated bass. Guests stop and exchange surprised looks before shoulders begin to move to the groove. One bold dolly-in and a witty reaction cut; warm candlelit luxury and realistic body movement, authentic venue details. No dubbed speech, no captions or text, no fake floating instruments. 9:16.

### 07 — 00:55–01:05 — «Большой танец» (10 s)
**Картинка:** кульминация. Вспышка коллективной радости. Свинг сменяется синхронными современными движениями. Камера как в референсе скользит среди танцующих.
**Референсы:** реальный зал; кадр кринолинов; VIDEO 1 — 10-секундный обрезанный танцевальный отрывок референса. Видео ТОЛЬКО для настроения, движения и ритма монтажа; не копировать актёров и дворец.
**Prompt:**
> Ten-second jubilant cinematic ballroom dance climax INSIDE THE EXACT REAL KOZLOV CLUB, not a palace. Costume and choreography tone inspired by Video 1 ONLY: lavish 19th-century formal court dress contrasted with unexpected modern energetic synchronized dance steps. At first women in sweeping pastel blue, mint, ivory and blush-pink crinoline gowns and men in crimson velvet tailcoats attempt refined period choreography; then the tight jazz-funk groove triggers playful contemporary footwork, claps and syncopated turns. Louis Armstrong beams and moves with the beat; Miles keeps a wry composed smile; Coltrane joins the rhythm; Joe Zawinul leads from the keys. Smooth camera moving through dancers, readable full-body proportions, wide-to-medium cuts, rich golden festive lighting, lively but classy and witty. Use the movement energy and camera rhythm from Video 1, NOT its original actors, NOT its palace setting, NOT its soundtrack. Keep club's stage and furniture visible. No glitches, no copy-paste dancers, no text. 9:16.

### 08 — 01:05–01:15 — «Фотографии помнят» (10 s)
**Картинка:** ритм резко прерывается, клуб снова пустеет. Все возвращаются в фоторамки. Камера на Армстронга, который в последний момент подмигивает. Титр добавляется в монтажной программе.
**Референсы:** точная стена клуба с портретами; те же фото музыкантов.
**Prompt:**
> Ten-second magical-realist finale in the exact real Alexey Kozlov Club interior. At the end of an exuberant mysterious New Year's ballroom celebration, a delicate final brass note rings out and the dancing crowd quietly fades back into the old framed photographs on the wall, not as scary transparent ghosts but as a graceful rewind of living portraits. The four jazz legends return to their respective black-and-white photographs. The room is again empty and still, preserving the exact furniture, stage and wall layout of the source image. Camera moves slowly toward Louis Armstrong's framed portrait; at the last second his eyes move and he gives the viewer a subtle knowing wink, then freezes into a vintage photograph. Gold candlelight gently dims. Elegant charming final beat. NO generated lettering or subtitles; reserve upper and lower negative space for text added in editing. 9:16.

## Монтаж и звук
**00:00–00:25:** полуночный бой, тишина клуба, затем оригинальный акустический вальс. **00:25–00:45:** струнные вальса постепенно уступают свинговой ритм-секции. **00:45–01:06:** узнаваемый драматургический поворот к оригинальному джаз-фанку, упругому басу, Fender Rhodes-подобным тембрам, живым барабанам и духовым. **01:06–01:15:** одна финальная труба, щелчок рамки, тишина.
**Титр в последние 3 секунды:** «С НОВЫМ 2027 ГОДОМ!» / «KEEPING JAZZ ALIVE SINCE 2012» / «KOZLOV CLUB».
**Драматургия:** 4 + 4 + 8 + 10 ... ; аудио склеивает независимые генерации.
**Диалоги:** все реплики вымышленные, не архивные. Для озвучки — актёрские голоса, не выдавать их за настоящие голоса исторических музыкантов.

## Практический порядок производства
1. Подготовить 4–6 качественных скриншотов *реального* 3D-тура клуба (общий зал, портретная стена, сцена, зона клавишных), без UI-элементов. Если портретной стены не существует, место под фоторамы добавить на реальный кадр заранее.
2. Подобрать 4 изображения музыкантов с правами, достаточными для публикации рекламного ролика. Создать один неизменный костюмный лист для каждого.
3. Сначала протестировать сцену №2 (Луи выходит из портрета), затем №7 (танец), затем уже остальные: это самые рискованные кадры.
4. Загрузить в H3 Flex каждый раз только нужные конкретной сцене изображения, а из исходного видео для сцены №7 — обрезанный 10-секундный фрагмент.
5. Выгрузить отдельные MP4, склеить по таймингу, добавить отдельно созданные диалоги и оригинальный саундтрек, наложить титр в конце.

**Важно:** пока у нас есть стиль и образец движения, но нет подходящих кадров интерьера для достоверной генерации. Без них система может нарисовать вымышленный клуб.

**Ограничения Pika по актуальной странице модели (31.10.2026):** выходные клипы 5–15 сек; видеореференсы 2–15 сек; до 12 референсов суммарно; референсные изображения JPEG/PNG/WebP, режим 9:16 поддерживается. Ограничения длительности суммарно по видео/аудиотипу следует учитывать при каждой отправке. Источник: https://dev.pika.art/models/pika/pika-h3-flex/reference-to-video
