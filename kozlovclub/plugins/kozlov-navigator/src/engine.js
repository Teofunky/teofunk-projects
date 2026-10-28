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
    // только целые слова: «труба» как начало слова ловила «трубадур» (Il trovatore в анонсах классики)
    { id: 'trumpet', label: 'Труба', stems: ['=труба', '=трубе', '=трубы', '=трубу', '=трубой', '=трубач', '=трубача', '=трубачу', '=трубачом', '=трубаче', '=трубачи', '=трубачей', '=трубачами', 'флюгельгорн', 'trumpet'] },
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
    if (ev.price === null) return { main: ev.priceText || 'Цена уточняется', sub: ev.priceText ? '' : 'на странице концерта' };
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
