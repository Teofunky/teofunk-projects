/*! Kozlov Navigator 1.0.7: подбор концертов клуба по ответам посетителя. Исходники: src/engine.js, src/ui.js. */
(function () {
'use strict';
var module = { exports: {} };
/*!
 * Kozlov Navigator: движок подбора концертов.
 *
 * Правила работы (решения клуба):
 *  - подбор без ИИ, только по правилам из этого файла;
 *  - никакого приоритета по марже;
 *  - жанры берутся из индекса Kozlov Smart Search, настроение сводится к этим жанрам;
 *  - концерт в 23:59 означает полночь этого дня.
 *
 * Как устроен подбор, по шагам:
 *  1. buildEvents(): каждому концерту из индекса KSS считается профиль
 *     (энергия, формат, инструменты, особый вечер) по жанрам, названию и тексту анонса.
 *  2. Жёсткие условия: дата, время суток, бюджет. Если подходящих меньше трёх,
 *     условия ослабляются по лестнице RELAX, и карточка честно помечается.
 *  3. Мягкие условия дают баллы 0..100 (WEIGHTS): настроение, звучание, опыт,
 *     компания, близость даты, особый вечер, покупка онлайн.
 *  4. Три карточки выбираются так, чтобы это были три разных вечера (DIVERSITY).
 *  5. Для каждой карточки строится строка «почему вам» из самых сильных совпадений.
 *
 * Файл без зависимостей. Работает в браузере (window.KozlovNavigator) и в Node (module.exports).
 */
(function (root, factory) {
  'use strict';
  var api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.KozlovNavigator = api;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /* ===================== НАСТРОЙКИ ===================== */

  /** Вес каждого фактора в итоговом балле (сумма 100). */
  var WEIGHTS = {
    mood: 40,       // насколько концерт попадает в выбранное настроение
    sound: 18,      // любимые инструменты и формат (биг-бэнд, джем)
    experience: 8,  // уровень слушателя: новичку понятное, знатоку редкое
    company: 6,     // один, вдвоём, компанией
    soon: 14,       // чем ближе дата, тем выше шанс, что человек пойдёт
    special: 7,     // премьера, презентация, юбилей, зарубежный гость
    online: 7       // билет покупается сразу в окне, без звонка
  };

  /** Штрафы за похожесть внутри тройки, чтобы получились три разных вечера. */
  var DIVERSITY = { sameShow: 40, sameDate: 12, sameStageSameDate: 6, bothJam: 8, bothBrunch: 8 };

  /** Порог: с какого балла настроения концерт считается «подходящим по настроению». */
  var MOOD_OK = 0.5;

  /** Горизонт подбора без выбранной даты, дней. */
  var HORIZON_DAYS = 45;

  /** Не предлагать концерты, которые начинаются раньше чем через N минут. */
  var MIN_LEAD_MIN = 20;

  var STAGES = {
    g: { name: 'Главная сцена', short: 'Главная сцена', color: '#f24841' },
    m: { name: 'Мансарда', short: 'Мансарда', color: '#2d6c8c' },
    u: { name: 'Сцена на Мясницкой, 15', short: 'Мясницкая, 15', color: '#5a7d38' },
    b: { name: 'The Beat', short: 'The Beat', color: '#e8d800' }
  };

  var GENRE_RU = {
    jazz: 'джаз', festival: 'фестиваль', classics: 'классика', jam: 'джем', fusion: 'фьюжн',
    rock: 'рок', 'jazz-rock': 'джаз-рок', funk: 'фанк', soul: 'соул', manouche: 'мануш',
    'hip-hop': 'хип-хоп', pop: 'поп', arsenal: '«Арсенал»', blues: 'блюз', latin: 'латино',
    dance: 'танцы', ethnics: 'этника', folk: 'фолк', 'avant-garde': 'авангард', gospel: 'госпел',
    indie: 'инди', flamenco: 'фламенко'
  };

  /** Энергия жанра, 1 (тихо) .. 5 (громко и танцевально). */
  var GENRE_ENERGY = {
    funk: 5, 'hip-hop': 5, dance: 5, rock: 4, 'jazz-rock': 4, latin: 4, flamenco: 4, jam: 4,
    arsenal: 4, blues: 3, soul: 3, fusion: 3, pop: 3, gospel: 3, indie: 3, ethnics: 3,
    manouche: 3, jazz: 3, festival: 3, folk: 2, 'avant-garde': 2, classics: 1
  };
  var ENERGY_UP = ['драйв', 'зажигат', 'энергичн', 'огнен', 'мощн', 'ураган', 'танцевальн', 'грув', 'groove', 'funky', 'жарк', 'взрывн'];
  var ENERGY_DOWN = ['лирич', 'камерн', 'уютн', 'нежн', 'баллад', 'медитат', 'созерцат', 'спокойн'];

  /**
   * Настроения. genres: близость жанров KSS к настроению (0..1); energy: желаемая энергия;
   * words: слова-признаки в названии и анонсе; flag: особый признак профиля; avoid: что мешает.
   */
  var MOODS = [
    { id: 'drive', label: 'Драйв и танцы', short: 'драйв', hint: 'фанк, соул, латино',
      genres: { funk: 1, dance: 1, 'hip-hop': 0.9, rock: 0.8, 'jazz-rock': 0.8, latin: 0.8, arsenal: 0.7, flamenco: 0.7, blues: 0.6, soul: 0.6, jam: 0.6, fusion: 0.5 },
      energy: 4.5, words: ['драйв', 'танц', 'зажигат', 'грув', 'groove', 'энергичн', 'огнен', 'фанк', 'funk', 'жарк', 'party', 'вечеринк'],
      avoid: { classics: 0.6, 'avant-garde': 0.3 } },
    { id: 'romance', label: 'Романтика', short: 'романтика', hint: 'соул, босса-нова, баллады',
      genres: { soul: 1, latin: 0.9, manouche: 0.8, flamenco: 0.6, pop: 0.6, classics: 0.6, jazz: 0.5, gospel: 0.4 },
      energy: 2.5, words: ['романт', 'любов', 'лирич', 'баллад', 'босса', 'bossa', 'нежн', 'свидан', 'франц', 'шансон', 'чувств', 'love'],
      avoid: { 'hip-hop': 0.6, rock: 0.4, jam: 0.4, 'jazz-rock': 0.3 } },
    { id: 'calm', label: 'Уютно и спокойно', short: 'уютный вечер', hint: 'камерный джаз, акустика',
      genres: { classics: 1, manouche: 0.8, jazz: 0.6, folk: 0.6, soul: 0.5, ethnics: 0.4 },
      energy: 2, words: ['уютн', 'камерн', 'лирич', 'акустич', 'нежн', 'баллад', 'мягк', 'спокойн', 'медитат', 'тепл'],
      avoid: { 'hip-hop': 0.6, rock: 0.6, funk: 0.5, dance: 0.5, jam: 0.5, 'jazz-rock': 0.4 } },
    { id: 'hits', label: 'Знакомые хиты', short: 'знакомые мелодии', hint: 'стандарты, трибьюты',
      genres: { pop: 1, gospel: 0.6, soul: 0.6, rock: 0.6, dance: 0.6, arsenal: 0.5, blues: 0.4 },
      energy: 3.5, flag: 'hits', words: ['хит', 'легендарн', 'шлягер', 'стандарт', 'кавер', 'cover', 'трибьют', 'tribute', 'саундтрек', 'мюзикл', 'бродвей', 'битлз', 'beatles', 'queen', 'abba', 'синатр', 'элвис', 'любим', 'известн', 'популярн'],
      avoid: { 'avant-garde': 0.6 } },
    { id: 'deep', label: 'Глубоко и необычно', short: 'авторская музыка', hint: 'авторский джаз, фьюжн',
      genres: { 'avant-garde': 1, fusion: 0.8, indie: 0.6, ethnics: 0.6, 'jazz-rock': 0.6, classics: 0.5, folk: 0.4 },
      energy: 3, flag: 'original', words: ['авторск', 'импровиз', 'экспериментал', 'композитор', 'авангард', 'современн', 'оригинальн', 'собственн', 'модальн', 'концептуальн'],
      avoid: { pop: 0.4, dance: 0.3 } },
    { id: 'surprise', label: 'Удивите меня', short: 'что-то новое', hint: 'редкие инструменты, гости',
      genres: { ethnics: 1, flamenco: 0.9, 'avant-garde': 0.8, folk: 0.7, latin: 0.6, indie: 0.6, classics: 0.5 },
      energy: null, flag: 'unusual', words: ['необычн', 'редк', 'экзотик', 'этно', 'маримб', 'вибрафон', 'дудук', 'ситар', 'варган', 'калимб', 'синтез'],
      avoid: { jam: 0.3 } }
  ];

  /**
   * Инструменты. Признак ищется в названии (ведущий инструмент) и в тексте анонса (в составе).
   * Слово со знаком «=» ищется целиком, остальные как начало слова.
   */
  var INSTRUMENTS = [
    { id: 'vocal', label: 'Вокал', stems: ['вокал', 'певиц', 'певец', 'поет', 'поют', 'singers'] },
    { id: 'sax', label: 'Саксофон', stems: ['саксофон', 'sax'] },
    { id: 'piano', label: 'Фортепиано', stems: ['фортепиан', 'пианист', 'рояль', 'рояле', 'piano'] },
    { id: 'guitar', label: 'Гитара', stems: ['гитар', 'guitar', 'фингерстайл'] },
    { id: 'trumpet', label: 'Труба', stems: ['труба', 'трубе', 'трубы', 'трубой', 'флюгельгорн', 'trumpet'] },
    { id: 'bass', label: 'Контрабас', stems: ['контрабас', 'басист', 'бас-гитар', 'bass'] },
    { id: 'drums', label: 'Ударные', stems: ['ударн', 'барабан', 'drum'] },
    { id: 'violin', label: 'Скрипка', stems: ['скрипк', 'скрипач', 'violin'] },
    { id: 'trombone', label: 'Тромбон', stems: ['тромбон'] },
    { id: 'clarinet', label: 'Кларнет', stems: ['кларнет'] },
    { id: 'flute', label: 'Флейта', stems: ['флейт'] },
    { id: 'percussion', label: 'Перкуссия', stems: ['перкусси', 'кахон', 'конги', 'бонго'] },
    { id: 'accordion', label: 'Аккордеон', stems: ['аккордеон', 'баян', 'бандонеон'] },
    { id: 'cello', label: 'Виолончель', stems: ['виолончел'] },
    { id: 'mallets', label: 'Маримба, вибрафон', stems: ['маримб', 'вибрафон', 'marimba'] },
    { id: 'organ', label: 'Орган Хаммонд', stems: ['хаммонд', 'hammond', '=орган', '=органе', '=органом', 'органист'] }
  ];

  /** Форматы, которые можно выбрать на шаге «Звучание». */
  var FORMATS = [
    { id: 'bigband', label: 'Большой оркестр' },
    { id: 'jam', label: 'Джем-сейшн' },
    { id: 'vocalnight', label: 'Вокальный вечер' }
  ];

  var WHEN = [
    { id: 'today', label: 'Сегодня' },
    { id: 'tomorrow', label: 'Завтра' },
    { id: 'weekend', label: 'Выходные' },
    { id: 'week', label: 'На неделе' },
    { id: 'any', label: 'Не важно' }
  ];
  var TIMES = [
    { id: 'day', label: 'Днём', hint: 'до 17:00' },
    { id: 'evening', label: 'Вечером', hint: '17–22' },
    { id: 'night', label: 'Поздно', hint: 'с 22:00' }
  ];
  var BUDGETS = [
    { id: 1000, label: 'до 1 000 ₽' },
    { id: 1500, label: 'до 1 500 ₽' },
    { id: 2000, label: 'до 2 000 ₽' },
    { id: 0, label: 'Не важно' }
  ];
  var EXPERIENCE = [
    { id: 'new', label: 'Впервые', hint: 'или почти' },
    { id: 'regular', label: 'Иногда', hint: 'пару раз в год' },
    { id: 'expert', label: 'Часто', hint: 'разбираюсь в стилях' }
  ];
  var COMPANY = [
    { id: 'solo', label: 'Один' },
    { id: 'pair', label: 'Вдвоём' },
    { id: 'group', label: 'Компанией' }
  ];

  var SPECIAL_TITLE = [
    ['премьер', 'премьера'], ['презентац', 'презентация'], ['юбиле', 'юбилейный вечер'],
    ['фест', 'фестиваль'], ['20 лет', 'юбилейный вечер'], ['tribute night', 'трибьют-вечер']
  ];
  var FOREIGN_RE = /\((?:[^)]*?)(куба|сша|израил|австри|бразил|япон|камерун|ямайк|греци|франц|итали|герман|англи|великобрит|испани|аргентин|индия|китай)/i;

  var MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  var MONTHS_SHORT = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
  var WD_SHORT = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
  var WD_FULL = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
  var WD_IN = ['в воскресенье', 'в понедельник', 'во вторник', 'в среду', 'в четверг', 'в пятницу', 'в субботу'];

  /* ===================== ВСПОМОГАТЕЛЬНОЕ ===================== */

  function clamp(x, a, b) { return x < a ? a : (x > b ? b : x); }
  function norm(s) { return String(s || '').toLowerCase().replace(/ё/g, 'е'); }

  /** Текст в виде « слово слово » для быстрого поиска по началу слова. */
  function wordline(words) { return ' ' + words.join(' ') + ' '; }
  function splitWords(s) { return norm(s).replace(/[«»"'’`“”&:;,.!?()\/\\–—+%]+/g, ' ').split(/\s+/).filter(Boolean); }

  /** Есть ли в тексте слово, начинающееся со stem (или равное ему, если stem начинается с «=»). */
  function has(line, stem) {
    if (stem.charAt(0) === '=') return line.indexOf(' ' + stem.slice(1) + ' ') !== -1;
    return line.indexOf(' ' + stem) !== -1;
  }
  function countHits(line, stems) {
    var n = 0;
    for (var i = 0; i < stems.length; i++) if (has(line, stems[i])) n++;
    return n;
  }

  function isoDay(ms) { return new Date(ms).toISOString().slice(0, 10); }
  function addDays(iso, n) { var p = iso.split('-'); return isoDay(Date.UTC(+p[0], +p[1] - 1, +p[2] + n)); }
  function dayDiff(a, b) { // b - a в днях
    var pa = a.split('-'), pb = b.split('-');
    return Math.round((Date.UTC(+pb[0], +pb[1] - 1, +pb[2]) - Date.UTC(+pa[0], +pa[1] - 1, +pa[2])) / 864e5);
  }
  function dowOf(iso) { return new Date(iso + 'T00:00:00Z').getUTCDay(); }

  /** Цена из строки афиши: «от 1500 руб.», «ДЕПОЗИТ 2 000 РУБ.», «Вход свободный». */
  function parsePrice(s) {
    var t = norm(s);
    if (/свободн|бесплатн/.test(t)) return { amount: 0, entry: 'free' };
    var m = t.replace(/\s+/g, '').match(/(\d{3,6})/);
    var amount = m ? parseInt(m[1], 10) : null;
    return { amount: amount, entry: /депозит/.test(t) ? 'deposit' : (amount === null ? 'unknown' : 'ticket') };
  }
  function rub(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + '\u00a0₽'; }

  /* ===================== 1. ПРОФИЛЬ КОНЦЕРТА ===================== */

  /**
   * Превращает индекс и тексты Kozlov Smart Search в список концертов с профилем.
   * index: ответ /wp-json/kozlov-search/v1/index; texts: ответ /texts (необязательно).
   */
  function buildEvents(index, texts) {
    var off = (index && index.off) || 10800;
    var vocab = texts && texts.vocab ? texts.vocab.split(' ') : null;
    var docCache = {};
    function docWords(i) {
      if (!vocab || i === null || i === undefined || !texts.docs[i]) return [];
      if (docCache[i]) return docCache[i];
      var acc = 0, out = [], d = texts.docs[i];
      for (var k = 0; k < d.length; k++) { acc += d[k]; out.push(norm(vocab[acc])); }
      return (docCache[i] = out);
    }
    var events = [];
    var rows = (index && index.ev) || [];
    for (var r = 0; r < rows.length; r++) {
      var e = rows[r];
      if (!e || !e[0] || !/^\d{4}-\d\d-\d\d$/.test(e[1]) || !/^\d\d:\d\d$/.test(e[2])) continue;
      var genres = (Array.isArray(e[8]) ? e[8] : []).map(norm);
      var titleWords = splitWords(e[0]);
      var tLine = wordline(titleWords);
      var line = wordline(titleWords.concat(docWords(e[9])));
      var p = parsePrice(e[4]);
      var hh = +e[2].slice(0, 2), mm = +e[2].slice(3, 5);
      var ymd = e[1].split('-');
      events.push({
        id: e[5], title: String(e[0]).replace(/\s+/g, ' ').trim(),
        date: e[1], time: e[2], midnight: e[2] === '23:59', hour: hh,
        start: Date.UTC(+ymd[0], +ymd[1] - 1, +ymd[2], hh, mm) - off * 1000,
        stage: STAGES[e[3]] ? e[3] : '', priceText: String(e[4] || '').replace(/(от \d+ руб\.)\s+\1/i, '$1').trim(),
        price: p.amount, entry: p.entry,
        genres: genres, img: e[6] || '', webp: e[11] || 0,
        tc: (e[7] === 1 || e[7] === 2) && /^[A-Za-z0-9_-]{6,80}$/.test(String(e[10] || '')) ? String(e[10]) : '',
        tcKind: e[7] === 2 ? 'registration' : (e[7] === 1 ? 'ticket' : ''),
        doc: e[9],
        profile: profile(e[0], genres, tLine, line, hh)
      });
    }
    return hydrate(events);
  }

  /** Досчитывает общие для афиши величины. Нужна и для заранее посчитанных профилей (JSON). */
  function hydrate(events) {
    // насколько инструмент редкий в афише: редкое совпадение весит больше частого
    var total = events.length || 1, share = {};
    INSTRUMENTS.forEach(function (ins) {
      var n = 0;
      events.forEach(function (ev) { if (ev.profile.instruments[ins.id]) n++; });
      share[ins.id] = n / total;
    });
    events.forEach(function (ev) { ev.share = share; });
    events.sort(function (a, b) { return a.start - b.start; });
    return events;
  }

  /** Профиль концерта: всё, что нужно для подбора, считается один раз. */
  function profile(title, genres, tLine, line, hour) {
    var t = norm(title);
    // энергия: два самых энергичных жанра плюс слова в анонсе
    var en = genres.map(function (g) { return GENRE_ENERGY[g] || 3; }).sort(function (a, b) { return b - a; });
    var energy = en.length ? (en.length > 1 ? en[0] * 0.6 + en[1] * 0.4 : en[0]) : 3;
    energy += clamp(countHits(line, ENERGY_UP) * 0.25, 0, 0.75) - clamp(countHits(line, ENERGY_DOWN) * 0.25, 0, 0.75);
    if (hour >= 22) energy += 0.3;
    if (hour < 17) energy -= 0.3;

    var jam = genres.indexOf('jam') !== -1 || /(^|[\s«"])(джем|jam)([\s»"-]|$)/.test(t);
    var bigband = /оркестр|orchestra|big band|биг-бэнд|бигбэнд|^бдо /.test(t);
    var brunch = /бранч|brunch/.test(t);
    var kids = genres.indexOf('for kids') !== -1 || /для самых маленьких|детск/.test(t);
    var contest = /конкурс/.test(t);
    var tribute = /трибьют|tribute/.test(t);
    var hits = tribute || countHits(line, MOODS[3].words) >= 2 || genres.indexOf('pop') !== -1;
    var original = countHits(line, MOODS[4].words) >= 2 || genres.indexOf('avant-garde') !== -1;
    var foreign = FOREIGN_RE.test(title);
    var special = null;
    for (var i = 0; i < SPECIAL_TITLE.length && !special; i++) if (t.indexOf(SPECIAL_TITLE[i][0]) !== -1) special = SPECIAL_TITLE[i][1];
    if (!special && foreign) special = 'зарубежный гость';
    if (!special && has(line, 'презентац')) special = 'презентация программы';

    var instruments = {};
    INSTRUMENTS.forEach(function (ins) {
      var lead = ins.stems.some(function (s) { return has(tLine, s); });
      var present = lead || ins.stems.some(function (s) { return has(line, s); });
      if (present) instruments[ins.id] = lead ? 2 : 1;
    });
    var vocalNight = /певиц|вокал|singers|хор/.test(t) || (instruments.vocal === 2);
    // сколько слов-признаков каждого настроения нашлось в названии и анонсе
    var moodWords = {};
    MOODS.forEach(function (m) { moodWords[m.id] = countHits(line, m.words); });
    var rare = !!(instruments.mallets || instruments.accordion || instruments.cello || instruments.organ || instruments.flute ||
      genres.indexOf('ethnics') !== -1 || genres.indexOf('flamenco') !== -1);

    return {
      energy: clamp(energy, 1, 5), jam: jam, bigband: bigband, brunch: brunch, kids: kids, contest: contest,
      tribute: tribute, hits: hits, original: original, foreign: foreign, special: special, rare: rare,
      vocalNight: vocalNight, instruments: instruments, moodWords: moodWords
    };
  }

  /* ===================== 2. ЖЁСТКИЕ УСЛОВИЯ ===================== */

  /** Московское «сейчас» и даты-ориентиры, как в панели фильтров афиши. */
  function clock(nowMs, off) {
    off = off || 10800;
    var today = isoDay(nowMs + off * 1000);
    var dow = dowOf(today) || 7; // 1 пн .. 7 вс
    return {
      now: nowMs, today: today, tomorrow: addDays(today, 1),
      weekendFrom: dow <= 4 ? addDays(today, 5 - dow) : today,
      weekendTo: addDays(today, 7 - dow)
    };
  }

  /** Диапазон дат для ответа «Когда». widen: 0 как выбрано, 1 шире, 2 ещё шире. */
  function dateRange(answers, c, widen) {
    var w = answers.when || 'any';
    var from, to;
    if (w === 'date' && answers.date) { from = answers.date; to = answers.date; }
    else if (w === 'today') { from = c.today; to = c.today; }
    else if (w === 'tomorrow') { from = c.tomorrow; to = c.tomorrow; }
    else if (w === 'weekend') { from = c.weekendFrom; to = c.weekendTo; }
    else if (w === 'week') { from = c.today; to = addDays(c.today, 6); }
    else { from = c.today; to = addDays(c.today, HORIZON_DAYS); }
    if (widen === 1) {
      if (w === 'today' || w === 'tomorrow') to = addDays(to, 1);
      else if (w === 'weekend') to = addDays(to, 7);
      else if (w === 'date') { from = addDays(from, -2); to = addDays(to, 3); }
      else if (w === 'week') to = addDays(to, 7);
    } else if (widen >= 2) {
      to = addDays(c.today, HORIZON_DAYS);
      if (from > c.today && w !== 'any') from = c.today;
    }
    if (from < c.today) from = c.today;
    return { from: from, to: to };
  }

  function slotOf(ev) { return ev.hour < 17 ? 'day' : (ev.hour < 22 ? 'evening' : 'night'); }

  /** Проходит ли концерт жёсткие условия на данном уровне ослабления. */
  function passes(ev, answers, c, level) {
    if (ev.profile.kids) return false;
    if (ev.start < c.now + MIN_LEAD_MIN * 60000) return false;
    var widen = level >= 3 ? 2 : (level >= 2 ? 1 : 0);
    var range = dateRange(answers, c, widen);
    if (ev.date < range.from || ev.date > range.to) return false;
    var times = answers.times || [];
    if (level < 1 && times.length && times.indexOf(slotOf(ev)) === -1) return false;
    var budget = answers.budget || 0;
    if (budget && ev.price !== null) {
      var limit = level >= 5 ? Infinity : (level >= 4 ? budget * 1.5 : budget);
      if (ev.price > limit) return false;
    }
    return true;
  }

  /**
   * Лестница ослабления, по порядку: сначала время суток, потом даты, потом бюджет.
   * Уровень 0 означает «всё как выбрано». note попадает на карточку.
   */
  var RELAX = [
    null,
    { key: 'time', note: 'другое время' },
    { key: 'date', note: 'соседняя дата' },
    { key: 'date2', note: 'ближайшая подходящая дата' },
    { key: 'budget', note: 'чуть дороже бюджета' },
    { key: 'budget', note: 'дороже бюджета' }
  ];

  /**
   * Порядок заполнения тройки: [уровень уступки, только сильные совпадения].
   * Настроение важнее времени суток и соседней даты, а бюджет уступаем последним.
   */
  var ORDER = [[0, true], [1, true], [2, true], [0, false], [3, true], [1, false], [2, false], [3, false], [4, true], [4, false], [5, true], [5, false]];

  /** Пометки на карточке: что уступили и совпало ли настроение. */
  function relaxNotes(it, answers) {
    var out = [];
    if (it.level) {
      var r = RELAX.slice(1, it.level + 1).filter(function (x) { return !relaxIrrelevant(x.key, answers); });
      if (r.length) out.push(r[r.length - 1].note);
    }
    if (!it.strong) {
      if ((answers.moods || []).length && it.s.mood.score < MOOD_OK) out.push('другое настроение');
      else if (it.s.sound.score <= 0) out.push('без выбранного звучания');
    }
    return out;
  }

  /* ===================== 3. БАЛЛЫ ===================== */

  function moodScore(ev, mood) {
    var cache = ev._mood || (ev._mood = {});
    if (cache[mood.id]) return cache[mood.id];
    return (cache[mood.id] = moodScoreRaw(ev, mood));
  }
  function moodScoreRaw(ev, mood) {
    var p = ev.profile, g = 0, a = 0, matched = [];
    ev.genres.forEach(function (x) {
      var v = mood.genres[x] || 0;
      if (v > g) g = v;
      if (v >= 0.6) matched.push(x);
      var av = (mood.avoid && mood.avoid[x]) || 0;
      if (av > a) a = av;
    });
    if (p.jam && mood.avoid && mood.avoid.jam > a) a = mood.avoid.jam;
    var e = mood.energy === null ? 0.5 : 1 - Math.abs(p.energy - mood.energy) / 3;
    var w = Math.min(1, (p.moodWords[mood.id] || 0) / 2);
    var f = 0;
    if (mood.flag === 'hits' && p.hits) f = p.tribute ? 1 : 0.6;
    if (mood.flag === 'original' && p.original) f = 1;
    if (mood.flag === 'unusual') f = (p.rare ? 0.6 : 0) + (p.foreign ? 0.5 : 0) + (p.special ? 0.3 : 0);
    var s = 0.45 * g + 0.25 * clamp(e, 0, 1) + 0.2 * w + 0.25 * Math.min(1, f) - 0.5 * a;
    return { score: clamp(s, 0, 1), matched: matched, words: w > 0 };
  }

  /** Попадание в выбранные настроения: лучшее совпадение важнее среднего. */
  function moodFit(ev, answers) {
    var ids = answers.moods || [];
    if (!ids.length) return { score: 0.5, neutral: true };
    var best = null, sum = 0;
    ids.forEach(function (id) {
      var mood = MOODS.filter(function (m) { return m.id === id; })[0];
      if (!mood) return;
      var r = moodScore(ev, mood);
      sum += r.score;
      if (!best || r.score > best.score) best = { score: r.score, mood: mood, matched: r.matched };
    });
    if (!best) return { score: 0.5, neutral: true };
    return { score: 0.65 * best.score + 0.35 * (sum / ids.length), best: best };
  }

  /** Звучание: инструменты (ведущий весит больше) и формат. */
  function soundFit(ev, answers) {
    var ins = answers.instruments || [], fm = answers.formats || [];
    if (!ins.length && !fm.length) return { score: 0.5, neutral: true };
    var p = ev.profile, best = 0, hits = [], lead = null;
    ins.forEach(function (id) {
      var v = p.instruments[id];
      if (!v) return;
      var rarity = 1 - (ev.share[id] || 0);           // редкое совпадение весит больше
      var s = v === 2 ? 1 : 0.55 + 0.3 * rarity;
      if (s > best) best = s;
      hits.push(id);
      if (v === 2) lead = id;
    });
    var fhits = [];
    fm.forEach(function (id) {
      if ((id === 'bigband' && p.bigband) || (id === 'jam' && p.jam) || (id === 'vocalnight' && p.vocalNight)) { best = Math.max(best, 1); fhits.push(id); }
    });
    var n = hits.length + fhits.length;
    var score = n ? Math.min(1, best + 0.12 * (n - 1)) : 0;
    return { score: score, hits: hits, lead: lead, formats: fhits };
  }

  function experienceFit(ev, answers) {
    var p = ev.profile, x = 0;
    if (answers.experience === 'new') {
      if (p.hits || p.bigband || p.tribute) x += 0.6;
      if (ev.genres.indexOf('arsenal') !== -1) x += 0.4;
      if (ev.genres.indexOf('avant-garde') !== -1) x -= 0.8;
      if (p.original && !p.hits) x -= 0.3;
      if (p.jam) x -= 0.2;
    } else if (answers.experience === 'expert') {
      if (p.original) x += 0.5;
      if (p.rare || p.foreign) x += 0.4;
      if (ev.genres.indexOf('avant-garde') !== -1) x += 0.3;
      if (p.tribute) x -= 0.4;
      if (ev.genres.length === 1 && ev.genres[0] === 'pop') x -= 0.3;
    } else return { score: 0.5, neutral: true };
    return { score: clamp(0.5 + 0.5 * x, 0, 1), friendly: answers.experience === 'new' && x > 0.3 };
  }

  function companyFit(ev, answers) {
    var p = ev.profile, x = 0;
    if (answers.company === 'solo') { if (p.jam) x += 0.6; if (ev.stage === 'm' || ev.stage === 'u') x += 0.3; }
    else if (answers.company === 'pair') { if (!p.jam && p.energy <= 3.5) x += 0.4; if (p.jam) x -= 0.3; }
    else if (answers.company === 'group') { if (ev.entry === 'deposit') x += 0.5; if (p.energy >= 4) x += 0.4; if (ev.stage === 'g') x += 0.2; }
    else return { score: 0.5, neutral: true };
    return { score: clamp(0.5 + 0.5 * x, 0, 1), tables: answers.company === 'group' && ev.entry === 'deposit' };
  }

  /** Итоговый балл концерта 0..100 и разбор по факторам (для строки «почему вам»). */
  function scoreEvent(ev, answers, c) {
    var m = moodFit(ev, answers), s = soundFit(ev, answers), x = experienceFit(ev, answers), k = companyFit(ev, answers);
    var days = Math.max(0, (ev.start - c.now) / 864e5);
    var soon = Math.exp(-days / 7);
    var special = ev.profile.special ? 1 : 0;
    var online = ev.tc && ev.tcKind === 'ticket' ? 1 : 0;
    var total = WEIGHTS.mood * m.score + WEIGHTS.sound * s.score + WEIGHTS.experience * x.score +
      WEIGHTS.company * k.score + WEIGHTS.soon * soon + WEIGHTS.special * special + WEIGHTS.online * online;
    if (ev.profile.contest) total -= 15;                        // конкурс не концерт
    if (ev.profile.brunch && (answers.times || []).length && (answers.times || []).indexOf('day') === -1) total -= 10;
    return { total: total, mood: m, sound: s, exp: x, company: k, soon: soon };
  }

  /* ===================== 4. ТРИ РАЗНЫХ ВЕЧЕРА ===================== */

  /** Ключ «одного и того же шоу»: повторы программы и пятничные/субботние сеты одного артиста. */
  function showKey(ev) {
    return norm(ev.title).replace(/^(friday|saturday|sunday) night:\s*/, '').replace(/[^a-zа-я0-9]+/g, ' ').trim();
  }

  function similarityPenalty(a, chosen, answers) {
    var pen = 0, fm = (answers && answers.formats) || [];
    chosen.forEach(function (b) {
      if (showKey(a.ev) === showKey(b.ev)) pen += DIVERSITY.sameShow;
      if (a.ev.date === b.ev.date) { pen += DIVERSITY.sameDate; if (a.ev.stage === b.ev.stage) pen += DIVERSITY.sameStageSameDate; }
      if (a.ev.profile.jam && b.ev.profile.jam && fm.indexOf('jam') === -1) pen += DIVERSITY.bothJam;
      if (a.ev.profile.brunch && b.ev.profile.brunch) pen += DIVERSITY.bothBrunch;
    });
    return pen;
  }

  /** Жадный выбор: каждый следующий концерт = максимум (балл − похожесть на уже выбранные). */
  function pickDiverse(pool, k, already, answers) {
    var chosen = (already || []).slice(), out = [];
    var rest = pool.slice();
    while (out.length < k && rest.length) {
      var bi = -1, bv = -Infinity;
      for (var i = 0; i < rest.length; i++) {
        var v = rest[i].s.total - similarityPenalty(rest[i], chosen, answers);
        if (v > bv) { bv = v; bi = i; }
      }
      var it = rest.splice(bi, 1)[0];
      // одно и то же шоу второй раз не показываем вовсе
      if (chosen.some(function (c) { return showKey(c.ev) === showKey(it.ev); })) continue;
      chosen.push(it); out.push(it);
    }
    return out;
  }

  /* ===================== 5. «ПОЧЕМУ ВАМ» ===================== */

  function joinRu(list) { return list.length < 2 ? list.join('') : list.slice(0, -1).join(', ') + ' и ' + list[list.length - 1]; }
  function insLabel(id) { var i = INSTRUMENTS.filter(function (x) { return x.id === id; })[0]; return i ? i.label.toLowerCase() : id; }

  function reasonFor(it, answers, c) {
    var ev = it.ev, s = it.s, parts = [];
    if (s.mood.best && s.mood.best.score >= 0.45) {
      var g = s.mood.best.matched.filter(function (x) { return x !== 'jazz'; }).slice(0, 2).map(function (x) { return GENRE_RU[x] || x; });
      parts.push({ w: s.mood.best.score + 0.2, t: s.mood.best.mood.short + (g.length ? ': ' + joinRu(g) : '') });
    }
    if (!s.sound.neutral && s.sound.score > 0) {
      if (s.sound.formats.length) parts.push({ w: 1.1, t: s.sound.formats.indexOf('bigband') !== -1 ? 'большой оркестр' : (s.sound.formats.indexOf('jam') !== -1 ? 'живой джем-сейшн' : 'вокальный вечер') });
      if (s.sound.lead) parts.push({ w: 1, t: 'в центре ' + insLabel(s.sound.lead) });
      else if (s.sound.hits.length) parts.push({ w: 0.7, t: 'в составе ' + joinRu(s.sound.hits.slice(0, 2).map(insLabel)) });
    }
    if (s.exp.friendly) parts.push({ w: 0.8, t: 'хорошо для первого знакомства с джазом' });
    if (answers.experience === 'expert' && (ev.profile.original || ev.profile.rare)) parts.push({ w: 0.6, t: ev.profile.original ? 'авторская программа' : 'редкий состав' });
    if (ev.profile.special) parts.push({ w: 0.75, t: ev.profile.special });
    if (s.company.tables) parts.push({ w: 0.5, t: 'столик на компанию' });
    if (answers.budget && ev.price !== null && ev.price <= answers.budget) parts.push({ w: 0.35, t: ev.price === 0 ? 'вход свободный' : 'в бюджете' });
    if (!parts.length || (parts.length === 1 && parts[0].w < 0.5)) {
      // ничего не совпало явно: честно говорим, что это за вечер
      var gl = ev.genres.filter(function (x) { return x !== 'festival'; }).slice(0, 3).map(function (x) { return GENRE_RU[x] || x; });
      if (gl.length) parts.push({ w: 0.2, t: 'в программе ' + joinRu(gl) });
    }
    parts.sort(function (a, b) { return b.w - a.w; });
    var txt = parts.slice(0, 3).map(function (p) { return p.t; }).join(' · ');
    return txt ? txt.charAt(0).toUpperCase() + txt.slice(1) : '';
  }

  /* ===================== ПОДПИСИ ДАТЫ И ЦЕНЫ ===================== */

  function dateLabel(ev, c) {
    var d = +ev.date.slice(8, 10), m = +ev.date.slice(5, 7) - 1, wd = dowOf(ev.date);
    var rel = ev.date === c.today ? 'Сегодня' : (ev.date === c.tomorrow ? 'Завтра' : '');
    var time = ev.midnight ? 'полночь' : ev.time;
    return {
      rel: rel, day: d + ' ' + MONTHS[m], dayShort: d + ' ' + MONTHS_SHORT[m], wd: WD_SHORT[wd], wdFull: WD_FULL[wd], time: time,
      line: (rel ? rel + ', ' : WD_SHORT[wd] + ', ') + d + ' ' + MONTHS_SHORT[m] + ' · ' + time
    };
  }
  function priceLabel(ev) {
    if (ev.entry === 'free') return { main: 'Вход свободный', sub: '' };
    if (ev.price === null) return { main: ev.priceText || 'Цена на сайте', sub: '' };
    if (ev.entry === 'deposit') return { main: 'Депозит ' + rub(ev.price), sub: 'за гостя, идёт в счёт меню' };
    return { main: 'от ' + rub(ev.price), sub: '' };
  }

  /* ===================== ГЛАВНЫЕ ФУНКЦИИ ===================== */

  /**
   * Подбор. answers: { moods[], when, date, times[], instruments[], formats[], experience, company, budget }.
   * Возвращает три карточки, запас для «Ещё варианты» и честные пометки об ослаблениях.
   */
  function recommend(events, answers, nowMs, opts) {
    opts = opts || {};
    var c = clock(nowMs, opts.off);
    var k = opts.k || 3;
    var scored = events.map(function (ev) {
      var s = scoreEvent(ev, answers, c);
      return { ev: ev, s: s, strong: strongMatch({ ev: ev, s: s }, answers) };
    });
    var picks = [], used = {}, notes = [];
    for (var st = 0; st < ORDER.length && picks.length < k; st++) {
      var level = ORDER[st][0], strongOnly = ORDER[st][1];
      var pool = scored.filter(function (it) { return !used[it.ev.id] && it.strong === strongOnly && passes(it.ev, answers, c, level); });
      pool.sort(function (a, b) { return b.s.total - a.s.total; });
      var got = pickDiverse(pool, k - picks.length, picks, answers);
      got.forEach(function (it) { used[it.ev.id] = true; it.level = level; it.relaxed = relaxNotes(it, answers); picks.push(it); });
    }
    // запас для «Ещё варианты» в том же порядке уступок, внутри по баллу
    var base = [], inBase = {};
    for (var lv = 0; lv < ORDER.length; lv++) {
      var L = ORDER[lv][0], S = ORDER[lv][1];
      var layer = scored.filter(function (it) { return !used[it.ev.id] && !inBase[it.ev.id] && it.strong === S && passes(it.ev, answers, c, L); })
        .sort(function (a, b) { return b.s.total - a.s.total; });
      layer.forEach(function (it) { inBase[it.ev.id] = true; it.level = L; base.push(it); });
    }

    // честная пометка, если настроение в эти даты не находится
    var moodsSel = answers.moods || [];
    if (moodsSel.length && picks.length && picks[0].s.mood.score < 0.4) {
      var ml = MOODS.filter(function (m) { return moodsSel.indexOf(m.id) !== -1; }).map(function (m) { return '«' + m.label + '»'; });
      notes.push({ kind: 'mood', text: 'Под настроение ' + joinRu(ml) + ' в эти даты точных совпадений нет. Показываем лучшее из того, что есть.', action: { label: 'Искать в другие даты', patch: { when: 'any', date: null } } });
    }
    if (picks.some(function (p) { return p.relaxed.length; }) && !notes.length) {
      notes.push({ kind: 'relaxed', text: 'Точно под все ответы нашлось меньше трёх вечеров. Добавили близкие варианты и отметили, в чём они отличаются.' });
    }

    // лучший по совпадению первым, остальные по дате
    if (picks.length > 1) {
      var first = picks[0];
      var others = picks.slice(1).sort(function (a, b) { return a.ev.start - b.ev.start; });
      picks = [first].concat(others);
    }
    var out = picks.map(function (it, i) { return decorate(it, answers, c, i === 0); });
    return {
      picks: out,
      reserve: base,
      exact: countExact(scored, answers, c),
      notes: notes,
      clock: c
    };
  }

  /** Следующие варианты для кнопки «Ещё»: те же правила разнообразия. */
  function more(result, answers, nowMs, shownIds, n) {
    var c = result.clock;
    var pool = result.reserve.filter(function (it) { return shownIds.indexOf(it.ev.id) === -1; });
    var shown = result.reserve.filter(function (it) { return shownIds.indexOf(it.ev.id) !== -1; })
      .concat(result.picks.map(function (p) { return p._it; }));
    return pickDiverse(pool, n || 3, shown, answers).map(function (it) {
      it.relaxed = relaxNotes(it, answers);
      return decorate(it, answers, c, false);
    });
  }

  function decorate(it, answers, c, best) {
    return {
      _it: it, ev: it.ev, score: Math.round(it.s.total), best: best,
      date: dateLabel(it.ev, c), price: priceLabel(it.ev),
      stage: STAGES[it.ev.stage] || null,
      reason: reasonFor(it, answers, c), relaxed: it.relaxed || [],
      buy: it.ev.tc && it.ev.tcKind === 'ticket' ? 'ticket' : (it.ev.tc && it.ev.tcKind === 'registration' ? 'registration' : (it.ev.entry === 'deposit' ? 'table' : 'page'))
    };
  }

  function relaxIrrelevant(key, answers) {
    if (key === 'time') return !(answers.times || []).length;
    if (key === 'budget') return !answers.budget;
    if (key === 'date' || key === 'date2') return !answers.when || answers.when === 'any';
    return false;
  }

  /** Сколько концертов подходит точно: дата, время, бюджет, настроение и звучание. Для живого счётчика. */
  function countExact(scored, answers, c) {
    return scored.filter(function (it) { return passes(it.ev, answers, c, 0) && strongMatch(it, answers); }).length;
  }
  function strongMatch(it, answers) {
    if ((answers.moods || []).length && it.s.mood.score < MOOD_OK) return false;
    if (((answers.instruments || []).length || (answers.formats || []).length) && it.s.sound.score <= 0) return false;
    return true;
  }
  /** Концерты, которые подходят точно (для счётчика, ленты дат и превью). */
  function matches(events, answers, nowMs, opts) {
    var c = clock(nowMs, opts && opts.off), out = [];
    for (var i = 0; i < events.length; i++) {
      var ev = events[i];
      if (!passes(ev, answers, c, 0)) continue;
      var s = scoreEvent(ev, answers, c);
      if (strongMatch({ ev: ev, s: s }, answers)) out.push({ ev: ev, score: s.total });
    }
    return out;
  }
  function count(events, answers, nowMs, opts) { return matches(events, answers, nowMs, opts).length; }

  /** Ссылка на афишу с теми же фильтрами (панель фильтров понимает параметры kaf_*). */
  function afishaLink(answers, nowMs, opts) {
    var c = clock(nowMs, opts && opts.off), q = [];
    var w = answers.when;
    if (w === 'today' || w === 'tomorrow' || w === 'weekend') q.push('kaf_when=' + w);
    else if (w === 'date' && answers.date) q.push('kaf_from=' + answers.date, 'kaf_to=' + answers.date);
    else if (w === 'week') q.push('kaf_from=' + c.today, 'kaf_to=' + addDays(c.today, 6));
    // панель фильтров понимает только свои ступени цены: берём ближайшую не ниже бюджета
    if (answers.budget) {
      var steps = [1000, 1500, 2000, 3000], st = null;
      for (var si = 0; si < steps.length && st === null; si++) if (steps[si] >= answers.budget) st = steps[si];
      if (st) q.push('kaf_price=' + st);
    }
    var g = {};
    (answers.moods || []).forEach(function (id) {
      var m = MOODS.filter(function (x) { return x.id === id; })[0];
      if (m) Object.keys(m.genres).forEach(function (k) { if (m.genres[k] >= 0.8) g[k] = 1; });
    });
    var gl = Object.keys(g);
    if (gl.length) q.push('kaf_genre=' + gl.join(','));
    return '/' + (q.length ? '?' + q.join('&') : '');
  }

  return {
    version: '1.0.0',
    WEIGHTS: WEIGHTS, DIVERSITY: DIVERSITY, MOODS: MOODS, INSTRUMENTS: INSTRUMENTS, FORMATS: FORMATS,
    WHEN: WHEN, TIMES: TIMES, BUDGETS: BUDGETS, EXPERIENCE: EXPERIENCE, COMPANY: COMPANY, STAGES: STAGES, GENRE_RU: GENRE_RU,
    buildEvents: buildEvents, hydrate: hydrate, recommend: recommend, more: more, count: count, matches: matches, afishaLink: afishaLink,
    clock: clock, dateLabel: dateLabel, addDays: addDays, dowOf: dowOf, slotOf: slotOf, rub: rub,
    _internal: { profile: profile, moodScore: moodScore, scoreEvent: scoreEvent, passes: passes, parsePrice: parsePrice }
  };
});

var E = module.exports;
var CSS = "/* Kozlov Navigator: стили живут только внутри Shadow DOM и не влияют на сайт. */\n:host { all: initial; }\n.kn {\n  --ground: #f3f1ee;\n  --paper: #ffffff;\n  --ink: #0c0f17;\n  --ink-2: #2b303b;\n  --muted: #676d78;\n  --faint: #aab0b8;\n  --line: #e4e1dc;\n  --accent: #f34841;\n  --accent-press: #d9362f;\n  --accent-soft: #fde8e6;\n  --scrim: rgba(12, 15, 23, .5);\n  --st-g: #f24841; --st-m: #2d6c8c; --st-u: #5a7d38; --st-b: #e8d500;\n  --fd: \"KNAV Condensed\", \"Roboto Condensed\", \"Arial Narrow\", Roboto, Arial, sans-serif;\n  --fb: Roboto, -apple-system, \"Segoe UI\", Arial, sans-serif;\n  color: var(--ink); font: 400 15px/1.45 var(--fb); -webkit-font-smoothing: antialiased; text-align: left; letter-spacing: normal;\n}\n/* сброс с нулевой специфичностью: не перебивает стили компонентов */\n:where(.kn) *, :where(.kn) *::before, :where(.kn) *::after { box-sizing: border-box; }\n:where(.kn) :where(button, input) { font: inherit; color: inherit; margin: 0; }\n:where(.kn) button { background: none; border: 0; padding: 0; cursor: pointer; -webkit-tap-highlight-color: transparent; text-transform: none; }\n:where(.kn) a { color: inherit; }\n:where(.kn) :where(h2, h3, p) { margin: 0; }\n.kn :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }\n.kn [hidden] { display: none !important; }\n.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }\n\n/* знак: эквалайзер */\n.eq { display: inline-flex; align-items: flex-end; gap: 2px; height: 14px; }\n.eq i { display: block; width: 3px; border-radius: 1px; background: currentColor; }\n.eq i:nth-child(1) { height: 6px; } .eq i:nth-child(2) { height: 13px; } .eq i:nth-child(3) { height: 9px; } .eq i:nth-child(4) { height: 14px; }\n.eq.live i { animation: kn-eq 1.1s ease-in-out infinite; transform-origin: bottom; }\n.eq.live i:nth-child(2) { animation-delay: -.3s; } .eq.live i:nth-child(3) { animation-delay: -.6s; } .eq.live i:nth-child(4) { animation-delay: -.15s; }\n@keyframes kn-eq { 0%, 100% { transform: scaleY(.45); } 50% { transform: scaleY(1); } }\n\n/* ---------- строка входа под фильтрами ----------\n * Выглядит как вторая строка панели поиска: тот же светлый фон, скругление и высота элементов 40 px.\n * Стоит в отступе под панелью и не сдвигает подписи карточек афиши (отступы считает ui.js). */\n.strip-wrap { display: flex; align-items: center; gap: 2px; padding: 6px; border-radius: 12px; background: #f2f1ef; color: #020817; }\n.strip { flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; min-height: 40px; padding: 0 0 0 6px; border-radius: 20px; text-align: left; font: 500 15px/1.2 Roboto, Arial, sans-serif; }\n.strip .ic { width: 28px; height: 28px; border-radius: 50%; background: var(--accent); color: #fff; display: inline-flex; align-items: center; justify-content: center; flex: none; }\n.strip .ic .eq { height: 11px; gap: 1.5px; }\n.strip .ic .eq i { width: 2.5px; }\n.strip .s-t { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }\n.strip .s-t small { font: 400 14px/1.2 Roboto, Arial, sans-serif; color: #676d78; }\n.strip .s-cta { flex: none; display: inline-flex; align-items: center; gap: 6px; height: 40px; padding: 0 16px; border-radius: 20px; background: var(--accent); color: #fff; font: 500 14px/1 Roboto, Arial, sans-serif; transition: background .15s; }\n.strip:hover .s-cta { background: var(--accent-press); }\n.strip-x { flex: none; width: 34px; height: 40px; display: inline-flex; align-items: center; justify-content: center; border-radius: 20px; color: #8a8f98; transition: color .15s, background .15s; }\n.strip-x:hover { color: #020817; background: rgba(2,8,23,.05); }\n@media (max-width: 700px) { .strip .s-t small { display: none; } }\n@media (max-width: 480px) {\n  .strip { gap: 8px; padding-left: 8px; font-size: 14px; }\n  .strip .ic, .strip .s-cta .long, .strip .s-cta svg { display: none; }\n  .strip .s-cta { padding: 0 14px; }\n}\n\n/* кнопка в строке фильтров (компьютер): по размеру как фильтры панели, цвет как «Купить билет» */\n.kchip { position: relative; display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 18px 0 6px; border-radius: 20px; background: var(--accent); color: #fff;\n  font: 500 15px/1 Roboto, Arial, sans-serif; white-space: nowrap; transition: background .15s; }\n.kchip:hover { background: var(--accent-press); }\n.kchip .ic { width: 28px; height: 28px; border-radius: 50%; background: rgba(255,255,255,.2); display: inline-flex; align-items: center; justify-content: center; flex: none; }\n.kchip .ic .eq { height: 11px; gap: 1.5px; }\n.kchip .ic .eq i { width: 2.5px; }\n.kchip.short .kl2 { display: none; }\n.kchip.icon { width: 40px; padding: 0; justify-content: center; }\n.kchip.icon .kl { display: none; }\n.kchip.icon .ic { background: none; }\n/* только значок: подпись появляется при наведении */\n.kchip.icon::after { content: \"Подобрать концерт\"; position: absolute; top: calc(100% + 8px); right: 0; padding: 8px 12px; border-radius: 8px; background: #0c0f17; color: #fff;\n  font: 500 13px/1 Roboto, Arial, sans-serif; white-space: nowrap; opacity: 0; transform: translateY(-4px); pointer-events: none; transition: opacity .15s, transform .15s; z-index: 5; }\n.kchip.icon:hover::after, .kchip.icon:focus-visible::after { opacity: 1; transform: none; }\n\n/* ---------- кнопка на странице ---------- */\n.lw { position: fixed; right: calc(16px + env(safe-area-inset-right, 0px)); bottom: calc(16px + var(--lift, 0px) + env(safe-area-inset-bottom, 0px)); z-index: 99990;\n  display: flex; transition: transform .3s cubic-bezier(.2,.8,.2,1), opacity .2s ease, bottom .25s ease; }\n.launcher { position: relative; display: inline-flex; align-items: center; gap: 10px; height: 50px; padding: 0 18px 0 6px; border-radius: 999px; background: var(--ink); color: #fff;\n  font: 700 14px/1 var(--fd); letter-spacing: .09em; text-transform: uppercase; box-shadow: 0 10px 28px rgba(12,15,23,.3); }\n.launcher .lbl { white-space: nowrap; }\n/* свёрнута крестиком: язычок у правого края экрана, нажатие возвращает кнопку */\n.kmini { display: none; }\n.lw.mini { right: 0; }\n.lw.mini .launcher, .lw.mini .launcher-x, .lw.mini .hint { display: none; }\n.lw.mini .kmini { display: inline-flex; width: 30px; height: 44px; padding-left: 3px; border-radius: 22px 0 0 22px; align-items: center; justify-content: center; background: #fff; color: var(--accent);\n  box-shadow: -3px 4px 14px rgba(12,15,23,.16), 0 0 0 1px rgba(12,15,23,.08); transition: width .15s; }\n.lw.mini .kmini .eq { height: 12px; gap: 1.5px; }\n.lw.mini .kmini .eq i { width: 2.5px; }\n.lw.mini .kmini:hover { width: 36px; }\n/* крестик на кнопке: маленький значок, область нажатия 40 px */\n.launcher-x { position: absolute; top: -9px; right: -7px; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;\n  background: #fff; color: var(--ink); box-shadow: 0 2px 8px rgba(12,15,23,.22), 0 0 0 1px rgba(12,15,23,.08); transition: transform .15s, opacity .2s; }\n.launcher-x::before { content: \"\"; position: absolute; inset: -8px; border-radius: 50%; }\n.launcher-x:hover { transform: scale(1.08); }\n.launcher .ic { position: relative; width: 38px; height: 38px; border-radius: 50%; background: var(--accent); display: inline-flex; align-items: center; justify-content: center; flex: none; }\n/* пульс как у прежней кнопки: расходящееся кольцо вокруг значка и мягкое свечение */\n.launcher { animation: kn-glow 4s ease-in-out infinite; }\n.launcher .ic::after { content: \"\"; position: absolute; inset: -4px; border-radius: 50%; border: 1.5px solid var(--accent); opacity: .45; animation: kn-ring 2.4s ease-out infinite; pointer-events: none; }\n@keyframes kn-ring { 0% { transform: scale(.9); opacity: .6; } 100% { transform: scale(1.7); opacity: 0; } }\n/* телефон: всегда круглая кнопка 52 px без надписи. Размер не меняется при прокрутке, карточки закрывает минимально */\n.lw.round .launcher { width: 52px; height: 52px; padding: 0; gap: 0; justify-content: center; animation: none; box-shadow: 0 6px 18px rgba(12,15,23,.32); }\n.lw.round .launcher .lbl { display: none; }\n.lw.round .launcher .ic { width: 40px; height: 40px; }\n.lw.round .launcher-x { top: -5px; right: -5px; width: 21px; height: 21px; }\n.lw.round .launcher-x svg { width: 10px; height: 10px; }\n/* подсказка рядом с кнопкой: один раз за визит, потом исчезает сама */\n.hint { position: absolute; right: calc(100% + 12px); top: 50%; transform: translate(8px, -50%); opacity: 0; pointer-events: none; white-space: nowrap; padding: 10px 13px; border-radius: 10px;\n  background: var(--ink); color: #fff; font: 700 13px/1 var(--fd); letter-spacing: .08em; text-transform: uppercase; box-shadow: 0 8px 22px rgba(12,15,23,.25); transition: opacity .3s ease, transform .3s ease; }\n.hint::after { content: \"\"; position: absolute; right: -4px; top: 50%; width: 10px; height: 10px; margin-top: -5px; background: var(--ink); transform: rotate(45deg); border-radius: 2px; }\n.lw:not(.round) .hint { display: none; }\n.lw.hinting .hint { opacity: 1; transform: translate(0, -50%); }\n@keyframes kn-glow { 0%, 100% { box-shadow: 0 10px 28px rgba(12,15,23,.3), 0 0 0 1px rgba(243,72,65,.16) inset; } 50% { box-shadow: 0 14px 38px -4px rgba(243,72,65,.5), 0 0 0 1px rgba(243,72,65,.38) inset; } }\n.lw.away { transform: translateY(calc(100% + 40px + var(--lift, 0px) + env(safe-area-inset-bottom, 0px))); opacity: 0; pointer-events: none; }\n.lw.away * { pointer-events: none; }\n\n/* ---------- подсказка «Скрыто · Вернуть» ---------- */\n.toast { position: fixed; left: 50%; bottom: calc(18px + var(--lift, 0px) + env(safe-area-inset-bottom, 0px)); z-index: 99995; transform: translateX(-50%); width: max-content; white-space: nowrap; display: inline-flex; align-items: center; gap: 14px;\n  max-width: calc(100vw - 32px); padding: 10px 10px 10px 18px; border-radius: 999px; background: var(--ink); color: #fff; font-size: 14px; line-height: 1.25; box-shadow: 0 12px 30px rgba(12,15,23,.3);\n  animation: kn-toast .25s cubic-bezier(.2,.8,.2,1); }\n.toast button { flex: none; height: 34px; padding: 0 14px; border-radius: 999px; background: rgba(255,255,255,.12); font: 700 13px/1 var(--fd); letter-spacing: .08em; text-transform: uppercase; }\n.toast button:hover { background: rgba(255,255,255,.2); }\n@keyframes kn-toast { from { opacity: 0; transform: translate(-50%, 10px); } to { opacity: 1; transform: translate(-50%, 0); } }\n\n/* ---------- лист навигатора ---------- */\n.nav { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; background: var(--scrim); padding: 24px; }\n.panel { position: relative; width: 100%; max-width: 440px; height: min(820px, 100%); background: var(--ground); border-radius: 26px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 40px 90px -24px rgba(12,15,23,.55); }\n@media (max-width: 560px) {\n  .nav { padding: 0; background: var(--ground); }\n  .panel { max-width: none; height: 100%; border-radius: 0; box-shadow: none; }\n}\n.head { flex: none; display: flex; align-items: center; gap: 6px; padding-top: calc(8px + env(safe-area-inset-top, 0px)); padding-bottom: 6px;\n  padding-left: max(10px, env(safe-area-inset-left, 0px)); padding-right: max(10px, env(safe-area-inset-right, 0px)); }\n.icon-btn { width: 42px; height: 42px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; flex: none; transition: background .15s; }\n.icon-btn:hover { background: rgba(12,15,23,.06); }\n.icon-btn.hide { visibility: hidden; }\n.brand { flex: 1; min-width: 0; display: flex; align-items: center; justify-content: center; gap: 8px; font: 700 13px/1 var(--fd); letter-spacing: .18em; text-transform: uppercase; }\n.brand .eq { color: var(--accent); }\n.progress { flex: none; display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; padding: 4px max(20px, env(safe-area-inset-right, 0px)) 0 max(20px, env(safe-area-inset-left, 0px)); }\n.progress span { height: 3px; border-radius: 3px; background: rgba(12,15,23,.1); transition: background .3s; }\n.progress span.done { background: var(--ink); }\n.progress span.on { background: var(--accent); }\n\n.body { flex: 1; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; padding: 18px max(20px, env(safe-area-inset-right, 0px)) 28px max(20px, env(safe-area-inset-left, 0px)); }\n.eyebrow { font: 600 11.5px/1 var(--fd); letter-spacing: .18em; text-transform: uppercase; color: var(--muted); margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }\n.eyebrow::before { content: \"\"; width: 18px; height: 2px; background: var(--accent); border-radius: 2px; }\n.q { font: 700 33px/.98 var(--fd); text-transform: uppercase; letter-spacing: -.005em; text-wrap: balance; }\n.help { margin-top: 10px; color: var(--muted); font-size: 15px; line-height: 1.45; max-width: 38ch; }\n.skip-all { margin-top: 10px; font: 500 14px/1.3 var(--fb); display: inline-flex; align-items: center; gap: 6px; border-bottom: 1px solid var(--faint); padding-bottom: 2px; }\n.group { margin-top: 24px; }\n.group-t { font: 600 11.5px/1 var(--fd); letter-spacing: .16em; text-transform: uppercase; color: var(--muted); margin: 0 0 10px; display: flex; justify-content: space-between; gap: 8px; }\n\n/* настроение: фото-плитки */\n.moods { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 20px; }\n.mood { position: relative; isolation: isolate; aspect-ratio: 6 / 5; max-width: 100%; border-radius: 18px; overflow: hidden; background: var(--ink-2); color: #fff; text-align: left; }\n.mood img, .fmt img, .t-pic img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: 50% 30%; z-index: -2; }\n.mood img { transition: transform .6s cubic-bezier(.2,.8,.2,1), filter .3s; }\n.mood::after { content: \"\"; position: absolute; inset: 0; z-index: -1; background: linear-gradient(180deg, rgba(12,15,23,0) 25%, rgba(12,15,23,.82) 100%); }\n.mood:hover img { transform: scale(1.04); }\n.mood .mt { position: absolute; left: 12px; right: 12px; bottom: 11px; display: flex; flex-direction: column; gap: 3px; }\n.mood b { font: 700 19px/1 var(--fd); text-transform: uppercase; letter-spacing: .01em; text-wrap: balance; }\n.mood small { font-size: 12px; line-height: 1.25; color: rgba(255,255,255,.82); }\n.mood .lvl { position: absolute; top: 11px; left: 12px; display: inline-flex; align-items: flex-end; gap: 2px; height: 14px; }\n.mood .lvl i { display: block; width: 4px; border-radius: 1px; background: rgba(255,255,255,.35); }\n.mood .lvl i.on { background: #fff; }\n.mood .n, .fmt .n { position: absolute; top: 9px; right: 9px; height: 22px; min-width: 22px; padding: 0 7px; border-radius: 999px; background: rgba(12,15,23,.5); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); font: 600 11.5px/22px var(--fb); text-align: center; font-variant-numeric: tabular-nums; }\n.mood .ok { position: absolute; top: 8px; right: 8px; width: 26px; height: 26px; border-radius: 50%; background: var(--accent); display: none; align-items: center; justify-content: center; }\n.mood[aria-pressed=\"true\"]::before, .fmt[aria-pressed=\"true\"]::before { content: \"\"; position: absolute; inset: 0; border: 3px solid var(--accent); border-radius: inherit; z-index: 2; pointer-events: none; }\n.mood[aria-pressed=\"true\"] .ok { display: inline-flex; }\n.mood[aria-pressed=\"true\"] .n { display: none; }\n.mood[aria-pressed=\"true\"] .lvl i.on { background: var(--accent); }\n.moods.has-sel .mood[aria-pressed=\"false\"] img { filter: saturate(.25) brightness(.7); }\n\n/* чипы */\n.chips { display: flex; flex-wrap: wrap; gap: 8px; }\n.chip { min-height: 42px; padding: 8px 15px; border: 1px solid var(--line); border-radius: 999px; background: var(--paper); font: 500 14.5px/1.2 var(--fb); display: inline-flex; align-items: center; gap: 7px; transition: border-color .15s, background .15s, color .15s; }\n.chip:hover { border-color: var(--faint); }\n.chip svg { color: var(--muted); flex: none; }\n.chip .n, .chip small { color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }\n.chip[aria-pressed=\"true\"] { background: var(--ink); border-color: var(--ink); color: #fff; }\n.chip[aria-pressed=\"true\"] .n, .chip[aria-pressed=\"true\"] small { color: rgba(255,255,255,.62); }\n.chip[aria-pressed=\"true\"] svg { color: var(--accent); }\n.chip:disabled { opacity: .38; cursor: default; }\n\n/* лента дат */\n.dates { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; margin-right: calc(-1 * max(20px, env(safe-area-inset-right, 0px))); padding: 2px 20px 4px 0; scroll-snap-type: x proximity; }\n.dates::-webkit-scrollbar { display: none; }\n.day { flex: none; width: 54px; height: 80px; border-radius: 16px; background: var(--paper); border: 1px solid var(--line); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; scroll-snap-align: start; }\n.day .w { font: 600 11px/1 var(--fd); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }\n.day .d { font: 700 22px/1 var(--fd); font-variant-numeric: tabular-nums; }\n.day .dots { display: flex; gap: 3px; height: 6px; }\n.day .dots i, .legend i { width: 6px; height: 6px; border-radius: 50%; display: block; }\n.day.we .w { color: var(--accent); }\n.day.mon { margin-left: 10px; }\n.day[aria-pressed=\"true\"] { background: var(--ink); border-color: var(--ink); color: #fff; }\n.day[aria-pressed=\"true\"] .w { color: rgba(255,255,255,.65); }\n.day:disabled { opacity: .35; cursor: default; }\n.legend { display: flex; flex-wrap: wrap; gap: 4px 12px; margin-top: 8px; font-size: 12px; color: var(--muted); }\n.legend span { display: inline-flex; align-items: center; gap: 5px; }\n\n/* форматы */\n.fmts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }\n.fmt { position: relative; isolation: isolate; aspect-ratio: 1 / 1.1; max-width: 100%; border-radius: 16px; overflow: hidden; background: var(--ink-2); color: #fff; text-align: left; }\n.fmt::after { content: \"\"; position: absolute; inset: 0; z-index: -1; background: linear-gradient(180deg, rgba(12,15,23,0) 30%, rgba(12,15,23,.85) 100%); }\n.fmt b { position: absolute; left: 10px; right: 8px; bottom: 9px; font: 700 14.5px/1.02 var(--fd); text-transform: uppercase; }\n.fmt .n { top: 7px; right: 7px; height: 20px; line-height: 20px; font-size: 11px; }\n.fmt:disabled { opacity: .4; cursor: default; }\n.fmt:disabled img { filter: grayscale(1); }\n\n/* шаг 4: сегменты с бегунком */\n.seg { position: relative; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); background: var(--paper); border: 1px solid var(--line); border-radius: 18px; padding: 4px; }\n.seg .thumb { position: absolute; top: 4px; bottom: 4px; left: 4px; width: calc((100% - 8px) / 3); border-radius: 14px; background: var(--ink); transition: transform .32s cubic-bezier(.2,.8,.2,1), opacity .2s; opacity: 0; }\n.seg[data-i=\"0\"] .thumb { opacity: 1; transform: translateX(0); }\n.seg[data-i=\"1\"] .thumb { opacity: 1; transform: translateX(100%); }\n.seg[data-i=\"2\"] .thumb { opacity: 1; transform: translateX(200%); }\n.seg button { position: relative; z-index: 1; min-height: 64px; border-radius: 14px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; transition: color .2s; }\n.seg button svg { color: var(--faint); transition: color .2s; }\n.seg button b { font: 700 14px/1 var(--fd); text-transform: uppercase; letter-spacing: .04em; }\n.seg button[aria-pressed=\"true\"] { color: #fff; }\n.seg button[aria-pressed=\"true\"] svg { color: var(--accent); }\n.seg-hint { margin-top: 8px; font-size: 12.5px; color: var(--muted); min-height: 1.3em; }\n\n/* бюджет: слайдер с гистограммой цен афиши */\n.bud { background: var(--paper); border: 1px solid var(--line); border-radius: 18px; padding: 16px 16px 12px; }\n.bud-top { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; }\n.bud-v { font: 700 28px/1 var(--fd); font-variant-numeric: tabular-nums; }\n.bud-c { font-size: 12.5px; color: var(--muted); text-align: right; }\n.hist { display: flex; align-items: flex-end; gap: 3px; height: 54px; margin-top: 14px; padding: 0 9px; }\n.hist i { flex: 1; min-height: 2px; border-radius: 3px 3px 0 0; background: var(--accent); transition: background .15s, opacity .15s; }\n.hist i.out { background: var(--line); }\n.range { -webkit-appearance: none; appearance: none; width: 100%; height: 28px; background: transparent; margin: 0; display: block; cursor: pointer; }\n.range:focus { outline: none; }\n.range::-webkit-slider-runnable-track { height: 4px; border-radius: 4px; background: linear-gradient(90deg, var(--accent) var(--p, 100%), var(--line) var(--p, 100%)); }\n.range::-moz-range-track { height: 4px; border-radius: 4px; background: var(--line); }\n.range::-moz-range-progress { height: 4px; border-radius: 4px; background: var(--accent); }\n.range::-webkit-slider-thumb { -webkit-appearance: none; width: 26px; height: 26px; margin-top: -11px; border-radius: 50%; background: #fff; border: 2px solid var(--accent); box-shadow: 0 4px 12px rgba(12,15,23,.25); }\n.range::-moz-range-thumb { width: 22px; height: 22px; border-radius: 50%; background: #fff; border: 2px solid var(--accent); box-shadow: 0 4px 12px rgba(12,15,23,.25); }\n.range:focus-visible::-webkit-slider-thumb { box-shadow: 0 0 0 4px var(--accent-soft), 0 4px 12px rgba(12,15,23,.25); }\n.ticks { display: flex; justify-content: space-between; font: 600 11px/1 var(--fd); letter-spacing: .06em; color: var(--faint); padding: 2px 4px 0; font-variant-numeric: tabular-nums; }\n\n/* нижняя панель */\n.foot { flex: none; display: flex; align-items: center; gap: 12px; background: var(--paper); box-shadow: 0 -1px 0 var(--line);\n  padding: 12px max(16px, env(safe-area-inset-right, 0px)) calc(12px + env(safe-area-inset-bottom, 0px)) max(16px, env(safe-area-inset-left, 0px)); }\n.counter { flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; }\n.thumbs { display: flex; flex: none; }\n.thumbs img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid var(--paper); background: var(--ground); display: block; }\n.thumbs img + img { margin-left: -12px; }\n.cnt-t { min-width: 0; line-height: 1.15; }\n.cnt-t b { display: block; font: 700 22px/1 var(--fd); font-variant-numeric: tabular-nums; }\n.cnt-t span { font-size: 12px; color: var(--muted); }\n.cnt-t.zero b { font: 700 14px/1.2 var(--fd); text-transform: uppercase; letter-spacing: .03em; }\n.bump { animation: kn-bump .25s ease-out; }\n@keyframes kn-bump { 0% { transform: translateY(3px); opacity: .4; } 100% { transform: none; opacity: 1; } }\n.primary { height: 52px; padding: 0 20px 0 22px; border-radius: 999px; background: var(--accent); color: #fff; font: 700 15px/1 var(--fd); letter-spacing: .1em; text-transform: uppercase; display: inline-flex; align-items: center; gap: 8px; flex: none; transition: background .15s, transform .1s; box-shadow: 0 8px 20px -8px rgba(243,72,65,.7); }\n.primary:hover { background: var(--accent-press); }\n.primary:active { transform: scale(.97); }\n.primary.ghost { background: var(--paper); color: var(--ink); box-shadow: inset 0 0 0 1.5px var(--line); }\n\n/* подборка: билеты */\n.answers { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; margin: 14px calc(-1 * max(20px, env(safe-area-inset-right, 0px))) 0 0; padding: 0 20px 2px 0; }\n.answers::-webkit-scrollbar { display: none; }\n.answers .chip { min-height: 32px; padding: 5px 12px; font-size: 13px; flex: none; }\n.answers .chip.edit { background: none; border-style: dashed; }\n.note { margin-top: 14px; background: var(--accent-soft); border-radius: 14px; padding: 12px 14px; font-size: 14px; line-height: 1.42; }\n.note button { margin-top: 8px; font: 700 12.5px/1 var(--fd); letter-spacing: .1em; text-transform: uppercase; color: var(--accent-press); }\n.cards { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; }\n.ticket { position: relative; background: var(--paper); border-radius: 20px; }\n.t-pic { position: relative; isolation: isolate; aspect-ratio: 3 / 2; max-width: 100%; border-radius: 20px 20px 0 0; overflow: hidden; background: var(--ink-2); color: #fff; }\n.t-pic img { object-position: 50% 28%; }\n.t-pic::after { content: \"\"; position: absolute; inset: 0; z-index: -1; background: linear-gradient(180deg, rgba(12,15,23,.25) 0%, rgba(12,15,23,0) 28%, rgba(12,15,23,0) 45%, rgba(12,15,23,.85) 100%); }\n.t-top { position: absolute; top: 12px; left: 12px; right: 12px; display: flex; gap: 6px; flex-wrap: wrap; }\n.badge { height: 26px; padding: 0 10px; border-radius: 999px; display: inline-flex; align-items: center; gap: 6px; font: 700 11.5px/1 var(--fd); letter-spacing: .1em; text-transform: uppercase; }\n.badge.best { background: var(--accent); color: #fff; }\n.badge.rel { background: #fff; color: var(--ink); }\n.t-cap { position: absolute; left: 16px; right: 16px; bottom: 14px; display: flex; flex-direction: column; gap: 6px; }\n.t-stage { display: inline-flex; align-items: center; gap: 6px; font: 600 12px/1 var(--fd); letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,.85); }\n.dot { width: 8px; height: 8px; border-radius: 50%; flex: none; box-shadow: 0 0 0 2px rgba(255,255,255,.25); }\n.t-title { font: 700 25px/1 var(--fd); text-transform: uppercase; text-wrap: balance; }\n.t-title a, .r-title a { text-decoration: none; }\n.perf { position: relative; height: 0; margin: 0 18px; border-top: 2px dashed var(--line); }\n.perf::before, .perf::after { content: \"\"; position: absolute; top: -11px; width: 20px; height: 20px; border-radius: 50%; background: var(--ground); }\n.perf::before { left: -29px; } .perf::after { right: -29px; }\n.stub { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 12px; }\n.stub-row { display: flex; align-items: center; gap: 14px; }\n.t-date { display: flex; align-items: center; gap: 8px; flex: none; }\n.t-date b { font: 700 40px/.85 var(--fd); font-variant-numeric: tabular-nums; letter-spacing: -.02em; }\n.t-date span { font: 700 12.5px/1.15 var(--fd); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }\n.t-date.rel b, .t-date.rel span { color: var(--accent); }\n.vr { width: 1px; align-self: stretch; background: var(--line); }\n.t-time { flex: 1; min-width: 0; }\n.t-time b { display: block; font: 700 20px/1 var(--fd); font-variant-numeric: tabular-nums; text-transform: uppercase; }\n.t-time span { font-size: 12px; color: var(--muted); }\n.t-price { text-align: right; flex: none; }\n.t-price b { display: block; font: 700 18px/1.05 var(--fd); font-variant-numeric: tabular-nums; }\n.t-price span { font-size: 11.5px; color: var(--muted); }\n.why { display: flex; gap: 8px; align-items: flex-start; font-size: 14px; line-height: 1.4; }\n.why .sp { flex: none; margin-top: 1px; color: var(--accent); }\n.tags { display: flex; flex-wrap: wrap; gap: 5px; }\n.tag { font: 600 10.5px/1 var(--fd); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); border: 1px dashed var(--faint); border-radius: 999px; padding: 5px 9px; }\n.cta { height: 50px; width: 100%; border-radius: 999px; background: var(--accent); color: #fff; font: 700 15px/1 var(--fd); letter-spacing: .1em; text-transform: uppercase; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; transition: background .15s; }\n.cta:hover { background: var(--accent-press); }\n.cta.alt { background: var(--ink); }\n.cta.ghost { background: var(--paper); color: var(--ink); box-shadow: inset 0 0 0 1.5px var(--ink); }\n.ticket.row { display: grid; grid-template-columns: 104px 0 minmax(0, 1fr); border-radius: 18px; }\n.ticket.row .t-pic { aspect-ratio: auto; height: 100%; min-height: 132px; border-radius: 18px 0 0 18px; }\n.ticket.row .t-pic::after { background: linear-gradient(180deg, rgba(12,15,23,0) 50%, rgba(12,15,23,.6) 100%); }\n.t-d { position: absolute; left: 10px; bottom: 9px; color: #fff; display: flex; flex-direction: column; }\n.t-d b { font: 700 28px/.9 var(--fd); font-variant-numeric: tabular-nums; }\n.t-d span { font: 700 11px/1.2 var(--fd); letter-spacing: .1em; text-transform: uppercase; opacity: .85; }\n.vperf { position: relative; border-left: 2px dashed var(--line); margin: 14px 0; }\n.vperf::before, .vperf::after { content: \"\"; position: absolute; left: -11px; width: 20px; height: 20px; border-radius: 50%; background: var(--ground); }\n.vperf::before { top: -25px; } .vperf::after { bottom: -25px; }\n.r-in { padding: 12px 14px; display: flex; flex-direction: column; gap: 5px; min-width: 0; }\n.r-meta { font: 600 11.5px/1.2 var(--fd); letter-spacing: .1em; text-transform: uppercase; color: var(--muted); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }\n.r-meta .dot { box-shadow: none; width: 7px; height: 7px; }\n.r-meta .rl { color: var(--accent); }\n.r-title { font: 700 17px/1.05 var(--fd); text-transform: uppercase; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }\n.ticket.row .why { font-size: 12.5px; color: var(--muted); line-height: 1.35; }\n.ticket.row .why span { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }\n.r-buy { margin-top: auto; padding-top: 4px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }\n.r-buy b { font: 700 15px/1 var(--fd); font-variant-numeric: tabular-nums; }\n.r-buy .cta { width: auto; height: 36px; padding: 0 14px; font-size: 12.5px; }\n.more { width: 100%; height: 50px; margin-top: 14px; border-radius: 999px; background: var(--paper); box-shadow: inset 0 0 0 1.5px var(--ink); font: 700 14px/1 var(--fd); letter-spacing: .12em; text-transform: uppercase; }\n.links { margin-top: 18px; display: flex; flex-direction: column; gap: 12px; align-items: flex-start; }\n.links a, .links button { font: 500 14px/1.3 var(--fb); text-decoration: underline; text-underline-offset: 3px; text-decoration-color: var(--faint); }\n\n/* лист: телефон или переход к покупке */\n.sheet-wrap { position: absolute; inset: 0; background: var(--scrim); display: flex; align-items: flex-end; z-index: 5; }\n.sheet { width: 100%; background: var(--paper); border-radius: 24px 24px 0 0; padding: 22px max(20px, env(safe-area-inset-right, 0px)) calc(18px + env(safe-area-inset-bottom, 0px)) max(20px, env(safe-area-inset-left, 0px)); display: flex; flex-direction: column; gap: 12px; }\n.sheet h3 { font: 700 24px/1.02 var(--fd); text-transform: uppercase; }\n.sheet p { color: var(--muted); font-size: 14px; }\n.tel { display: flex; align-items: center; justify-content: space-between; gap: 12px; border: 1px solid var(--line); border-radius: 16px; padding: 10px 10px 10px 16px; }\n.tel b { font: 700 22px/1 var(--fd); letter-spacing: .03em; -webkit-user-select: all; user-select: all; font-variant-numeric: tabular-nums; }\n.sheet .close-s { align-self: center; font: 500 14px/1 var(--fb); color: var(--muted); padding: 8px; }\n.err { margin-top: 20px; }\n\n.fade { animation: kn-fade .28s cubic-bezier(.2,.8,.2,1); }\n@keyframes kn-fade { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }\n@media (prefers-reduced-motion: reduce) {\n  .fade, .bump, .eq.live i, .launcher, .launcher .ic::after, .toast { animation: none; }\n  .kn * { transition: none !important; }\n  .lw.away { transform: none; }\n}\n@media (max-width: 360px) { .q { font-size: 28px; } .mood b { font-size: 17px; } .t-title { font-size: 22px; } .primary { padding: 0 16px; } }\n";
/*
 * Kozlov Navigator: интерфейс. Работает внутри Shadow DOM, сайт не трогает.
 * Данные берёт из индекса Kozlov Smart Search, подбор считает engine.js (E).
 * Ожидает: E (движок), CSS (строка стилей), document.getElementById('knav-config') с JSON-настройками.
 */
function knavStart(E, CSS) {
  'use strict';
  var cfgEl = document.getElementById('knav-config');
  var cfg = {};
  try { cfg = JSON.parse(cfgEl ? cfgEl.textContent : '{}') || {}; } catch (e) { return; }
  if (window.__knavStarted) return;
  window.__knavStarted = true;

  var MODE = cfg.mode === 'prebuilt' ? 'prebuilt' : 'kss';
  var PHONE = cfg.phone || '+7 (495) 296-12-00';
  var STAGE_COLOR = { g: 'var(--st-g)', m: 'var(--st-m)', u: 'var(--st-u)', b: 'var(--st-b)' };
  var STAGE_ORDER = ['g', 'm', 'u', 'b'];
  var STEPS = [
    { key: 'mood', q: 'Какое настроение?', help: 'Выберите одно или два. Подберём три вечера из афиши клуба.' },
    { key: 'when', q: 'Когда удобно?', help: 'День или конкретная дата. Время суток можно не выбирать.' },
    { key: 'sound', q: 'Что хочется услышать?', help: 'Необязательно. Формат или до трёх инструментов.' },
    { key: 'about', q: 'Пара деталей', help: 'Подборка станет точнее. Любой вопрос можно пропустить.' }
  ];
  var ENERGY = { drive: 5, hits: 4, deep: 3, surprise: 3, romance: 2, calm: 1 };
  var BUD = { min: 500, max: 4500, step: 100 };   // max на слайдере означает «любой бюджет»
  var YM_GOALS = { open: 1, results: 1, buy_click: 1, table_click: 1 };
  var MONTH_SHORT = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
  var WD = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
  var WD_GEN = ['воскресенья', 'понедельника', 'вторника', 'среды', 'четверга', 'пятницы', 'субботы'];
  var WD_ACC = ['воскресенье', 'понедельник', 'вторник', 'среду', 'четверг', 'пятницу', 'субботу'];
  function EMPTY() { return { moods: [], when: null, date: null, times: [], instruments: [], formats: [], experience: null, company: null, budget: 0 }; }

  var state = { events: [], off: 10800, imgBase: '', evBase: '/event/', ready: false, loading: null, failed: false,
    open: false, step: 0, editing: false, a: EMPTY(), result: null, shown: [], extra: [], moodImg: {}, fmtImg: {}, lastCount: null };

  /* ---------- утилиты ---------- */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function plural(n, one, few, many) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
    return many;
  }
  function now() { return Date.now(); }
  function with_(patch) { var o = {}, k; for (k in state.a) o[k] = state.a[k]; for (k in patch) o[k] = patch[k]; return o; }
  function opt() { return { off: state.off }; }
  function count(ans) { return E.count(state.events, ans, now(), opt()); }
  function toggle(list, v, max) {
    if (list.indexOf(v) !== -1) return list.filter(function (x) { return x !== v; });
    var out = list.concat([v]);
    return max && out.length > max ? out.slice(out.length - max) : out;
  }
  function rub(n) { return E.rub(n); }

  function imgUrl(ev, size) {
    if (!ev || !ev.img) return '';
    if (MODE === 'prebuilt') return ev.img;
    var base = state.imgBase || '/wp-content/uploads/';
    var big = ev.img.replace(/-\d+x\d+(\.\w+)$/, '-768x576$1');
    if (size === 'big' && ev.webp === 1) return encodeURI(base + big + '.webp');
    if (size === 'big' && /\.jpe?g$/i.test(ev.img)) return encodeURI(base + big); // jpg 768 лёгкий; png 768 тяжёлый, его не берём
    if (ev.webp === 1) return encodeURI(base + ev.img + '.webp');
    return encodeURI(base + ev.img);
  }
  function img(ev, size) {
    var src = imgUrl(ev, size);
    if (!src) return '';
    var fb = MODE === 'prebuilt' ? '' : encodeURI((state.imgBase || '/wp-content/uploads/') + ev.img);
    return '<img src="' + esc(src) + '" alt="" loading="lazy" decoding="async"' + (fb && fb !== src ? ' data-fb="' + esc(fb) + '"' : '') + '>';
  }
  function eventUrl(ev) { return (cfg.site || '') + (state.evBase || '/event/') + encodeURIComponent(ev.id) + '/'; }

  /* ---------- статистика: Метрика и своя страница в админке ---------- */
  var sid = '';
  try { sid = sessionStorage.getItem('knav_sid') || ''; } catch (e) { }
  if (!sid) {
    sid = (Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).slice(0, 20);
    try { sessionStorage.setItem('knav_sid', sid); } catch (e) { }
  }
  function track(ev, data) {
    try { if (cfg.ym && YM_GOALS[ev] && typeof window.ym === 'function') window.ym(+cfg.ym, 'reachGoal', 'nav_' + ev, data || {}); } catch (e) { }
    if (!cfg.track) { try { if (cfg.debug) console.info('[navigator]', ev, data || ''); } catch (e) { } return; }
    try {
      var body = JSON.stringify({ sid: sid, ev: ev, d: data || {} });
      if (navigator.sendBeacon && navigator.sendBeacon(cfg.track, new Blob([body], { type: 'application/json' }))) return;
      fetch(cfg.track, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'omit' }).catch(function () { });
    } catch (e) { }
  }
  function compactAnswers(a) {
    return { m: a.moods, w: a.when, d: a.date, t: a.times, i: a.instruments, f: a.formats, x: a.experience, c: a.company, b: a.budget };
  }

  /* ---------- шрифт заголовков (свой, под своим именем) ---------- */
  (function fonts() {
    if (!cfg.fonts || !window.FontFace || !document.fonts) return;
    var ranges = {
      cyrillic: 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116',
      latin: 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'
    };
    ['600', '700'].forEach(function (w) {
      ['cyrillic', 'latin'].forEach(function (r) {
        try {
          var f = new FontFace('KNAV Condensed', 'url(' + cfg.fonts + 'roboto-condensed-' + r + '-' + w + '-normal.woff2) format("woff2")', { weight: w, style: 'normal', unicodeRange: ranges[r], display: 'swap' });
          document.fonts.add(f); f.load().catch(function () { });
        } catch (e) { }
      });
    });
  })();

  /* ---------- разметка: всё внутри теневого корня ---------- */
  function makeRoot(host) {
    var root = host.attachShadow({ mode: 'open' });
    var st = document.createElement('style');
    st.textContent = CSS;
    root.appendChild(st);
    return root;
  }
  var EQ = '<span class="eq live"><i></i><i></i><i></i><i></i></span>';
  var ICON = {
    check: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    arrow: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    back: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 5l-7 7 7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    close: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    spark: '<svg class="sp" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.9 6.1L20 10l-6.1 1.9L12 18l-1.9-6.1L4 10l6.1-1.9z"/><circle cx="19" cy="19" r="2"/></svg>',
    pen: '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>',
    copy: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3" stroke="currentColor" stroke-width="2"/></svg>',
    day: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    evening: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 16a6 6 0 0 1 12 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M3 20h18M12 4v3M4.9 8.9l2 2M19.1 8.9l-2 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    night: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>'
  };
  function notes(n) {
    var x = '', w = 8 + (n - 1) * 9;
    for (var i = 0; i < n; i++) x += '<g transform="translate(' + (i * 9) + ' 0)"><ellipse cx="4" cy="17" rx="3.4" ry="2.6" fill="currentColor"/><path d="M7.2 17V4.5" stroke="currentColor" stroke-width="1.8"/></g>';
    return '<svg width="' + w + '" height="22" viewBox="0 0 ' + w + ' 22" aria-hidden="true">' + x + '</svg>';
  }
  function people(n) {
    var x = '', w = 14 + (n - 1) * 11;
    for (var i = 0; i < n; i++) x += '<g transform="translate(' + (i * 11) + ' 0)"><circle cx="7" cy="6" r="3.4" fill="currentColor"/><path d="M1.5 20a5.5 5.5 0 0 1 11 0z" fill="currentColor"/></g>';
    return '<svg width="' + w + '" height="22" viewBox="0 0 ' + w + ' 22" aria-hidden="true">' + x + '</svg>';
  }

  /* ---------- посетитель скрыл плашку или кнопку крестиком ----------
   * Выбор хранится в браузере посетителя (localStorage) cfg.dismissDays дней, потом подсказки возвращаются.
   * Вернуть у себя сразу: открыть любую страницу с ?knav_show=1 в адресе. */
  var DISMISS = cfg.dismiss !== false, DISMISS_DAYS = Math.max(1, +cfg.dismissDays || 30), HKEY = 'knav_hidden';
  var XSVG = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"/></svg>';
  try { if (/[?&]knav_show=1\b/.test(location.search)) localStorage.removeItem(HKEY); } catch (e) { }
  function hiddenMap() { try { var m = JSON.parse(localStorage.getItem(HKEY) || '{}'); return m && typeof m === 'object' ? m : {}; } catch (e) { return {}; } }
  // 'entry': вход у фильтров с версии 1.0.4. Скрытая раньше тёмная полоса ('strip') новую кнопку не прячет.
  function isHidden(what) { if (!DISMISS) return false; var t = +hiddenMap()[what]; return !!t && now() - t < DISMISS_DAYS * 864e5; }
  function setHidden(what, on) { try { var m = hiddenMap(); if (on) m[what] = now(); else delete m[what]; localStorage.setItem(HKEY, JSON.stringify(m)); } catch (e) { } }

  var host = document.createElement('div');
  host.id = 'knav-root';
  document.body.appendChild(host);
  var R = makeRoot(host);
  var wrap = document.createElement('div');
  wrap.className = 'kn';
  wrap.innerHTML =
    (cfg.launcher === false ? '' : '<div class="lw away"><button type="button" class="launcher" data-act="open" aria-label="Джаз-навигатор: подобрать концерт"><span class="ic">' + EQ + '</span><span class="lbl">Подобрать концерт</span></button>' +
      (DISMISS ? '<button type="button" class="launcher-x" data-act="hide-launcher" aria-label="Свернуть кнопку навигатора">' + XSVG + '</button>' +
        '<button type="button" class="kmini" data-act="restore-launcher" aria-label="Вернуть кнопку «Подобрать концерт»" title="Вернуть кнопку «Подобрать концерт»"><span class="eq"><i></i><i></i><i></i><i></i></span></button>' : '') +
      '<span class="hint" aria-hidden="true">Подобрать концерт</span></div>') +
    '<div class="nav" role="dialog" aria-modal="true" aria-labelledby="knav-q" hidden><div class="panel">' +
    '<div class="head"><button type="button" class="icon-btn hide" data-act="back" aria-label="Назад">' + ICON.back + '</button>' +
    '<div class="brand"><span class="eq"><i></i><i></i><i></i><i></i></span>Джаз-навигатор</div>' +
    '<button type="button" class="icon-btn" data-act="close" aria-label="Закрыть">' + ICON.close + '</button></div>' +
    '<div class="progress" aria-hidden="true"><span></span><span></span><span></span><span></span></div>' +
    '<div class="body"></div><div class="foot"></div><div class="sheet-box"></div></div></div>' +
    '<div class="sr" aria-live="polite"></div>';
  R.appendChild(wrap);
  var $ = function (sel) { return R.querySelector(sel); };
  var navEl = $('.nav'), body = $('.body'), foot = $('.foot'), sheetBox = $('.sheet-box'), btnBack = $('[data-act=back]'), progress = $('.progress'), live = $('.sr'), launcherEl = $('.lw');
  // свёрнута крестиком: в углу остаётся маленький значок, нажатие на него возвращает кнопку
  var mini = !!(launcherEl && isHidden('launcher'));
  if (mini) launcherEl.classList.add('mini');

  /* ---------- вход у фильтров афиши ----------
   * Компьютер: красная кнопка «Подобрать концерт» в той же строке, что фильтры, сразу после «Сцена»
   * (вставляется в панель Smart Search своим Shadow DOM, стили панели не трогает). Если места мало,
   * надпись короче («Подобрать»), ещё меньше места: только значок. Телефон (уже 720 px): строка под панелью. */
  var stripHost = null, stripOff = false, stripAnchor = null, chipHost = null, inlineOn = false, bootAt = now();
  if (cfg.strip !== false && cfg.stripAfter && !isHidden('entry')) {
    try { stripAnchor = document.querySelector(cfg.stripAfter); } catch (e) { }
    if (stripAnchor && stripAnchor.parentNode) {
      stripHost = document.createElement('div');
      stripHost.className = 'knav-strip-host';
      stripHost.style.display = window.innerWidth >= 720 ? 'none' : 'block'; // на компьютере сначала пробуем строку фильтров
      stripAnchor.parentNode.insertBefore(stripHost, stripAnchor.nextSibling);
      var SR = makeRoot(stripHost);
      var sw = document.createElement('div');
      sw.className = 'kn';
      sw.innerHTML = '<div class="strip-wrap"><button type="button" class="strip" data-act="open"><span class="ic">' + EQ + '</span>' +
        '<span class="s-t">Не знаете, что выбрать?<small> Подберём концерт под настроение за 30 секунд</small></span>' +
        '<span class="s-cta">Подобрать<span class="long"> концерт</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span></button>' +
        (DISMISS ? '<button type="button" class="strip-x" data-act="hide-strip" aria-label="Скрыть подсказку навигатора"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></button>' : '') + '</div>';
      SR.appendChild(sw);
      SR.addEventListener('click', function (e) {
        if (e.target.closest('[data-act=hide-strip]')) hideStrip();
        else if (e.target.closest('[data-act=open]')) open('strip');
      });
      SR.addEventListener('pointerenter', loadData, true);
      placeEntry();
      [300, 1000, 2500, 3200].forEach(function (t) { setTimeout(placeEntry, t); });
      window.addEventListener('load', schedulePlace);
      window.addEventListener('resize', schedulePlace, { passive: true });
      if (window.ResizeObserver) { try { new ResizeObserver(schedulePlace).observe(stripAnchor); } catch (e) { } }
    }
  }

  var placeT = 0, kafObserved = false;
  function schedulePlace() { clearTimeout(placeT); placeT = setTimeout(placeEntry, 80); }
  function kafRow() {
    var sr = stripAnchor && stripAnchor.shadowRoot;
    if (!sr) return null;
    var bar = sr.querySelector('.kaf-bar'), chips = sr.querySelector('.kaf-chips');
    if (!bar || !chips) return null;
    var last = chips.lastElementChild;
    if (last && last === chipHost) last = last.previousElementSibling;
    if (!last) return null;
    if (!kafObserved && window.MutationObserver) {
      kafObserved = true; // счётчики в фильтрах заполняются позже и меняют ширину: проверяем, помещается ли кнопка
      try { new MutationObserver(schedulePlace).observe(chips, { childList: true, subtree: true, characterData: true }); } catch (e) { }
    }
    return { bar: bar, chips: chips, last: last };
  }
  function makeChip() {
    chipHost = document.createElement('span');
    chipHost.className = 'knav-chip-host';
    chipHost.style.cssText = 'display:flex;flex:0 0 auto;align-self:center;margin:0;padding:0;border:0;background:none;min-width:0';
    var CR = makeRoot(chipHost);
    var cw = document.createElement('div');
    cw.className = 'kn';
    cw.innerHTML = '<button type="button" class="kchip" data-act="open" aria-label="Не знаете, что выбрать? Подобрать концерт">' +
      '<span class="ic">' + EQ + '</span><span class="kl">Подобрать<span class="kl2"> концерт</span></span></button>';
    CR.appendChild(cw);
    CR.addEventListener('click', function (e) { if (e.target.closest('[data-act=open]')) open('chip'); });
    CR.addEventListener('pointerenter', loadData, true);
  }
  function placeEntry() {
    if (!stripAnchor || stripOff) return;
    var wide = window.innerWidth >= 720, row = wide ? kafRow() : null;
    if (wide && !row && now() - bootAt < 3000) return; // панель фильтров ещё рисуется: подождём, а не покажем строку зря
    if (row) {
      if (!chipHost) makeChip();
      /* Варианты по порядку: отдельным элементом в строке панели (полная надпись, короче, значок),
       * затем последним фильтром в группе фильтров, если они переносятся на вторую строку.
       * Подходит вариант, при котором кнопка стоит в одной строке со «Сцена» и панель не стала выше. */
      if (chipHost.parentNode) chipHost.parentNode.removeChild(chipHost);
      var h0 = row.bar.getBoundingClientRect().height;
      var btn = chipHost.shadowRoot.querySelector('.kchip'), fit = false;
      var tries = [[row.bar, ''], [row.bar, 'short'], [row.bar, 'icon'], [row.chips, ''], [row.chips, 'short']];
      for (var i = 0; i < tries.length && !fit; i++) {
        if (tries[i][0] === row.bar) row.bar.insertBefore(chipHost, row.chips.nextSibling); else row.chips.appendChild(chipHost);
        btn.className = 'kchip' + (tries[i][1] ? ' ' + tries[i][1] : '');
        fit = Math.abs(row.last.getBoundingClientRect().top - chipHost.getBoundingClientRect().top) < 6 && row.bar.getBoundingClientRect().height <= h0 + 1;
      }
      if (!fit) { row.bar.insertBefore(chipHost, row.chips.nextSibling); btn.className = 'kchip'; } // места нет: своей строкой внутри панели, слева
      inlineOn = true;
      stripHost.style.display = 'none';
      return;
    }
    if (chipHost && chipHost.parentNode) chipHost.parentNode.removeChild(chipHost);
    inlineOn = false;
    stripHost.style.display = 'block';
    layoutStrip();
  }

  /* Место для строки. Под панелью фильтров афиша оставляет пустой отступ: в нём стоят подписи над карточками
   * («Концерт сегодня | Сцена») и заголовок месяца. Строка встаёт сразу под видимой частью панели (8 px),
   * а этот отступ целиком переносится под неё, поэтому подписи остаются на своих местах. */
  function contentBottom(el, ar) {
    var max = -1, n = 0, roots = [el];
    if (el.shadowRoot) roots.push(el.shadowRoot);
    for (var k = 0; k < roots.length; k++) {
      var list = roots[k].querySelectorAll('*');
      for (var i = 0; i < list.length && n < 500; i++, n++) {
        var r = list[i].getBoundingClientRect();
        if (!r.width || !r.height || r.height >= ar.height - 1 || r.bottom > ar.bottom + 1) continue;
        var cs = getComputedStyle(list[i]);
        if (cs.position === 'fixed' || cs.visibility === 'hidden') continue;
        if (r.bottom > max) max = r.bottom;
      }
    }
    return max < 0 ? ar.bottom : max;
  }
  function layoutStrip() {
    if (!stripHost || !stripAnchor || stripOff) return;
    var hs = stripHost.style;
    hs.marginTop = '0px'; hs.marginBottom = '0px';
    var ar = stripAnchor.getBoundingClientRect();
    if (!ar.height) return;
    var reserved = Math.max(0, Math.min(140, Math.round(ar.bottom - contentBottom(stripAnchor, ar))));
    hs.marginTop = (8 - reserved) + 'px';
    hs.marginBottom = reserved + 'px';
  }

  /* ---------- данные ---------- */
  function loadData() {
    if (state.ready || state.loading) return state.loading;
    var bucket = Math.floor(now() / 3e5); // тот же ключ кэша, что у Smart Search: индекс берётся из кэша браузера
    var q = function (u) { return u + (u.indexOf('?') < 0 ? '?' : '&') + 'kss_t=' + bucket; };
    var get = function (u) { return fetch(q(u), { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); };
    var p = MODE === 'prebuilt'
      ? fetch(cfg.data).then(function (r) { return r.json(); }).then(function (d) { state.off = d.off || 10800; state.events = E.hydrate(d.events); })
      : Promise.all([get(cfg.index), get(cfg.texts).catch(function () { return null; })]).then(function (res) {
        var idx = res[0];
        if (!idx || !idx.ev) throw new Error('index');
        state.off = idx.off || 10800; state.imgBase = idx.imgBase || '/wp-content/uploads/'; state.evBase = idx.evBase || '/event/';
        state.events = E.buildEvents(idx, res[1]);
      });
    state.loading = p.then(function () { state.ready = true; pickImages(); if (state.open) rerender(); })
      .catch(function () { state.failed = true; if (state.open) rerender(); });
    return state.loading;
  }

  /* фото для плиток настроения и форматов берутся из самой афиши */
  function pickImages() {
    var c = E.clock(now(), state.off), horizon = E.addDays(c.today, 45);
    var pool = state.events.filter(function (ev) { return ev.img && ev.date >= c.today && ev.date <= horizon && !ev.profile.kids && !ev.profile.contest; });
    var used = {};
    E.MOODS.forEach(function (m) {
      var best = null, bs = -1;
      pool.forEach(function (ev) {
        if (used[ev.img]) return;
        var s = E._internal.moodScore(ev, m).score - (ev.start - c.now) / 864e5 / 400;
        if (s > bs) { bs = s; best = ev; }
      });
      if (best) { used[best.img] = 1; state.moodImg[m.id] = best; }
    });
    var test = { bigband: function (p) { return p.bigband; }, jam: function (p) { return p.jam; }, vocalnight: function (p) { return p.vocalNight; } };
    Object.keys(test).forEach(function (k) {
      for (var i = 0; i < pool.length; i++) if (test[k](pool[i].profile) && !used[pool[i].img]) { used[pool[i].img] = 1; state.fmtImg[k] = pool[i]; break; }
    });
  }

  /* ---------- шаги ---------- */
  function rerender() { if (state.step === 'results') renderResults(); else renderStep(); }

  function renderStep() {
    var s = STEPS[state.step];
    var html = '<div class="fade"><p class="eyebrow">Шаг ' + (state.step + 1) + ' из 4</p><h2 class="q" id="knav-q">' + s.q + '</h2><p class="help">' + s.help + '</p>';
    if (state.failed) html += '<div class="note err">Не удалось загрузить афишу. Попробуйте ещё раз чуть позже или откройте <a href="' + esc((cfg.site || '') + '/') + '">полную афишу</a>.</div>';
    else if (s.key === 'mood') html += moodStep();
    else if (!state.ready) html += '<div class="note">Загружаем афишу…</div>';
    else if (s.key === 'when') html += whenStep();
    else if (s.key === 'sound') html += soundStep();
    else if (s.key === 'about') html += aboutStep();
    body.innerHTML = html + '</div>';
    body.scrollTop = 0;
    renderFoot();
    setProgress(state.step);
    btnBack.classList.toggle('hide', state.step === 0 && !state.editing);
    if (s.key === 'about' && state.ready) bindBudget();
  }

  function moodStep() {
    var h = state.editing ? '' : '<button type="button" class="skip-all" data-act="skip-all">Не знаю, покажите лучшее' + ICON.arrow + '</button>';
    h += '<div class="moods' + (state.a.moods.length ? ' has-sel' : '') + '" role="group" aria-label="Настроение">';
    E.MOODS.forEach(function (m) {
      var on = state.a.moods.indexOf(m.id) !== -1, lvl = ENERGY[m.id] || 3, bars = '';
      var n = state.ready ? count(with_({ moods: [m.id] })) : '';
      for (var i = 1; i <= 5; i++) bars += '<i class="' + (i <= lvl ? 'on' : '') + '" style="height:' + (4 + i * 2) + 'px"></i>';
      h += '<button type="button" class="mood" data-act="mood" data-v="' + m.id + '" aria-pressed="' + on + '" aria-label="' + esc(m.label + ', ' + m.hint + (n !== '' ? ', ' + n + ' ' + plural(n, 'концерт', 'концерта', 'концертов') : '')) + '">' +
        img(state.moodImg[m.id], 'big') + '<span class="lvl" aria-hidden="true">' + bars + '</span>' + (n !== '' ? '<span class="n">' + n + '</span>' : '') +
        '<span class="ok">' + ICON.check + '</span><span class="mt"><b>' + esc(m.label) + '</b><small>' + esc(m.hint) + '</small></span></button>';
    });
    return h + '</div>';
  }

  function whenStep() {
    var c = E.clock(now(), state.off), h = '<div class="group"><div class="chips" role="group" aria-label="Когда">';
    E.WHEN.forEach(function (w) {
      var on = state.a.when === w.id, n = w.id === 'any' ? null : count(with_({ when: w.id, date: null }));
      h += '<button type="button" class="chip" data-act="when" data-v="' + w.id + '" aria-pressed="' + on + '"' + (n === 0 ? ' disabled' : '') + '>' + w.label + (n !== null ? ' <span class="n">' + n + '</span>' : '') + '</button>';
    });
    h += '</div></div><div class="group"><p class="group-t"><span>Или выберите дату</span><span>точки: сцены</span></p><div class="dates" role="group" aria-label="Дата">';
    for (var i = 0; i < 21; i++) {
      var iso = E.addDays(c.today, i), dow = E.dowOf(iso);
      var list = E.matches(state.events, with_({ when: 'date', date: iso }), now(), opt());
      var st = {}; list.forEach(function (x) { if (x.ev.stage) st[x.ev.stage] = 1; });
      var dots = STAGE_ORDER.filter(function (k) { return st[k]; }).map(function (k) { return '<i style="background:' + STAGE_COLOR[k] + '"></i>'; }).join('');
      var on = state.a.when === 'date' && state.a.date === iso;
      var wd = i === 0 ? 'сег' : (i === 1 ? 'зав' : WD[dow]);
      h += '<button type="button" class="day' + (dow === 0 || dow === 6 ? ' we' : '') + (dow === 1 && i > 1 ? ' mon' : '') + '" data-act="date" data-v="' + iso + '" aria-pressed="' + on + '"' + (list.length ? '' : ' disabled') +
        ' aria-label="' + (+iso.slice(8, 10)) + ' ' + MONTH_SHORT[+iso.slice(5, 7) - 1] + ', ' + list.length + ' ' + plural(list.length, 'концерт', 'концерта', 'концертов') + '"><span class="w">' + wd + '</span><span class="d">' + (+iso.slice(8, 10)) + '</span><span class="dots">' + dots + '</span></button>';
    }
    h += '</div><div class="legend" aria-hidden="true">' + STAGE_ORDER.map(function (k) { return '<span><i style="background:' + STAGE_COLOR[k] + '"></i>' + E.STAGES[k].short + '</span>'; }).join('') + '</div></div>';
    h += '<div class="group"><p class="group-t"><span>Время</span><span>необязательно</span></p><div class="chips" role="group" aria-label="Время">';
    E.TIMES.forEach(function (t) {
      h += '<button type="button" class="chip" data-act="time" data-v="' + t.id + '" aria-pressed="' + (state.a.times.indexOf(t.id) !== -1) + '">' + ICON[t.id] + t.label + ' <small>' + t.hint + '</small></button>';
    });
    return h + '</div></div>';
  }

  function soundStep() {
    var h = '<div class="group"><p class="group-t"><span>Формат</span></p><div class="fmts" role="group" aria-label="Формат">';
    E.FORMATS.forEach(function (f) {
      var on = state.a.formats.indexOf(f.id) !== -1, n = count(with_({ formats: [f.id], instruments: [] }));
      h += '<button type="button" class="fmt" data-act="format" data-v="' + f.id + '" aria-pressed="' + on + '"' + (n === 0 && !on ? ' disabled' : '') + '>' + img(state.fmtImg[f.id], 'thumb') +
        '<span class="n">' + n + '</span><b>' + f.label + '</b></button>';
    });
    h += '</div></div><div class="group"><p class="group-t"><span>Инструменты</span><span>до трёх</span></p><div class="chips" role="group" aria-label="Инструменты">';
    E.INSTRUMENTS.forEach(function (ins) {
      var on = state.a.instruments.indexOf(ins.id) !== -1, n = count(with_({ instruments: [ins.id], formats: [] }));
      if (n === 0 && !on) return; // инструментов, которых нет в афише на эти даты, не показываем
      h += '<button type="button" class="chip" data-act="ins" data-v="' + ins.id + '" aria-pressed="' + on + '">' + ins.label + ' <span class="n">' + n + '</span></button>';
    });
    return h + '</div></div>';
  }

  function seg(act, list, val, icon) {
    var idx = -1, hint = '';
    list.forEach(function (o, i) { if (o.id === val) { idx = i; hint = o.hint || ''; } });
    var h = '<div class="seg" role="group"' + (idx >= 0 ? ' data-i="' + idx + '"' : '') + '><span class="thumb" aria-hidden="true"></span>';
    list.forEach(function (o, i) { h += '<button type="button" data-act="' + act + '" data-v="' + o.id + '" aria-pressed="' + (o.id === val) + '">' + icon(i + 1) + '<b>' + o.label + '</b></button>'; });
    return h + '</div>' + (act === 'exp' ? '<p class="seg-hint">' + esc(hint || 'Подскажем, с чего начать, или найдём редкое') + '</p>' : '');
  }

  function budgetValue() { return state.a.budget ? Math.min(Math.max(state.a.budget, BUD.min), BUD.max) : BUD.max; }
  function aboutStep() {
    var a = state.a;
    var h = '<div class="group"><p class="group-t"><span>Как часто бываете на джазе?</span></p>' + seg('exp', E.EXPERIENCE, a.experience, notes) + '</div>';
    h += '<div class="group"><p class="group-t"><span>С кем идёте?</span></p>' + seg('company', E.COMPANY, a.company, people) + '</div>';
    var v = budgetValue();
    h += '<div class="group"><p class="group-t"><span>Бюджет на человека</span><span>билет или депозит</span></p><div class="bud">' +
      '<div class="bud-top"><span class="bud-v"></span><span class="bud-c"></span></div><div class="hist" aria-hidden="true"></div>' +
      '<input class="range" type="range" min="' + BUD.min + '" max="' + BUD.max + '" step="' + BUD.step + '" value="' + v + '" aria-label="Бюджет на человека">' +
      '<div class="ticks" aria-hidden="true"><span>500</span><span>1 500</span><span>2 500</span><span>3 500</span><span>любой</span></div></div></div>';
    return h;
  }
  // слайдер обновляет подпись и гистограмму на лету, а подборку пересчитывает по отпусканию
  function bindBudget() {
    var range = body.querySelector('.range'), vEl = body.querySelector('.bud-v'), cEl = body.querySelector('.bud-c'), hist = body.querySelector('.hist');
    if (!range) return;
    var prices = E.matches(state.events, with_({ budget: 0 }), now(), opt()).map(function (x) { return x.ev.price; }).filter(function (p) { return p !== null; });
    var BIN = 250, bins = [], nb = Math.ceil(BUD.max / BIN);
    for (var i = 0; i < nb; i++) bins.push(0);
    prices.forEach(function (p) { bins[Math.min(nb - 1, Math.floor(p / BIN))]++; });
    var mx = Math.max.apply(null, bins.concat([1]));
    hist.innerHTML = bins.map(function (b, i) { return '<i data-x="' + (i * BIN) + '" style="height:' + Math.round(4 + 96 * b / mx) + '%"></i>'; }).join('');
    var bars = hist.children;
    function paint(v) {
      var any = v >= BUD.max;
      vEl.textContent = any ? 'Любой бюджет' : 'до ' + rub(v);
      var inN = any ? prices.length : prices.filter(function (p) { return p <= v; }).length;
      cEl.textContent = prices.length ? 'в бюджете ' + inN + ' из ' + prices.length : '';
      for (var k = 0; k < bars.length; k++) bars[k].className = any || +bars[k].getAttribute('data-x') < v ? '' : 'out';
      range.style.setProperty('--p', ((v - BUD.min) / (BUD.max - BUD.min) * 100) + '%');
      range.setAttribute('aria-valuetext', vEl.textContent);
    }
    paint(+range.value);
    var raf = 0;
    range.addEventListener('input', function () {
      var v = +range.value;
      paint(v);
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(function () { state.a.budget = v >= BUD.max ? 0 : v; renderFoot(); });
    });
  }

  function renderFoot() {
    var last = state.step === STEPS.length - 1;
    var optional = STEPS[state.step].key === 'sound' && !state.a.instruments.length && !state.a.formats.length;
    var label = state.editing ? 'Обновить' : (last ? 'Подобрать' : (optional ? 'Пропустить' : 'Далее'));
    var counter;
    if (!state.ready) counter = '<div class="counter"><div class="cnt-t"><b>…</b><span>' + (state.failed ? 'афиша недоступна' : 'загружаем афишу') + '</span></div></div>';
    else {
      var list = E.matches(state.events, state.a, now(), opt()).sort(function (x, y) { return y.score - x.score; });
      var n = list.length, seen = {}, th = [];
      for (var i = 0; i < list.length && th.length < 3; i++) if (list[i].ev.img && !seen[list[i].ev.img]) { seen[list[i].ev.img] = 1; th.push(list[i].ev); }
      var bump = state.lastCount !== null && state.lastCount !== n ? ' class="bump"' : '';
      state.lastCount = n;
      counter = n === 0
        ? '<div class="counter"><div class="cnt-t zero"><b>Точных совпадений нет</b><span>подберём самые близкие</span></div></div>'
        : '<div class="counter"><div class="thumbs" aria-hidden="true">' + th.map(function (ev) { return '<img src="' + esc(imgUrl(ev, 'thumb')) + '" alt="">'; }).join('') + '</div>' +
          '<div class="cnt-t"><b' + bump + '>' + n + '</b><span>' + plural(n, 'концерт подходит', 'концерта подходят', 'концертов подходят') + '</span></div></div>';
    }
    foot.hidden = false;
    foot.innerHTML = counter + '<button type="button" class="primary' + (optional && !state.editing ? ' ghost' : '') + '" data-act="next"' + (state.ready ? '' : ' disabled') + '>' + label + ICON.arrow + '</button>';
  }

  function setProgress(i) {
    var bars = progress.children;
    for (var k = 0; k < bars.length; k++) bars[k].className = k < i ? 'done' : (k === i ? 'on' : '');
    progress.hidden = i < 0;
  }

  /* ---------- подборка ---------- */
  function answerChips() {
    var a = state.a, out = [];
    a.moods.forEach(function (id) { var m = E.MOODS.filter(function (x) { return x.id === id; })[0]; if (m) out.push([0, m.label]); });
    if (a.when === 'date' && a.date) out.push([1, WD[E.dowOf(a.date)] + ', ' + (+a.date.slice(8, 10)) + ' ' + MONTH_SHORT[+a.date.slice(5, 7) - 1]]);
    else if (a.when) out.push([1, a.when === 'any' ? 'Любой день' : E.WHEN.filter(function (w) { return w.id === a.when; })[0].label]);
    a.times.forEach(function (t) { out.push([1, E.TIMES.filter(function (x) { return x.id === t; })[0].label]); });
    a.formats.forEach(function (f) { out.push([2, E.FORMATS.filter(function (x) { return x.id === f; })[0].label]); });
    a.instruments.forEach(function (f) { out.push([2, E.INSTRUMENTS.filter(function (x) { return x.id === f; })[0].label]); });
    if (a.experience) out.push([3, { new: 'Впервые на джазе', regular: 'Хожу иногда', expert: 'Хожу часто' }[a.experience]]);
    if (a.company) out.push([3, E.COMPANY.filter(function (x) { return x.id === a.company; })[0].label]);
    if (a.budget) out.push([3, 'до ' + rub(a.budget)]);
    return '<div class="answers" aria-label="Ваши ответы">' + out.map(function (c) { return '<button type="button" class="chip" data-act="goto" data-v="' + c[0] + '">' + esc(c[1]) + '</button>'; }).join('') +
      '<button type="button" class="chip edit" data-act="goto" data-v="0">' + ICON.pen + ' Изменить</button></div>';
  }
  function cta(p, small) {
    var id = ' data-id="' + esc(p.ev.id) + '"';
    if (p.buy === 'ticket') return '<button type="button" class="cta" data-act="buy"' + id + '>' + (small ? 'Купить' : 'Купить билет' + ICON.arrow) + '</button>';
    if (p.buy === 'registration') return '<button type="button" class="cta" data-act="buy"' + id + '>Записаться' + (small ? '' : ICON.arrow) + '</button>';
    if (p.buy === 'table') return '<button type="button" class="cta alt" data-act="table"' + id + '>' + (small ? 'Стол' : 'Забронировать стол') + '</button>';
    return '<a class="cta ghost" href="' + esc(eventUrl(p.ev)) + '" data-act="page"' + id + '>Подробнее</a>';
  }
  function why(p) { return p.reason ? '<div class="why">' + ICON.spark + '<span>' + esc(p.reason) + '</span></div>' : ''; }
  function tags(p) { return p.relaxed.length ? '<div class="tags">' + p.relaxed.map(function (t) { return '<span class="tag">' + esc(t) + '</span>'; }).join('') + '</div>' : ''; }
  function link(p) { return '<a href="' + esc(eventUrl(p.ev)) + '" data-act="page" data-id="' + esc(p.ev.id) + '">' + esc(p.ev.title) + '</a>'; }
  function stageLine(p) { return p.stage ? '<span class="dot" style="background:' + STAGE_COLOR[p.ev.stage] + '"></span>' + esc(p.stage.short) : ''; }

  function heroTicket(p) {
    var ev = p.ev, d = +ev.date.slice(8, 10), mo = MONTH_SHORT[+ev.date.slice(5, 7) - 1], dow = E.dowOf(ev.date);
    var timeB = ev.midnight ? 'Полночь' : ev.time;
    var timeS = ev.midnight ? 'в ночь с ' + WD_GEN[dow] + ' на ' + WD_ACC[(dow + 1) % 7] : 'начало концерта';
    return '<article class="ticket fade"><div class="t-pic">' + img(ev, 'big') +
      '<div class="t-top">' + (p.best ? '<span class="badge best">Лучшее совпадение</span>' : '') + (p.date.rel ? '<span class="badge rel">' + p.date.rel + '</span>' : '') + '</div>' +
      '<div class="t-cap"><span class="t-stage">' + stageLine(p) + '</span><h3 class="t-title">' + link(p) + '</h3></div></div>' +
      '<div class="perf" aria-hidden="true"></div><div class="stub"><div class="stub-row">' +
      '<div class="t-date' + (p.date.rel ? ' rel' : '') + '"><b>' + d + '</b><span>' + mo + '<br>' + WD[dow] + '</span></div><span class="vr"></span>' +
      '<div class="t-time"><b>' + timeB + '</b><span>' + timeS + '</span></div>' +
      '<div class="t-price"><b>' + esc(p.price.main) + '</b>' + (p.price.sub ? '<span>' + esc(p.price.sub) + '</span>' : '') + '</div></div>' +
      why(p) + tags(p) + cta(p, false) + '</div></article>';
  }
  function rowTicket(p) {
    var ev = p.ev, d = +ev.date.slice(8, 10), mo = MONTH_SHORT[+ev.date.slice(5, 7) - 1], dow = E.dowOf(ev.date);
    return '<article class="ticket row fade"><div class="t-pic">' + img(ev, 'thumb') + '<div class="t-d"><b>' + d + '</b><span>' + mo + ' · ' + WD[dow] + '</span></div></div>' +
      '<div class="vperf" aria-hidden="true"></div><div class="r-in"><div class="r-meta">' + (p.date.rel ? '<span class="rl">' + p.date.rel + '</span>' : '') +
      '<span>' + (ev.midnight ? 'полночь' : ev.time) + '</span>' + (p.stage ? '<span>·</span>' + stageLine(p) : '') + '</div>' +
      '<h3 class="r-title">' + link(p) + '</h3>' + why(p) + tags(p) +
      '<div class="r-buy"><b>' + esc(p.price.main) + '</b>' + cta(p, true) + '</div></div></article>';
  }
  function resultHelp(r, empty) {
    if (empty) return 'Три ближайших вечера, на которые стоит прийти. Ответьте на вопросы, и подборка станет точнее.';
    if (r.exact > 3) return 'Три разных вечера из ' + r.exact + ' ' + plural(r.exact, 'подходящего', 'подходящих', 'подходящих') + '. Первый совпадает лучше всего.';
    if (r.exact === 3) return 'Под ваши ответы подошли ровно три вечера.';
    if (r.exact > 0) return 'Точно подошло: ' + r.exact + '. Остальные близкие, на билете отмечено, чем отличаются.';
    return 'Точных совпадений нет. Вот самые близкие к вашим ответам вечера.';
  }
  function isEmpty(a) { return !a.moods.length && !a.when && !a.times.length && !a.instruments.length && !a.formats.length && !a.experience && !a.company && !a.budget; }

  function renderResults() {
    var r = state.result, a = state.a, empty = isEmpty(a);
    var h = '<div class="fade"><p class="eyebrow">' + (empty ? 'Выбор клуба' : 'Подборка готова') + '</p><h2 class="q" id="knav-q">' + (empty ? 'Не пропустите' : 'Три вечера для вас') + '</h2>';
    h += '<p class="help">' + resultHelp(r, empty) + '</p>' + (empty ? '' : answerChips());
    r.notes.forEach(function (n) { h += '<div class="note">' + esc(n.text) + (n.action ? '<br><button type="button" data-act="note">' + esc(n.action.label) + '</button>' : '') + '</div>'; });
    h += '<div class="cards">';
    r.picks.forEach(function (p, i) { h += i === 0 ? heroTicket(p) : rowTicket(p); });
    state.extra.forEach(function (p) { h += rowTicket(p); });
    h += '</div>';
    if (r.reserve.length > state.extra.length) h += '<button type="button" class="more" data-act="more">Ещё варианты</button>';
    h += '<div class="links"><a href="' + esc((cfg.site || '') + E.afishaLink(state.a, now(), opt())) + '" data-act="afisha">Открыть афишу с этими фильтрами</a><button type="button" data-act="restart">Пройти заново</button></div></div>';
    body.innerHTML = h;
    foot.hidden = true;
    setProgress(-1);
    btnBack.classList.remove('hide');
  }

  /* ---------- покупка ---------- */
  function findPick(id) { return (state.result ? state.result.picks : []).concat(state.extra).filter(function (p) { return p.ev.id === id; })[0]; }
  function openSheet(kind, p) {
    var h = '<div class="sheet-wrap" data-act="sheet-close"><div class="sheet fade" role="dialog" aria-modal="true" aria-labelledby="knav-sheet-t" data-stop="1">' +
      '<p class="eyebrow">' + esc(p.date.line) + (p.stage ? ' · ' + esc(p.stage.short) : '') + '</p><h3 id="knav-sheet-t">' + esc(p.ev.title) + '</h3>';
    if (kind === 'table') {
      h += '<p>Столы на этот вечер бронируются по телефону. ' + esc(p.price.main) + (p.price.sub ? ', ' + esc(p.price.sub) : '') + '.</p>' +
        '<div class="tel"><b>' + esc(PHONE) + '</b><button type="button" class="icon-btn" data-act="copy" aria-label="Скопировать номер">' + ICON.copy + '</button></div>' +
        '<a class="cta alt" href="tel:' + esc(PHONE.replace(/[^\d+]/g, '')) + '" data-act="call">Позвонить</a>';
    } else {
      h += '<p>Это прототип: на сайте здесь сразу открывается окно Ticketscloud с выбором мест.</p>' +
        '<a class="cta" href="' + esc(eventUrl(p.ev)) + '" target="_blank" rel="noopener" data-act="page" data-id="' + esc(p.ev.id) + '">Выбрать места' + ICON.arrow + '</a>';
    }
    sheetBox.innerHTML = h + '<button type="button" class="close-s" data-act="sheet-close">Вернуться к подборке</button></div></div>';
  }
  // окно Ticketscloud открывается так же, как с кнопок сайта: скрытой кнопкой с атрибутом tc-event
  function buyTicket(p) {
    if (MODE === 'prebuilt' || !p.ev.tc || !window.ticketsCloudWidget) {
      if (MODE === 'prebuilt') { openSheet('ticket', p); return; }
      window.location.href = eventUrl(p.ev); return;
    }
    close(true);
    var b = document.createElement('button');
    b.type = 'button'; b.setAttribute('tc-event', p.ev.tc); b.setAttribute('aria-hidden', 'true'); b.tabIndex = -1;
    b.style.cssText = 'position:fixed;left:-9999px;top:0;width:1px;height:1px;opacity:0;pointer-events:none';
    document.body.appendChild(b);
    b.click();
    setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 3000);
  }

  /* ---------- навигация ---------- */
  function go(step) {
    state.step = step;
    if (step === 'results') { renderResults(); return; }
    renderStep();
    if (!state.editing) track('step_' + (step + 1));
  }
  function showResults(reason) {
    if (!state.ready) return;
    state.result = E.recommend(state.events, state.a, now(), opt());
    state.extra = [];
    state.shown = state.result.picks.map(function (p) { return p.ev.id; });
    state.editing = false;
    go('results');
    track('results', { r: reason, exact: state.result.exact, a: compactAnswers(state.a), p: state.result.picks.map(function (p) { return { id: p.ev.id, t: p.ev.title.slice(0, 90) }; }) });
    live.textContent = 'Подборка готова: ' + state.result.picks.map(function (p) { return p.ev.title; }).join('; ');
  }
  function refresh() {
    var y = body.scrollTop, dates = body.querySelector('.dates'), dx = dates ? dates.scrollLeft : 0;
    renderStep();
    body.scrollTop = y;
    dates = body.querySelector('.dates');
    if (dates) dates.scrollLeft = dx;
    var f = body.querySelector('.fade'); if (f) f.classList.remove('fade');
  }

  var savedOverflow = '', pushed = false;
  function open(source) {
    if (state.open) return;
    state.open = true;
    navEl.hidden = false;
    savedOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    if (launcherEl) launcherEl.classList.add('away');
    loadData();
    rerender();
    if (state.step === 0 && !state.editing) track('step_1');
    try { history.pushState({ knav: 1 }, ''); pushed = true; } catch (e) { pushed = false; }
    track('open', { src: source || 'button', path: location.pathname });
    setTimeout(function () { var b = R.querySelector('.panel [data-act=close]'); if (b) b.focus({ preventScroll: true }); }, 50);
  }
  function close(silent) {
    if (!state.open) return;
    state.open = false;
    navEl.hidden = true;
    sheetBox.innerHTML = '';
    document.documentElement.style.overflow = savedOverflow;
    lastY = window.scrollY;
    setAway(restAway());
    if (pushed) { pushed = false; try { if (history.state && history.state.knav) history.back(); } catch (e) { } }
    if (!silent) track('close', { step: state.step });
  }
  window.addEventListener('popstate', function () { if (state.open) { pushed = false; close(); } });

  R.addEventListener('click', function (e) {
    var t = e.target.closest('[data-act]');
    if (!t) return;
    var act = t.getAttribute('data-act'), v = t.getAttribute('data-v'), a = state.a;
    if (act === 'sheet-close' && e.target.closest('[data-stop]') && !e.target.closest('.close-s')) return;
    switch (act) {
      case 'open': open('launcher'); break;
      case 'hide-launcher': minimize(); break;
      case 'restore-launcher': restore(); break;
      case 'close': close(); break;
      case 'mood': a.moods = toggle(a.moods, v, 2); refresh(); break;
      case 'when': a.when = a.when === v ? null : v; a.date = null; refresh(); break;
      case 'date': if (a.when === 'date' && a.date === v) { a.when = null; a.date = null; } else { a.when = 'date'; a.date = v; } refresh(); break;
      case 'time': a.times = toggle(a.times, v); refresh(); break;
      case 'format': a.formats = toggle(a.formats, v); refresh(); break;
      case 'ins': a.instruments = toggle(a.instruments, v, 3); refresh(); break;
      case 'exp': a.experience = a.experience === v ? null : v; refresh(); break;
      case 'company': a.company = a.company === v ? null : v; refresh(); break;
      case 'next':
        if (state.editing || state.step === STEPS.length - 1) showResults(state.editing ? 'edit' : 'quiz');
        else go(state.step + 1);
        break;
      case 'back':
        if (state.step === 'results') { state.editing = false; go(STEPS.length - 1); }
        else if (state.editing) showResults('edit-cancel');
        else if (state.step > 0) go(state.step - 1);
        break;
      case 'skip-all': if (state.ready) { track('skip'); showResults('skip'); } break;
      case 'goto': state.editing = true; go(+v); track('edit_answer', { step: +v + 1 }); break;
      case 'note':
        var n = state.result.notes.filter(function (x) { return x.action; })[0];
        if (n) { for (var k in n.action.patch) a[k] = n.action.patch[k]; showResults('note'); }
        break;
      case 'more':
        var add = E.more(state.result, a, now(), state.shown, 3);
        add.forEach(function (p) { state.shown.push(p.ev.id); });
        state.extra = state.extra.concat(add);
        var y = body.scrollTop; renderResults(); body.scrollTop = y;
        track('more', { added: add.length });
        break;
      case 'restart': state.a = EMPTY(); state.editing = false; go(0); break;
      case 'buy':
        var p1 = findPick(t.getAttribute('data-id'));
        if (p1) { track('buy_click', { id: p1.ev.id, t: p1.ev.title.slice(0, 90), tc: p1.ev.tc }); buyTicket(p1); }
        break;
      case 'table':
        var p2 = findPick(t.getAttribute('data-id'));
        if (p2) { track('table_click', { id: p2.ev.id, t: p2.ev.title.slice(0, 90) }); openSheet('table', p2); }
        break;
      case 'page': track('event_page', { id: t.getAttribute('data-id') }); break;
      case 'afisha': track('afisha_link'); break;
      case 'call': track('call_click'); break;
      case 'copy':
        try { navigator.clipboard.writeText(PHONE).then(function () { t.innerHTML = ICON.check; t.setAttribute('aria-label', 'Скопировано'); }, function () { }); } catch (err) { }
        track('phone_copy');
        break;
      case 'sheet-close': sheetBox.innerHTML = ''; break;
    }
  });
  R.addEventListener('error', function (e) { // нет фото нужного размера: берём обычное, иначе убираем
    var im = e.target;
    if (!im || im.tagName !== 'IMG') return;
    var fb = im.getAttribute('data-fb');
    if (fb) { im.removeAttribute('data-fb'); im.src = fb; } else if (im.parentNode) im.parentNode.removeChild(im);
  }, true);
  if (launcherEl) {
    launcherEl.addEventListener('pointerenter', loadData);
    launcherEl.addEventListener('touchstart', loadData, { passive: true });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || !state.open) return;
    if (sheetBox.innerHTML) sheetBox.innerHTML = ''; else close();
  });

  /* ---------- кнопка в углу ----------
   * Появляется после прокрутки (на главной, когда пролистали фильтры) и дальше стоит на месте, как у прежнего виджета.
   * Компьютер: тёмная кнопка с надписью. Телефон: красный кружок 52 px, размер не меняется, чтобы ничего не прыгало;
   * при первом появлении за визит рядом на 4 секунды всплывает подпись «Подобрать концерт».
   * На коротких страницах, где листать почти некуда, видна сразу. */
  var SCROLL_MODE = cfg.launcherMode === 'scroll';
  var lastY = window.scrollY;
  function topZone() {
    var el = inlineOn ? stripAnchor : (stripHost && !stripOff ? stripHost : null);
    if (el) { var r = el.getBoundingClientRect(); if (r.height) return Math.max(260, r.bottom + window.scrollY); }
    return 300;
  }
  function showAt() {
    var room = document.documentElement.scrollHeight - window.innerHeight;
    return room < 120 ? -1 : Math.min(200, room * 0.4);
  }
  function restAway() { var y = window.scrollY; return SCROLL_MODE ? y <= showAt() : y <= topZone(); }
  var roundMQ = window.matchMedia ? window.matchMedia('(max-width: 719px)') : null, hintT = 0;
  function syncRound() { if (launcherEl) launcherEl.classList.toggle('round', !!(roundMQ && roundMQ.matches)); }
  syncRound();
  function hintOnce() {
    if (!launcherEl || mini || !launcherEl.classList.contains('round')) return;
    try { if (sessionStorage.getItem('knav_hint')) return; sessionStorage.setItem('knav_hint', '1'); } catch (e) { }
    launcherEl.classList.add('hinting');
    clearTimeout(hintT);
    hintT = setTimeout(function () { launcherEl.classList.remove('hinting'); }, 4000);
  }
  function syncLauncher() { if (!state.open) setAway(restAway()); }

  /* Свои закреплённые кнопки сайта не закрываем: если в углу экрана уже есть элемент страницы
   * (круглая кнопка «на главную» на страницах концертов, нижняя панель покупки), кнопка навигатора встаёт над ним. */
  var lift = 0, liftAt = 0;
  function pinnedParent(el) {
    for (var n = el; n && n !== document.body && n !== document.documentElement; n = n.parentElement) {
      if (n === host) return null;
      var cs = getComputedStyle(n);
      if (cs.position === 'sticky' && cs.bottom === 'auto') continue; // прилипает к верху: в нижний угол не попадает
      if (cs.position === 'fixed' || cs.position === 'sticky') return (cs.visibility === 'hidden' || +cs.opacity === 0) ? null : n;
    }
    return null;
  }
  function measureLift() {
    if (!launcherEl) return;
    liftAt = Date.now();
    var W = window.innerWidth, H = window.innerHeight, lw = launcherEl.offsetWidth || 200, lh = launcherEl.offsetHeight || 50, base = 16, need = 0;
    for (var pass = 0; pass < 3; pass++) {
      var bottom = H - base - need, top = bottom - lh, hit = need;
      var xs = [W - 16 - lw + 8, W - 16 - lw / 2, W - 24], ys = [top + 6, top + lh / 2, bottom - 6];
      for (var i = 0; i < xs.length; i++) for (var j = 0; j < ys.length; j++) {
        var list = document.elementsFromPoint(xs[i], ys[j]) || [];
        for (var k = 0; k < list.length; k++) {
          var f = pinnedParent(list[k]);
          if (!f) continue;
          var r = f.getBoundingClientRect();
          if (r.height > H * 0.5 || r.top < H * 0.45 || r.bottom < top) continue; // фон и оверлеи на весь экран не в счёт
          hit = Math.max(hit, Math.ceil(H - r.top + 10 - base));
        }
      }
      if (hit <= need) break;
      need = hit;
    }
    need = Math.min(need, Math.round(H * 0.4));
    if (need !== lift) { lift = need; launcherEl.style.setProperty('--lift', need + 'px'); }
  }
  function setAway(on) {
    if (!launcherEl) return;
    var was = launcherEl.classList.contains('away');
    if (!on && (was || Date.now() - liftAt > 400)) measureLift();
    if (on !== was) launcherEl.classList.toggle('away', on);
    if (!on && was) setTimeout(hintOnce, 350);
    if (on) { clearTimeout(hintT); launcherEl.classList.remove('hinting'); }
  }
  if (launcherEl) {
    window.addEventListener('scroll', function () {
      if (state.open) return;
      setAway(restAway());
    }, { passive: true });
    syncLauncher();
    window.addEventListener('load', syncLauncher);
    window.addEventListener('resize', function () { liftAt = 0; syncRound(); syncLauncher(); }, { passive: true });
  }

  var toastT = 0;
  function toast(text, undo) {
    var old = wrap.querySelector('.toast');
    if (old) old.parentNode.removeChild(old);
    var t = document.createElement('div');
    t.className = 'toast';
    t.setAttribute('role', 'status');
    t.innerHTML = '<span>' + esc(text) + '</span><button type="button">Вернуть</button>';
    if (launcherEl) measureLift();
    t.style.setProperty('--lift', lift + 'px'); // над своими закреплёнными кнопками сайта, как и кнопка навигатора
    wrap.appendChild(t);
    function done() { clearTimeout(toastT); if (t.parentNode) t.parentNode.removeChild(t); }
    t.querySelector('button').addEventListener('click', function () { done(); undo(); });
    clearTimeout(toastT);
    toastT = setTimeout(done, 6000);
  }
  function hideStrip() {
    if (!stripHost || stripOff) return;
    stripOff = true;
    setHidden('entry', true);
    track('dismiss', { what: 'strip' });
    var st = stripHost.style, h = stripHost.offsetHeight, mt = parseFloat(st.marginTop) || 0;
    st.overflow = 'hidden'; st.height = h + 'px'; st.transition = 'height .25s ease, margin-top .25s ease, opacity .2s ease';
    requestAnimationFrame(function () { st.height = '0px'; st.marginTop = (mt - 8) + 'px'; st.opacity = '0'; });
    var gone = setTimeout(function () { st.display = 'none'; }, 280);
    toast('Подсказка скрыта', function () {
      clearTimeout(gone);
      stripOff = false;
      setHidden('entry', false);
      track('dismiss_undo', { what: 'strip' });
      st.display = 'block';
      requestAnimationFrame(function () { st.height = h + 'px'; st.marginTop = mt + 'px'; st.opacity = '1'; });
      setTimeout(function () { st.height = ''; st.overflow = ''; st.transition = ''; layoutStrip(); }, 300);
    });
  }
  function minimize() {
    if (!launcherEl || mini) return;
    mini = true;
    launcherEl.classList.add('mini');
    launcherEl.classList.remove('hinting');
    setHidden('launcher', true);
    track('dismiss', { what: 'launcher' });
    liftAt = 0;
    measureLift();
  }
  function restore() {
    if (!launcherEl || !mini) return;
    mini = false;
    launcherEl.classList.remove('mini');
    setHidden('launcher', false);
    track('dismiss_undo', { what: 'launcher' });
    liftAt = 0;
    measureLift();
  }

  if (cfg.autoOpen) open('auto');
  // публичный вход для своих кнопок на сайте: <a href="#navigator"> открывает навигатор
  function hashOpen() { if (location.hash === '#navigator') { open('link'); try { history.replaceState(history.state, '', location.pathname + location.search); } catch (e) { } } }
  window.addEventListener('hashchange', hashOpen);
  hashOpen();
}

function boot() { try { knavStart(E, CSS); } catch (e) { try { console.warn('[Kozlov Navigator]', e); } catch (x) { } } }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
