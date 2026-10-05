/*! Kozlov Smart Search 1.0.0. Поиск по афише в браузере посетителя. Глобальных переменных не создаёт, jQuery не использует. */
(function () {
'use strict';

/* Kozlov Smart Search: engine. Pure functions, no DOM, no globals: the function returns its API. */
function kssEngine() {
  'use strict';

  var RU2LAT = { 'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e', 'ж': 'Z', 'з': 'z', 'и': 'i', 'й': 'i', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u', 'ф': 'f', 'х': 'H', 'ц': 'ts', 'ч': 'C', 'ш': 'S', 'щ': 'S', 'ъ': '', 'ы': 'i', 'ь': '', 'э': 'e', 'ю': 'u', 'я': 'a' };
  var EN_KEYS = "`qwertyuiop[]asdfghjkl;'zxcvbnm,.";
  var RU_KEYS = 'ёйцукенгшщзхъфывапролджэячсмитьбю';
  var EN2RU = {}, RU2EN = {};
  for (var i = 0; i < EN_KEYS.length; i++) { EN2RU[EN_KEYS[i]] = RU_KEYS[i]; RU2EN[RU_KEYS[i]] = EN_KEYS[i]; }

  /* Частые слова афиши, которые пишут на двух языках по-разному. */
  var SYN_PAIRS = [['jam', 'джем'], ['band', 'бэнд'], ['blues', 'блюз'], ['funk', 'фанк'], ['fusion', 'фьюжн'], ['tribute', 'трибьют'], ['night', 'найт'], ['brunch', 'бранч'], ['session', 'сэшн'], ['big', 'биг'], ['latin', 'латина'], ['gospel', 'госпел'], ['orchestra', 'оркестр'], ['club', 'клуб'], ['duo', 'дуэт'], ['project', 'проект'], ['quartet', 'квартет'], ['quintet', 'квинтет'], ['trio', 'трио'], ['soul', 'соул'], ['bebop', 'бибоп'], ['boogie', 'буги'], ['woogie', 'вуги'], ['house', 'хаус'], ['lounge', 'лаунж'], ['live', 'лайв'], ['day', 'дэй'], ['time', 'тайм'], ['new', 'нью'], ['love', 'лав'], ['life', 'лайф'], ['blue', 'блю'], ['people', 'пипл'], ['voice', 'войс'], ['sound', 'саунд'], ['sun', 'сан'], ['music', 'мьюзик'], ['friday', 'фрайдей'], ['sunday', 'сандей'], ['saturday', 'сатурдей'], ['party', 'пати'], ['show', 'шоу'], ['dance', 'дэнс'], ['acoustic', 'акустик'], ['swing', 'свинг'], ['groove', 'грув']];
  var SYN = {};
  SYN_PAIRS.forEach(function (p) { SYN[p[0]] = p[1]; SYN[p[1]] = p[0]; });

  var STOP = {};
  ['и', 'в', 'на', 'с', 'по', 'у', 'к', 'the', 'and', 'a', 'of', 'feat', 'концерт', 'концерты', 'концерта', 'концертов', 'билет', 'билеты', 'билетов', 'купить', 'афиша', 'афише', 'расписание', 'когда', 'выступление', 'выступает', 'программа', 'руб', 'рублей', 'рубля', 'р', 'что', 'где', 'куда', 'кто', 'как', 'какие', 'какой', 'какая', 'есть', 'идет', 'будет', 'играет', 'играют', 'хочу', 'покажи', 'показать', 'все', 'пойти', 'сходить', 'можно', 'послушать', 'посмотреть', 'ближайшие', 'ближайший', 'ли', 'а', 'же', 'вы', 'мы', 'я', 'вас', 'нас', 'вам', 'мне', 'ваш', 'ваши', 'это', 'то'].forEach(function (w) { STOP[w] = 1; });

  /* Окончания для грубого стемминга русских слов: «баронина» -> «баронин», «субботам» -> «суббот». */
  var ENDINGS = ['иями', 'ями', 'ами', 'иях', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими', 'ой', 'ей', 'ый', 'ий', 'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ым', 'им', 'ых', 'их', 'ом', 'ем', 'ам', 'ям', 'ах', 'ях', 'ов', 'ев', 'ую', 'юю', 'ою', 'ею', 'а', 'я', 'ы', 'и', 'у', 'ю', 'е', 'о', 'ь'];
  function stem(w) {
    if (w.length < 4 || !/^[а-я]+$/.test(w)) return w;
    for (var i = 0; i < ENDINGS.length; i++) {
      var e = ENDINGS[i];
      if (w.length - e.length >= 3 && w.slice(-e.length) === e) return w.slice(0, -e.length);
    }
    return w;
  }

  function norm(s) {
    return String(s || '').toLowerCase().replace(/ё/g, 'е')
      .replace(/[\u00c0-\u024f]/g, function (c) { return c.normalize ? c.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : c; })
      .replace(/[«»"'`’‘“”]/g, '').replace(/[^a-zа-я0-9.]+/g, ' ').replace(/\.(?!\d)/g, ' ').replace(/\s+/g, ' ').trim();
  }
  function tokens(s) {
    var n = norm(s);
    return n ? n.split(' ') : [];
  }

  /* Phonetic key: brings Cyrillic and Latin spellings of one name to the same string.
     Capital letters are single sounds: S = ш, C = ч, Z = ж, H = х, J = дж. */
  function pk(tok) {
    var s = '', i, ch;
    if (/[а-я]/.test(tok)) {
      for (i = 0; i < tok.length; i++) { ch = tok[i]; s += RU2LAT.hasOwnProperty(ch) ? RU2LAT[ch] : ch; }
      s = s.replace(/dZ/g, 'J');
    } else {
      // английские сочетания букв сводим к тому, как их пишут по-русски
      s = tok.replace(/sch|sh/g, 'S').replace(/tch|ch/g, 'C').replace(/zh/g, 'Z').replace(/kh/g, 'H').replace(/^h/, 'H')
        .replace(/ph/g, 'f').replace(/th/g, 't').replace(/ck/g, 'k').replace(/qu/g, 'kv').replace(/q/g, 'k').replace(/x/g, 'ks').replace(/w/g, 'v')
        .replace(/dZ|dj/g, 'J').replace(/j/g, 'J')
        .replace(/c(?=[eiy])/g, 's').replace(/c/g, 'k')
        .replace(/y([aou])/g, function (m, v) { return v.toUpperCase(); })   // ya, yu, yo = я, ю, о; заглавная буква защищает от правила «ea»
        .replace(/oo|ou/g, 'u').replace(/ee|ea/g, 'i').replace(/y/g, 'i')
        .replace(/h/g, '')
        .replace(/[AOU]/g, function (m) { return m.toLowerCase(); });
    }
    return s.replace(/([^aeiou])\1+/g, '$1');
  }

  function skel(key) { return key.replace(/[aeiou]/g, ''); }
  function isVowel(c) { return 'aeiou'.indexOf(c) >= 0; }

  function swapLayout(tok, map) {
    var out = '', hit = 0, i, ch;
    for (i = 0; i < tok.length; i++) { ch = tok[i]; if (map.hasOwnProperty(ch)) { out += map[ch]; hit++; } else { out += ch; } }
    return hit ? out : '';
  }

  /* Edit distance from q to the closest prefix of t (Damerau). Stops early when above max. */
  function prefixDistance(q, t, max) {
    var n = q.length, m = Math.min(t.length, n + max), i, j;
    if (!n) return 0;
    var prev2 = null, prev = [], cur;
    for (j = 0; j <= m; j++) prev[j] = j;
    for (i = 1; i <= n; i++) {
      cur = [i];
      var rowMin = i;
      for (j = 1; j <= m; j++) {
        var cost = q.charCodeAt(i - 1) === t.charCodeAt(j - 1) ? 0 : 1;
        var v = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
        if (i > 1 && j > 1 && q.charCodeAt(i - 1) === t.charCodeAt(j - 2) && q.charCodeAt(i - 2) === t.charCodeAt(j - 1)) v = Math.min(v, prev2[j - 2] + 1);
        cur[j] = v;
        if (v < rowMin) rowMin = v;
      }
      if (rowMin > max) return max + 1;
      prev2 = prev; prev = cur;
    }
    var best = max + 1;
    for (j = Math.max(0, n - max); j <= m; j++) if (prev[j] < best) best = prev[j];
    return best;
  }

  function allowed(len) { return len <= 4 ? 0 : (len <= 8 ? 1 : 2); }

  /* Score of one query variant against one indexed token. 0 means no match. */
  function scoreToken(v, t) {
    var q = v.q, qk = v.k;
    if (/^\d/.test(q)) return q === t.raw ? 100 : 0;
    if (t.raw === q) return 100;
    if (t.raw.indexOf(q) === 0) return 90;
    if (t.stop) return 0;
    // фонетический ключ не используем, если от слова почти ничего не осталось ("ыыыы" -> "i")
    var keyOk = qk && qk.length >= (v.pen >= 5 ? 4 : 2) && (q.length - qk.length <= 2 || qk.length >= 4);
    if (keyOk && t.key === qk) return 80;
    // начало слова по звучанию: только между разными алфавитами и от четырёх звуков («кант» -> CANTALOOP)
    if (keyOk && qk.length >= (v.pen >= 5 ? 5 : 4) && v.cyr !== t.cyr && t.key.indexOf(qk) === 0) return 70;
    // падежные окончания: «баронина» -> «баронин», «зала» -> «зал»
    if (v.st !== q) {
      if (v.st.length >= 4 && t.raw.indexOf(v.st) === 0) return 60;
      if (v.st.length >= 3 && v.st === t.st) return 60;
    }
    if (q.length >= 4 && (t.f === 't' || t.f === 'x') && t.raw.indexOf(q) > 0) return 45;
    if (!v.fz || !keyOk) return 0;
    var sameStart = qk[0] === t.key[0] || (isVowel(qk[0]) && isVowel(t.key[0]));
    if (qk.length >= 5 && t.key.length >= 4 && sameStart) {
      var max = allowed(qk.length);
      var d = prefixDistance(qk, t.key, max);
      if (d <= max) return d === 0 ? 55 : (d === 1 ? 50 : 35);
    }
    // одинаковый «скелет» из согласных: рэгтайм = ragtime, олмазова = алмазова
    if (v.s && v.s.length >= 4 && v.s === t.sk && sameStart) return 40;
    return 0;
  }

  function mk(raw, field) { var key = pk(raw); return { raw: raw, key: key, sk: skel(key), st: stem(raw), cyr: /[а-я]/.test(raw), f: field, stop: field === 't' && STOP[raw] === 1 }; }
  function mkAll(words, field) { return words.map(function (w) { return mk(norm(w), field); }); }
  function exact(raw, field) { return { raw: raw, key: '', sk: '', st: raw, cyr: false, f: field, stop: false }; }

  /* ---------- структурные словари: дата, сцена, жанр, цена ---------- */
  var MONTHS = [['январь', 'января', 'янв'], ['февраль', 'февраля', 'фев'], ['март', 'марта', 'мар'], ['апрель', 'апреля', 'апр'], ['май', 'мая'], ['июнь', 'июня', 'июн'], ['июль', 'июля', 'июл'], ['август', 'августа', 'авг'], ['сентябрь', 'сентября', 'сен'], ['октябрь', 'октября', 'окт'], ['ноябрь', 'ноября', 'ноя'], ['декабрь', 'декабря', 'дек']];
  var WEEKDAYS = [['воскресенье', 'вс', 'выходные'], ['понедельник', 'пн'], ['вторник', 'вт'], ['среда', 'среду', 'ср'], ['четверг', 'чт'], ['пятница', 'пятницу', 'пт'], ['суббота', 'субботу', 'сб', 'выходные']];
  var RELATIVE = ['сегодня', 'завтра', 'послезавтра'];
  var STAGES = {
    g: { name: 'Главная сцена', words: ['главная', 'главной', 'сцена', 'main', 'stage', 'маросейка'] },
    m: { name: 'Мансарда', words: ['мансарда', 'мансарде', 'mansarda', 'mansard', 'сцена', 'маросейка'] },
    b: { name: 'The Beat', words: ['beat', 'бит', 'сцена', 'маросейка'] },
    u: { name: 'Unplugged', words: ['unplugged', 'анплагд', 'мясницкая', 'мясницкой', 'сцена'] }
  };
  var GENRES = {
    'jazz': { name: 'Джаз', words: ['джаз', 'jazz'] },
    'fusion': { name: 'Фьюжн', words: ['фьюжн', 'fusion'] },
    'soul': { name: 'Соул', words: ['соул', 'soul'] },
    'jazz-rock': { name: 'Джаз-рок', words: ['джазрок', 'jazzrock', 'джаз', 'рок', 'jazz', 'rock'] },
    'gospel': { name: 'Госпел', words: ['госпел', 'gospel'] },
    'blues': { name: 'Блюз', words: ['блюз', 'blues'] },
    'manush': { name: 'Мануш', words: ['мануш', 'manouche', 'джипси', 'gypsy'] },
    'jam': { name: 'Джем-сэшн', words: ['джем', 'jam', 'джемсэшн', 'сэшн', 'session'] },
    'for kids': { name: 'Детям', words: ['детям', 'дети', 'детей', 'детский', 'детские', 'ребенок', 'kids'] },
    'avangard': { name: 'Авангард', words: ['авангард', 'avantgarde'] },
    'classics': { name: 'Классика', words: ['классика', 'классическая', 'classics', 'classical'] },
    'ethnics': { name: 'Этника', words: ['этника', 'этно', 'этническая', 'ethnic', 'ethno', 'world'] },
    'rock': { name: 'Рок', words: ['рок', 'rock'] },
    'arsenal': { name: 'Арсенал', words: ['арсенал', 'arsenal'] }
  };
  var FREE_WORDS = ['бесплатно', 'бесплатный', 'бесплатные', 'свободный', 'вход', 'free'];
  var TEXT = { t: 1, x: 1 }, STRUCT = { d: 1, s: 1, p: 1, g: 1 };
  function has(o, k) { return Object.prototype.hasOwnProperty.call(o, k); }

  function genreWords(code, name) {
    var w = has(GENRES, code) ? GENRES[code].words.slice() : [];
    tokens(name || '').concat(tokens(code)).forEach(function (t) { if (t.length >= 3 && w.indexOf(t) < 0) w.push(t); });
    var joined = norm(name || '').replace(/ /g, ''); if (joined.length >= 3 && w.indexOf(joined) < 0) w.push(joined);
    return w;
  }

  function makeVocab(genres) {
    var seen = {}, out = [];
    function add(words, f) { words.forEach(function (w) { w = norm(w); if (w && !seen[w]) { seen[w] = 1; out.push(mk(w, f)); } }); }
    MONTHS.forEach(function (m) { add(m, 'd'); }); WEEKDAYS.forEach(function (w) { add(w, 'd'); }); add(RELATIVE, 'd'); add(FREE_WORDS, 'p'); add(['депозит'], 'p');
    Object.keys(STAGES).forEach(function (k) { add(STAGES[k].words, 's'); });
    Object.keys(genres).forEach(function (k) { add(genres[k].words, 'g'); });
    return out;
  }

  /* ---------- время клуба: смещение часового пояса сайта приходит с сервера, по умолчанию Москва (UTC+3) ---------- */
  var MSK_OFFSET = 3 * 60 * 60 * 1000;
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function clubNow(now, offsetMs) {
    var d = new Date((now ? now.getTime() : Date.now()) + (typeof offsetMs === 'number' ? offsetMs : MSK_OFFSET));
    return { ymd: d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()), hm: pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) };
  }
  function addDays(ymd, n) {
    var p = ymd.split('-'), d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n));
    return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
  }
  function weekdayOf(ymd) { var p = ymd.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])).getUTCDay(); }
  function dateParts(ymd) { var p = ymd.split('-'); return { y: +p[0], m: +p[1], d: +p[2], wd: weekdayOf(ymd) }; }

  /**
   * events: [title, 'YYYY-MM-DD', 'HH:MM', stageCodes, priceText, slug, image, button, [genreCodes], textId, ...]
   *         stageCodes: буквы g, m, b, u (у концерта может быть несколько сцен); textId: номер текста анонса или -1
   * pages:  [{title, url, words}]
   * now:    Date (любой часовой пояс посетителя, пересчитывается во время клуба)
   * extra:  { genres: {code: name}, offset: смещение часового пояса клуба в миллисекундах }
   * Тексты анонсов подключаются отдельно: attachTexts(index, payload).
   */
  function build(events, pages, now, extra) {
    extra = extra || {};
    var genres = Object.create(null), given = (extra.genres && typeof extra.genres === 'object' && !Array.isArray(extra.genres)) ? extra.genres : {};
    Object.keys(GENRES).forEach(function (k) { genres[k] = { name: GENRES[k].name, words: GENRES[k].words }; });
    Object.keys(given).forEach(function (k) {
      var name = typeof given[k] === 'string' ? given[k] : '';
      genres[k] = { name: name || (genres[k] && genres[k].name) || k, words: genreWords(k, name) };
    });
    var m = clubNow(now, extra.offset), today = m.ymd, tomorrow = addDays(today, 1), after = addDays(today, 2);
    // ближайшие выходные: если сегодня суббота или воскресенье, то текущие
    var wdToday = weekdayOf(today);
    var sat = wdToday === 0 ? addDays(today, -1) : addDays(today, (6 - wdToday));
    var weekend = [sat, addDays(sat, 1)];
    var items = [];
    (Array.isArray(events) ? events : []).forEach(function (e, n) {
      // строка без названия, даты или времени пропускается
      if (!e || typeof e[0] !== 'string' || !e[0] || typeof e[1] !== 'string' || !/^\d{4}-\d\d-\d\d$/.test(e[1]) || typeof e[2] !== 'string') return;
      // концерт считается будущим, пока не наступило время начала по времени клуба
      if (e[1] < today || (e[1] === today && e[2] <= m.hm)) return;
      var dp = dateParts(e[1]);
      var toks = tokens(e[0]).map(function (w) { return mk(w, 't'); });
      // составные слова через дефис ищутся и слитно: «рок-н-ролл» = «рокнролл»
      (String(e[0]).toLowerCase().replace(/ё/g, 'е').match(/[a-zа-я0-9]+(?:-[a-zа-я0-9]+)+/g) || []).forEach(function (c) { toks.push(mk(c.replace(/-/g, ''), 'x')); });
      var extra = [String(dp.d), pad(dp.d), dp.d + '.' + pad(dp.m), pad(dp.d) + '.' + pad(dp.m)].concat(MONTHS[dp.m - 1]).concat(WEEKDAYS[dp.wd]);
      if (e[1] === today) extra.push('сегодня');
      if (e[1] === tomorrow) extra.push('завтра');
      if (e[1] === after) extra.push('послезавтра');
      toks = toks.concat(mkAll(extra, 'd'));
      if (/^\d\d:\d\d$/.test(e[2])) toks.push(exact(e[2], 'd'));
      var stages = String(e[3] || '').split('').filter(function (c) { return STAGES[c]; });
      stages.forEach(function (c) { toks = toks.concat(mkAll(STAGES[c].words, 's')); });
      var price = norm(e[4]);
      var free = /свободн|бесплатн/.test(price), deposit = /депозит/.test(price);
      if (free) toks = toks.concat(mkAll(FREE_WORDS, 'p'));
      if (deposit) toks = toks.concat(mkAll(['депозит'], 'p'));
      var priceText = typeof e[4] === 'string' ? e[4] : '';
      var digits = priceText.replace(/\s+/g, '').match(/\d{3,}/);
      var priceNum = free ? 0 : (digits ? +digits[0] : null);
      if (digits) toks.push(mk(digits[0], 'p'));
      var gs = (Array.isArray(e[8]) ? e[8] : []).filter(function (g) { return typeof g === 'string' && genres[g]; });
      gs.forEach(function (g) { toks = toks.concat(mkAll(genres[g].words, 'g')); });
      var tid = (typeof e[9] === 'number' && e[9] >= 0) ? e[9] : -1;
      items.push({ n: n, title: e[0], date: e[1], time: e[2], stage: stages[0] || '', stages: stages.join(''), price: priceText, slug: typeof e[5] === 'string' ? e[5] : '', img: e[6], tickets: !!e[7], free: free, deposit: deposit, priceNum: priceNum, weekday: dp.wd, genres: gs, tid: tid, raw: e, toks: toks, group: norm(e[0]) });
    });
    var pageItems = (Array.isArray(pages) ? pages : []).filter(function (p) { return p && typeof p.title === 'string' && p.title && typeof p.url === 'string'; }).map(function (p) {
      var words = (Array.isArray(p.words) ? p.words : []).filter(function (w) { return typeof w === 'string'; });
      return { title: p.title, url: p.url, toks: tokens(p.title).map(function (w) { return mk(w, 't'); }).concat(mkAll(words, 't')) };
    });
    return { items: items, pages: pageItems, today: today, tomorrow: tomorrow, after: after, weekend: weekend, nowHM: m.hm, vocab: makeVocab(genres), genres: genres, hasTexts: false, content: null };
  }

  /* ---------- разбор запроса ---------- */
  function variant(q, pen, fz) { var k = pk(q); return { q: q, k: k, pen: pen, fz: fz, s: fz ? skel(k) : '', st: stem(q), cyr: /[а-я]/.test(q) }; }

  function variants(tok) {
    var out = [variant(tok, 0, true)];
    if (SYN.hasOwnProperty(tok)) out.push(variant(SYN[tok], 2, false));
    // неверная раскладка: берём вариант, только если получились одни буквы и слово не короче трёх знаков
    var a = swapLayout(tok, EN2RU), b = swapLayout(tok, RU2EN);
    if (a && a !== tok && a.length >= 3 && /^[а-я0-9.]+$/.test(a)) out.push(variant(a, 5, a.length >= 6));
    if (b && b !== tok && b.length >= 3 && /^[a-z0-9.]+$/.test(b)) out.push(variant(b, 5, false));
    return out;
  }

  /* Структурное ли слово: дата, день недели, сцена, жанр, цена. Возвращает вид слова или ''. */
  function structKind(t, vocab) {
    if (t.kind === 'time') return 'd';
    if (t.kind === 'priceMax') return 'p';
    var q = t.text;
    if (/^\d{1,2}$/.test(q) || /^\d{1,2}\.\d{2}$/.test(q)) return 'd';
    if (/^\d{3,}$/.test(q)) return 'p';
    if (/^\d/.test(q)) return '';
    var best = 0, kind = '', vi, wi;
    for (vi = 0; vi < t.vars.length; vi++) for (wi = 0; wi < vocab.length; wi++) {
      var s = scoreToken(t.vars[vi], vocab[wi]); if (s > best) { best = s; kind = vocab[wi].f; }
    }
    return (best >= 60 || (best >= 50 && q.length >= 6)) ? kind : '';
  }

  function finish(list, vocab) {
    // «до 1500», «дешевле 1500», «не дороже 1500»: верхняя граница цены
    var out = [], i;
    for (i = 0; i < list.length; i++) {
      var t = list[i], nx = list[i + 1];
      var cap = t.text === 'до' || t.text === 'дешевле' || (t.text === 'дороже' && out.length && out[out.length - 1].text === 'не');
      if (cap && nx && /^\d{3,6}$/.test(nx.text)) {
        if (t.text === 'дороже') out.pop();
        out.push({ text: 'до ' + nx.text, kind: 'priceMax', value: +nx.text, vars: [] }); i++; continue;
      }
      out.push(t);
    }
    var meaningful = out.filter(function (t) { return t.kind || !has(STOP, t.text); });
    out = meaningful.length ? meaningful : out;
    out.forEach(function (t) { t.sk = structKind(t, vocab); t.struct = !!t.sk; });
    return out;
  }

  /* Query tokens. Returns the normal reading and, when a word contains layout punctuation
     (",fhjyby" = "баронин", "vty." = "меню"), a second reading where such words are taken whole in the other layout. */
  function queryTokens(query, vocab) {
    var raw = String(query || '').toLowerCase().replace(/ё/g, 'е').trim().split(/\s+/).filter(Boolean).slice(0, 12);
    var main = [], alt = [], hasAlt = false;
    raw.forEach(function (r) {
      if (r.length > 60) r = r.slice(0, 60);
      // время: «20:00», «20.30» (точка считается временем, если это не похоже на дату ДД.ММ)
      var tm = /^(\d{1,2})([:.])(\d{2})$/.exec(r);
      if (tm && +tm[1] <= 23 && +tm[3] <= 59 && (tm[2] === ':' || +tm[3] === 0 || +tm[3] > 12)) {
        var hhmm = pad(+tm[1]) + ':' + tm[3];
        var tt = { text: r, kind: 'time', vars: [{ q: hhmm, k: '', pen: 0, fz: false, s: '', st: hhmm, cyr: false }] };
        main.push(tt); alt.push({ text: r, kind: 'time', vars: tt.vars });
        return;
      }
      var parts = norm(r).split(' ').filter(Boolean);
      parts.forEach(function (c) { main.push({ text: c, vars: variants(c) }); });
      var whole = norm(swapLayout(r, EN2RU));
      if (!/[а-я]/.test(r) && /[\[\];',.`]/.test(r) && /^[а-я]{2,}$/.test(whole)) { alt.push({ text: whole, vars: [variant(whole, 5, whole.length >= 6)] }); hasAlt = true; }
      else parts.forEach(function (c) { alt.push({ text: c, vars: variants(c) }); });
    });
    return { main: finish(main, vocab), alt: hasAlt ? finish(alt, vocab) : null };
  }

  /* ---------- сопоставление ---------- */
  function bestTok(qt, toks, fields) {
    var best = 0, at = -1, vi, ti;
    for (vi = 0; vi < qt.vars.length; vi++) {
      var v = qt.vars[vi];
      for (ti = 0; ti < toks.length; ti++) {
        if (fields && !fields[toks[ti].f]) continue;
        var s = scoreToken(v, toks[ti]);
        if (s) { s -= v.pen; if (toks[ti].f !== 't') s -= 3; if (s > best) { best = s; at = ti; } }
      }
    }
    return best ? { score: best, at: at } : null;
  }

  /**
   * Подключает тексты анонсов к готовому индексу.
   * payload: { vocab: 'слово слово ...', docs: [[номер первого слова, разница до следующего, ...], ...] }
   * Номер текста в docs равен textId события.
   */
  function attachTexts(index, payload) {
    var words = (payload && typeof payload.vocab === 'string' && payload.vocab) ? payload.vocab.split(' ') : [];
    var docs = (payload && payload.docs) || [], post = new Array(words.length), t, j;
    for (t = 0; t < docs.length; t++) {
      var deltas = docs[t] || [], id = 0;
      for (j = 0; j < deltas.length; j++) {
        id += deltas[j];
        if (id >= 0 && id < words.length) (post[id] || (post[id] = [])).push(t);
      }
    }
    index.content = { words: words, post: post, keys: null };
    index.hasTexts = words.length > 0;
    return index;
  }

  /* Слово запроса в текстах анонсов: { номер текста: оценка }. Только точное слово, начало слова и падежи, без опечаток.
     Слова, записанные другим алфавитом («колтрейн» и «coltrane»), сверяются по звучанию. */
  function contentHits(index, qt) {
    if (qt._c) return qt._c;
    var C = index.content, out = {}, vi, wi, k;
    qt._c = out;
    if (!C || qt.kind) return out;
    function put(list, s) { if (list) for (var i = 0; i < list.length; i++) if (!(out[list[i]] >= s)) out[list[i]] = s; }
    for (vi = 0; vi < qt.vars.length; vi++) {
      var v = qt.vars[vi], q = v.q;
      if (q.length < 3 || /^\d/.test(q)) continue;
      var st = (v.st !== q && v.st.length >= 4) ? v.st : '';
      for (wi = 0; wi < C.words.length; wi++) {
        var w = C.words[wi], s = 0;
        if (w === q) s = 52;
        else if (q.length >= 4 && w.lastIndexOf(q, 0) === 0) s = 48;
        else if (st && w.lastIndexOf(st, 0) === 0) s = 46;
        if (s) put(C.post[wi], s - v.pen);
      }
      if (v.pen === 0 && v.k && v.k.length >= 4 && q.length >= 4) {
        if (!C.keys) {
          C.keys = {};
          for (wi = 0; wi < C.words.length; wi++) { k = pk(C.words[wi]); if (k.length >= 4) (C.keys[k] || (C.keys[k] = [])).push(wi); }
        }
        var same = C.keys.hasOwnProperty(v.k) ? C.keys[v.k] : null;
        if (same) for (wi = 0; wi < same.length; wi++) if (/[а-я]/.test(C.words[same[wi]]) !== v.cyr) put(C.post[same[wi]], 44);
      }
    }
    return out;
  }

  /* mode 'A': структурные слова обязаны совпасть с датой, сценой, жанром или ценой, остальные с названием.
     mode 'B': все слова ищутся только в названии. mode 'P': страница сайта.
     mode 'C': как 'A', но обычное слово или название жанра может найтись и в тексте анонса; засчитывается, только если анонс действительно понадобился. */
  function matchItem(qts, it, mode) {
    var total = 0, min = 1000, hit = {}, qi, toks = it.toks, note = [];
    for (qi = 0; qi < qts.length; qi++) {
      var qt = qts[qi], s;
      if (qt.kind === 'priceMax') {
        if (mode === 'B' || mode === 'P' || it.priceNum === null || it.priceNum > qt.value) return null;
        s = 97;
      } else {
        var struct = (mode === 'A' || mode === 'C') && qt.struct;
        var r = bestTok(qt, toks, mode === 'P' ? null : (struct ? STRUCT : TEXT));
        // в анонсе ищем обычные слова и названия жанров; дата, сцена и цена остаются строгими
        if (mode === 'C' && (!struct || qt.sk === 'g') && it.tid >= 0 && qt._c) {
          var cs = qt._c[it.tid] || 0;
          if (cs && (!r || r.score < 60)) { r = { score: cs, at: -1 }; note.push(qt.text); }
        }
        if (!r) return null;
        s = r.score; if (r.at >= 0 && toks[r.at].f === 't') hit[r.at] = 1;
      }
      total += s; if (s < min) min = s;
    }
    if (mode === 'C' && !note.length) return null;
    return { score: total / qts.length, min: min, hit: hit, note: note };
  }

  /* True when the query names every meaningful word of the title: such a group goes first. */
  function coversTitle(hit, toks) {
    var need = 0, got = 0, i;
    for (i = 0; i < toks.length; i++) {
      if (toks[i].f !== 't') break;
      if (toks[i].stop) continue;
      need++; if (hit[i]) got++;
    }
    return need > 0 && need === got;
  }

  function byDate(a, b) { return a.date < b.date ? -1 : a.date > b.date ? 1 : (a.time < b.time ? -1 : a.time > b.time ? 1 : a.n - b.n); }

  function groupFound(found) {
    var groups = {}, order = [];
    found.forEach(function (f) {
      var g = groups[f.it.group];
      if (!g) { g = groups[f.it.group] = { title: f.it.title, score: f.score, hit: f.hit, block: f.block, note: f.note || null, dates: [] }; order.push(g); }
      if (f.block < g.block || (f.block === g.block && f.score > g.score)) { g.score = f.score; g.hit = f.hit; g.block = f.block; g.note = f.note || null; }
      g.dates.push(f.it);
    });
    order.forEach(function (g) { g.dates.sort(byDate); g.first = g.dates[0]; g.tier = Math.floor(g.score / 20); });
    // block 0: запрос называет всё название; 1: совпали структура и текст; 2: совпало только название; 3: слово найдено в тексте анонса
    order.sort(function (a, b) { return a.block !== b.block ? a.block - b.block : (a.tier !== b.tier ? b.tier - a.tier : byDate(a.first, b.first)); });
    return order;
  }

  function strong(found) {
    // если есть уверенные совпадения, слабые нечёткие не показываем
    var top = found.reduce(function (a, f) { return Math.max(a, f.min); }, 0);
    return top >= 65 ? found.filter(function (f) { return f.min >= 55; }) : found;
  }

  function evalAll(items, qts, mode, block) {
    var out = [];
    items.forEach(function (it) {
      var m = matchItem(qts, it, mode);
      if (m) out.push({ it: it, score: m.score, min: m.min, hit: m.hit, block: (m.min >= 60 && coversTitle(m.hit, it.toks)) ? 0 : block });
    });
    return strong(out);
  }

  function passes(it, f, index) {
    if (f.stage && it.stages.indexOf(f.stage) < 0) return false;
    if (f.free && !it.free) return false;
    if (f.genre && it.genres.indexOf(f.genre) < 0) return false;
    if (f.when === 'today' && it.date !== index.today) return false;
    if (f.when === 'tomorrow' && it.date !== index.tomorrow) return false;
    if (f.when === 'weekend' && index.weekend.indexOf(it.date) < 0) return false;
    return true;
  }
  function hasFilter(f) { return !!(f && (f.stage || f.free || f.genre || f.when)); }

  /* Все находки по запросу: структура и название, затем только название, затем текст анонса. */
  function collect(index, items, qts) {
    var hasStruct = qts.some(function (t) { return t.struct; });
    var hasText = qts.some(function (t) { return !t.struct || t.sk === 'g'; });
    var a = evalAll(items, qts, 'A', 1), found = a, seen = {};
    a.forEach(function (f) { seen[f.it.n] = 1; });
    if (hasStruct) {
      // совпадения только по названию идут после структурных
      var b = evalAll(items, qts, 'B', 2).filter(function (f) { return !seen[f.it.n] && (!a.length || f.min >= 45 || f.block === 0); });
      b.forEach(function (f) { seen[f.it.n] = 1; });
      found = found.concat(b);
    }
    if (index.hasTexts && hasText) {
      qts.forEach(function (t) { if (!t.struct || t.sk === 'g') contentHits(index, t); });
      items.forEach(function (it) {
        if (seen[it.n] || it.tid < 0) return;
        var m = matchItem(qts, it, 'C');
        if (m) found.push({ it: it, score: m.score, min: m.min, hit: m.hit, block: 3, note: m.note });
      });
    }
    return found;
  }

  function run(index, items, qts) {
    var found = collect(index, items, qts);
    var pages = [];
    index.pages.forEach(function (p) {
      var m = matchItem(qts, p, 'P');
      if (m && m.min >= 50) pages.push({ page: p, score: m.score, min: m.min });
    });
    pages.sort(function (x, y) { return y.score - x.score; });
    var best = Math.max(found.reduce(function (x, f) { return Math.max(x, f.min); }, 0), pages.reduce(function (x, p) { return Math.max(x, p.min); }, 0));
    return { found: found, pages: pages, best: best };
  }

  /**
   * opts.filter: { when: 'today'|'tomorrow'|'weekend', stage: 'g'|'m'|'b'|'u', free: true, genre: code }
   * Фильтр строгий: в результаты попадают только события, которые ему соответствуют.
   */
  function search(index, query, opts) {
    var filter = opts && opts.filter, filtered = hasFilter(filter);
    var items = filtered ? index.items.filter(function (it) { return passes(it, filter, index); }) : index.items;
    var parsed = queryTokens(query, index.vocab), qts = parsed.main;
    var letters = qts.reduce(function (a, t) { return a + t.text.length; }, 0);
    if (!qts.length || letters < 2) {
      if (!filtered) return { query: query, groups: [], pages: [], count: 0, short: true };
      return { query: query, groups: groupFound(items.map(function (it) { return { it: it, score: 100, min: 100, hit: {}, block: 1 }; })), pages: [], count: items.length, filterOnly: true };
    }
    var r = run(index, items, qts);
    if (parsed.alt) { var r2 = run(index, items, parsed.alt); if (r2.best > r.best) { r = r2; qts = parsed.alt; } }
    var res = { query: query, groups: groupFound(r.found), pages: r.pages.map(function (p) { return p.page; }), count: r.found.length };
    if (r.found.length || r.pages.length) return res;

    var text = qts.filter(function (t) { return !t.struct; });
    var struct = qts.filter(function (t) { return t.struct; });
    var dateToks = struct.filter(function (t) { return t.sk === 'd'; });
    var rest = qts.filter(function (t) { return t.sk !== 'd'; });
    var label = function (list) { return list.map(function (t) { return t.text; }); };
    // «баронин завтра» или «блюз завтра», а завтра этого нет: показываем то же самое в другие дни и прямо говорим об этом.
    // Посторонние концерты на эту дату не подмешиваем.
    if (dateToks.length && rest.length) {
      var t0 = collect(index, items, rest);
      if (t0.length) { res.groups = groupFound(t0); res.count = t0.length; res.relaxed = true; res.dropped = label(dateToks); res.droppedDate = true; return res; }
    }
    // «баронин мансарда», а в Мансарде он не играет: показываем этого артиста на других сценах
    if (struct.length && text.length) {
      var t1 = collect(index, items, text);
      if (t1.length) { res.groups = groupFound(t1); res.count = t1.length; res.relaxed = true; res.dropped = label(struct); return res; }
    }
    // несколько слов, вместе не нашлись: концерты, где в названии совпала часть слов
    if (text.length >= 2) {
      var cand = [], maxN = 0;
      items.forEach(function (it) {
        var n = 0, total = 0, hit = {}, words = [];
        text.forEach(function (qt) {
          var m = matchItem([qt], it, 'B');
          if (m && m.min >= 60) { n++; total += m.score; words.push(qt.text); for (var k in m.hit) hit[k] = 1; }
        });
        if (n) { cand.push({ it: it, score: total / n, min: 60, hit: hit, block: 2, n: n, words: words }); if (n > maxN) maxN = n; }
      });
      cand = cand.filter(function (c) { return c.n === maxN; });
      if (cand.length) {
        var seenW = {}, words = [];
        cand.forEach(function (c) { c.words.forEach(function (w) { if (!seenW[w]) { seenW[w] = 1; words.push(w); } }); });
        res.groups = groupFound(cand); res.count = cand.length; res.partial = true; res.words = words;
        if (struct.length) res.dropped = label(struct);
      }
    }
    return res;
  }

  /* Indexes of title words to highlight for a group. */
  function titleWords(title) {
    var out = [], re = /[A-Za-zА-Яа-яЁё0-9.\u00c0-\u024f«»"'`’‘“”]+/g, m, n = 0, toks;
    while ((m = re.exec(title))) {
      toks = tokens(m[0]);
      out.push({ start: m.index, end: m.index + m[0].length, from: n, to: n + toks.length });
      n += toks.length;
    }
    return out;
  }

  return { build: build, search: search, attachTexts: attachTexts, norm: norm, pk: pk, stem: stem, tokens: tokens, titleWords: titleWords, STAGES: STAGES, GENRES: GENRES, dateParts: dateParts, addDays: addDays, clubNow: clubNow };
}

/* Kozlov Smart Search: интерфейс. Подключается к строке поиска темы, результаты рисует в Shadow DOM.
   Файлы и разметку темы не меняет. При любой ошибке убирает себя, и на сайте остаётся штатный поиск темы. */
function kssUI(K, CSS) {
  'use strict';

  var doc = document, win = window;
  var cfgEl = doc.getElementById('kss-config');
  if (!cfgEl || cfgEl.getAttribute('data-kss')) return;
  var cfg;
  try { cfg = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }
  if (!cfg || typeof cfg.index !== 'string' || !cfg.index) return;

  var bar = doc.querySelector('.search_block');
  var form = bar ? bar.querySelector('form') : null;
  var input = bar ? bar.querySelector('input.searchinput, input[name="s"]') : null;
  if (!bar || !form || !input || !Element.prototype.attachShadow || !win.fetch || !win.MutationObserver) return;

  var MON = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
  var WD = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
  // кнопки быстрого выбора: это строгие фильтры, а не текстовый запрос
  var FILTERS = [['when', 'today', 'Сегодня'], ['when', 'tomorrow', 'Завтра'], ['when', 'weekend', 'Выходные'], ['stage', 'g', 'Главная сцена'], ['stage', 'm', 'Мансарда'], ['stage', 'b', 'The Beat'], ['stage', 'u', 'Unplugged'], ['free', true, 'Вход свободный']];
  var LIMIT = 12, REFRESH_MS = 5 * 60 * 1000;

  var state = 'idle';            // idle: индекс ещё не запрашивали; loading; ready; failed
  var isOpen = false, limit = LIMIT;
  var filter = { when: null, stage: null, free: false };
  var raw = null, index = null, content = null, loadedAt = 0, skew = 0, textsAsked = false, resynced = false;
  var host = null, shadow = null, root = null, panel = null, statusEl = null, closeBtn = null;
  var observer = null, layoutQueued = false, listeners = [];

  function setState(s) { state = s; try { cfgEl.setAttribute('data-kss', s); } catch (e) { /* не критично */ } }
  setState('idle');

  /* Любая ошибка в обработчике выключает наш интерфейс: остаётся штатный поиск темы. */
  function guard(fn) {
    return function () {
      if (state === 'failed') return undefined;
      try { return fn.apply(this, arguments); } catch (e) { fail(e); return undefined; }
    };
  }
  function on(target, type, fn, opts) {
    var g = guard(fn);
    target.addEventListener(type, g, opts || false);
    listeners.push([target, type, g, opts || false]);
  }
  function fail(e) {
    if (state === 'failed') return;
    setState('failed');
    try {
      listeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
      listeners = [];
      if (observer) observer.disconnect();
      if (host && host.parentNode) host.parentNode.removeChild(host);
      host = shadow = root = panel = null; isOpen = false;
      input.removeAttribute('aria-expanded');
    } catch (x) { /* уже нечего убирать */ }
    try { if (win.console && e && cfg.preview) win.console.warn('[kozlov-smart-search] выключен:', e); } catch (x) { /* не критично */ }
  }

  function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  /* Ссылки только внутри сайта: относительный путь от корня. */
  function safeUrl(u) { u = String(u || ''); return (/^\/(?!\/)/.test(u) && !/[\s"'<>\\]/.test(u)) ? u : '#'; }
  function plural(n, a, b, c) { var m = n % 100, k = n % 10; return (m > 10 && m < 20) ? c : (k === 1 ? a : (k > 1 && k < 5 ? b : c)); }
  function nowDate() { return new Date(Date.now() + skew); }
  function coarse() { return !!(win.matchMedia && win.matchMedia('(pointer:coarse)').matches); }
  function focusQuiet(el) { if (!el) return; try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } }

  /* ---------- данные ---------- */
  function getJSON(url, cb, attempt, fresh) {
    var headers = { 'Accept': 'application/json' };
    if (cfg.nonce) headers['X-WP-Nonce'] = String(cfg.nonce);
    if (cfg.key) headers['X-KSS-Preview'] = String(cfg.key);
    var ctrl = win.AbortController ? new win.AbortController() : null;
    var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 12000);
    var status = 0;
    // адрес меняется раз в 5 минут: браузер не покажет афишу старше, какой бы срок хранения ни выставил сервер
    var u = url + (url.indexOf('?') < 0 ? '?' : '&') + 'kss_t=' + Math.floor(Date.now() / REFRESH_MS);
    win.fetch(u, { credentials: 'same-origin', headers: headers, cache: fresh ? 'reload' : 'default', signal: ctrl ? ctrl.signal : undefined })
      .then(function (r) { status = r.status; if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(function (d) { clearTimeout(timer); cb(null, d); })
      .catch(function (e) {
        clearTimeout(timer);
        // 4xx означает, что поиск выключен или недоступен: не повторяем. Иначе одна повторная попытка.
        if (!attempt && !(status >= 400 && status < 500)) setTimeout(function () { getJSON(url, cb, 1, fresh); }, 700);
        else cb(e || new Error('fetch'));
      });
  }

  function validIndex(d) { return !!d && d.v === 1 && Array.isArray(d.ev) && Array.isArray(d.pages); }

  function rebuild() {
    var offset = (typeof raw.off === 'number' && isFinite(raw.off)) ? raw.off * 1000 : undefined;
    index = K.build(raw.ev, raw.pages, nowDate(), { genres: (raw.genres && !Array.isArray(raw.genres)) ? raw.genres : {}, offset: offset });
    if (content) { index.content = content; index.hasTexts = content.words.length > 0; }
  }

  function loadIndex(background, fresh) {
    if (!background) setState('loading');
    getJSON(cfg.index, guard(function (err, d) {
      if (err || !validIndex(d)) {
        if (background) return;      // фоновое обновление не удалось: работаем на прежних данных
        fail(err || new Error('bad index'));
        return;
      }
      raw = d; loadedAt = Date.now();
      // если часы посетителя сильно врут, берём время сервера
      var diff = (typeof d.now === 'number') ? d.now * 1000 - Date.now() : 0;
      skew = Math.abs(diff) > 20 * 60 * 1000 ? diff : 0;
      if (background) { content = null; textsAsked = false; }
      rebuild();
      setState('ready');
      if (isOpen) render(background);
      loadTexts(fresh);
    }), 0, fresh);
  }

  function loadTexts(fresh) {
    if (textsAsked || !cfg.texts || !raw || !raw.texts) return;
    textsAsked = true;
    var built = raw.built;
    setTimeout(function () {
      getJSON(cfg.texts, guard(function (err, d) {
        // тексты анонсов необязательны: без них поиск работает по названию, жанру, дате, сцене и цене
        if (err || !d || d.v !== 1 || typeof d.vocab !== 'string' || !Array.isArray(d.docs) || !raw) return;
        if (d.built !== built) {
          // индекс и тексты собраны в разное время (один из ответов взят из кэша браузера): один раз запрашиваем оба заново
          if (!resynced) { resynced = true; loadIndex(true, true); }
          return;
        }
        K.attachTexts(index, d);
        content = index.content;
        try { cfgEl.setAttribute('data-kss-texts', String(content.words.length)); } catch (e) { /* не критично */ }
        if (isOpen && input.value.replace(/\s+/g, '').length >= 2) render(true);
      }), 0, fresh);
    }, 150);
  }

  /* ---------- разметка ---------- */
  function mount() {
    host = doc.createElement('div');
    host.id = 'kss-host';
    host.style.cssText = 'position:fixed;left:0;top:0;width:0;height:0;margin:0;padding:0;border:0;z-index:99990;';
    shadow = host.attachShadow({ mode: 'open' });
    var style = doc.createElement('style');
    style.textContent = CSS;
    shadow.appendChild(style);
    root = doc.createElement('div');
    root.className = 'kss-root';
    root.hidden = true;
    root.innerHTML = '<div class="kss-shade"></div>' +
      '<button type="button" class="kss-close" aria-label="Закрыть поиск"><svg class="kss-close-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg></button>' +
      '<div class="kss-panel" role="region" aria-label="Результаты поиска"></div>' +
      '<div class="kss-sr" role="status" aria-live="polite"></div>';
    shadow.appendChild(root);
    panel = root.querySelector('.kss-panel');
    statusEl = root.querySelector('.kss-sr');
    closeBtn = root.querySelector('.kss-close');
    doc.body.appendChild(host);

    on(root.querySelector('.kss-shade'), 'click', closeBar);
    on(closeBtn, 'click', closeBar);
    on(panel, 'click', onPanelClick);
    // Щелчки внутри поиска не выходят на страницу. Скрипт Ticketscloud слушает все щелчки документа и падает с ошибкой
    // на тех, что пришли из Shadow DOM; ссылки при этом открываются как обычно, а виджет билетов вызывается отдельной кнопкой вне панели.
    on(root, 'click', function (e) { e.stopPropagation(); });
    // картинка не загрузилась: пробуем исходный файл, затем убираем её
    on(root, 'error', function (e) {
      var img = e.target;
      if (!img || img.tagName !== 'IMG') return;
      var fb = img.getAttribute('data-fb');
      if (fb) { img.removeAttribute('data-fb'); img.src = fb; }
      else if (img.parentNode) img.parentNode.removeChild(img);
    }, true);
  }

  function layout() {
    if (!root) return;
    var br = bar.getBoundingClientRect(), fr = form.getBoundingClientRect(), ir = input.getBoundingClientRect();
    var vw = doc.documentElement.clientWidth || win.innerWidth;
    var vv = win.visualViewport;
    var barH = Math.max(br.height, 50);
    var top = Math.max(0, Math.round(br.top + barH));
    var s = root.style;
    s.setProperty('--kss-top', top + 'px');
    s.setProperty('--kss-bar-top', Math.round(br.top) + 'px');
    s.setProperty('--kss-bar-h', Math.round(barH) + 'px');
    // крестик встаёт на место значка отправки формы темы; если значка нет, у правого края формы
    var sub = form.querySelector('.searchsubmit, input[type="image"], input[type="submit"], button[type="submit"]');
    var sr = sub ? sub.getBoundingClientRect() : null;
    var center = (sr && sr.width > 0 && sr.width <= 60 && sr.right <= vw + 1 && sr.left >= fr.left) ? (sr.left + sr.right) / 2 : fr.right - 9;
    s.setProperty('--kss-close-left', Math.max(0, Math.round(Math.min(vw - 44, center - 22))) + 'px');
    s.setProperty('--kss-pl', Math.max(12, Math.round(ir.left)) + 'px');
    s.setProperty('--kss-pr', Math.max(12, Math.round(vw - fr.right)) + 'px');
    s.setProperty('--kss-vh', Math.round(vv ? vv.height + vv.offsetTop : win.innerHeight) + 'px');
    try {
      var bg = win.getComputedStyle(bar).backgroundColor;
      if (bg && bg !== 'transparent' && bg !== 'rgba(0, 0, 0, 0)') s.setProperty('--kss-bar', bg);
    } catch (e) { /* остаётся цвет по умолчанию */ }
  }
  function queueLayout() {
    if (layoutQueued || !isOpen) return;
    layoutQueued = true;
    (win.requestAnimationFrame || win.setTimeout)(guard(function () { layoutQueued = false; if (isOpen) layout(); }), 16);
  }

  /* ---------- вывод результатов ---------- */
  function eventUrl(it) { return safeUrl(String(raw.evBase || '/event/') + it.slug + '/'); }
  function imgHtml(it) {
    var rel = it.raw[6], flag = it.raw[11], base = String(raw.imgBase || '');
    if (!rel || typeof rel !== 'string' || !base) return '';
    var plainSrc = safeUrl(encodeURI(base + rel)), src = plainSrc, fb = '';
    if (flag === 1) { src = safeUrl(encodeURI(base + rel + '.webp')); fb = plainSrc; }
    else if (flag === 2) { src = safeUrl(encodeURI(base + rel.replace(/\.[a-z]+$/i, '.webp'))); fb = plainSrc; }
    else if (flag === 3 && raw.webpBase) { src = safeUrl(encodeURI(String(raw.webpBase) + rel + '.webp')); fb = plainSrc; }
    else if (flag === 4 && raw.webpBase) { src = safeUrl(encodeURI(String(raw.webpBase) + rel.replace(/\.[a-z]+$/i, '.webp'))); fb = plainSrc; }
    if (src === '#') return '';
    return '<img class="kss-img" src="' + esc(src) + '"' + (fb && fb !== '#' ? ' data-fb="' + esc(fb) + '"' : '') + ' alt="" loading="lazy" decoding="async" width="112" height="84">';
  }
  function stageName(it) {
    var names = [];
    String(it.stages || '').split('').forEach(function (c) { if (K.STAGES[c]) names.push(K.STAGES[c].name); });
    return names.length ? names.join(', ') : 'Клуб Алексея Козлова';
  }
  function dateLabel(it, short) {
    var p = K.dateParts(it.date), base = p.d + ' ' + MON[p.m - 1];
    if (short) return base;
    return base + ', ' + (it.date === index.today ? 'сегодня' : (it.date === index.tomorrow ? 'завтра' : WD[p.wd]));
  }
  function isOn(f) { return f[0] === 'free' ? filter.free : filter[f[0]] === f[1]; }
  function anyFilter() { return !!(filter.when || filter.stage || filter.free); }
  function filterNames() { return FILTERS.filter(isOn).map(function (f) { return f[2]; }); }

  function markTitle(title, hit) {
    var words = K.titleWords(title), out = '', pos = 0;
    words.forEach(function (w) {
      var onw = false, i;
      for (i = w.from; i < w.to; i++) if (hit && hit[i]) onw = true;
      out += esc(title.slice(pos, w.start));
      out += onw ? '<mark class="kss-mark">' + esc(title.slice(w.start, w.end)) + '</mark>' : esc(title.slice(w.start, w.end));
      pos = w.end;
    });
    return out + esc(title.slice(pos));
  }

  function ctaHtml(it, url) {
    var btn = it.raw[7], tc = it.raw[10];
    var hasTc = (btn === 1 || btn === 2) && typeof tc === 'string' && /^[A-Za-z0-9_-]{6,80}$/.test(tc);
    var label = hasTc ? (btn === 2 ? 'Регистрация' : 'Купить билет') : 'Подробнее';
    return '<a class="kss-cta' + (hasTc ? '' : ' kss-cta-read') + '" href="' + esc(url) + '"' + (hasTc ? ' data-tc="' + esc(tc) + '"' : '') + ' tabindex="-1">' + label + '</a>';
  }

  function rowHtml(g) {
    var it = g.first, url = eventUrl(it);
    var more = g.dates.slice(1), shown = more.slice(0, 5);
    var h = '<li class="kss-row' + (more.length ? ' kss-row-multi' : '') + '">';
    h += '<a class="kss-thumb" href="' + esc(url) + '" tabindex="-1" aria-hidden="true">' + imgHtml(it) + '</a>';
    h += '<a class="kss-main" data-nav href="' + esc(url) + '"><span class="kss-title">' + markTitle(g.title, g.hit) + '</span>';
    h += '<span class="kss-meta"><span class="kss-when">' + esc(dateLabel(it)) + ' · ' + esc(it.time) + '</span> · ' + esc(stageName(it)) + (it.price ? '<span class="kss-dot"> · </span><span class="kss-price">' + esc(it.price) + '</span>' : '') + '</span>';
    if (g.note && g.note.length) h += '<span class="kss-note">В описании концерта: «' + esc(g.note.join('», «')) + '»</span>';
    h += '</a>';
    if (more.length) {
      h += '<div class="kss-more"><span class="kss-more-label">Ещё даты:</span>';
      shown.forEach(function (d) { h += '<a class="kss-date" href="' + esc(eventUrl(d)) + '">' + esc(dateLabel(d, true)) + ', ' + WD[K.dateParts(d.date).wd] + (d.stages !== it.stages ? ' · ' + esc(stageName(d)) : '') + '</a>'; });
      if (more.length > shown.length) h += '<span class="kss-more-rest">и ещё ' + (more.length - shown.length) + '</span>';
      h += '</div>';
    }
    return h + ctaHtml(it, url) + '</li>';
  }

  function chipsHtml() {
    var h = '<div class="kss-chips" role="group" aria-label="Быстрый выбор">';
    FILTERS.forEach(function (f, i) { var onf = isOn(f); h += '<button type="button" class="kss-chip' + (onf ? ' kss-chip-on' : '') + '" data-f="' + i + '" aria-pressed="' + (onf ? 'true' : 'false') + '">' + esc(f[2]) + '</button>'; });
    if (anyFilter()) h += '<button type="button" class="kss-chip kss-chip-reset" data-reset>Сбросить</button>';
    return h + '</div>';
  }

  function footHtml() {
    return '<div class="kss-foot"><a class="kss-foot-link" href="' + esc(safeUrl(cfg.home || '/')) + '">Вся афиша</a><span class="kss-keys"><kbd class="kss-kbd">↓</kbd> <kbd class="kss-kbd">↑</kbd> выбрать, <kbd class="kss-kbd">Enter</kbd> к первому результату, <kbd class="kss-kbd">Esc</kbd> закрыть</span></div>';
  }

  function listHtml(groups) {
    // концерты, найденные только по тексту анонса, идут отдельным блоком после совпадений по названию и жанру
    var h = '<ul class="kss-list">', split = false, i;
    for (i = 0; i < groups.length; i++) {
      if (!split && groups[i].block === 3 && i > 0) { h += '<li class="kss-sep">Упоминается в описании концерта</li>'; split = true; }
      h += rowHtml(groups[i]);
    }
    return h + '</ul>';
  }

  function render(keepScroll) {
    if (!panel) return;
    var preview = cfg.preview ? '<p class="kss-preview">Режим проверки: этот поиск видят только администраторы сайта.</p>' : '';
    if (state !== 'ready' || !index) {
      panel.innerHTML = '<div class="kss-in">' + preview + '<p class="kss-none">Загружаем афишу…</p></div>';
      return;
    }
    var q = String(input.value || ''), qt = q.trim(), names = filterNames(), status = '';
    var res = K.search(index, q, { filter: filter });
    var oldChips = panel.querySelector('.kss-chips'), chipScroll = oldChips ? oldChips.scrollLeft : 0, y = panel.scrollTop;
    var html = '<div class="kss-in">' + preview + '<div class="kss-label">Быстрый выбор</div>' + chipsHtml();
    if (res.short) {
      // пустой запрос без фильтров: ближайшие концерты
      var seen = {}, near = [];
      index.items.forEach(function (it) { if (near.length < 6 && !seen[it.group]) { seen[it.group] = 1; near.push({ title: it.title, first: it, dates: [it], hit: null, block: 1 }); } });
      html += '<div class="kss-label">Ближайшие концерты</div>' + (near.length ? listHtml(near) : '<p class="kss-none">В афише пока нет будущих концертов.</p>');
    } else if (!res.groups.length && !res.pages.length) {
      var what = qt ? 'По запросу «' + esc(qt.length > 60 ? qt.slice(0, 60) + '…' : qt) + '»' : 'По выбранным условиям';
      if (qt && names.length) what += ' с условием «' + esc(names.join(', ')) + '»';
      status = 'Ничего не найдено';
      html += '<div class="kss-empty"><span class="kss-empty-title">В ближайшей афише такого нет</span><p class="kss-empty-text">' + what + ' концертов не нашлось. Попробуйте имя артиста, жанр, день недели или сцену.</p></div>';
    } else {
      if (res.groups.length) {
        var count = res.count + ' ' + plural(res.count, 'концерт', 'концерта', 'концертов'), label = count;
        var dropped = res.dropped ? '«' + esc(res.dropped.join('», «')) + '»' : '';
        var onlyText = res.groups[0].block === 3;
        if (res.relaxed && res.droppedDate) label = 'На ' + dropped + ' ничего нет. В другие дни: ' + count;
        else if (res.relaxed) label = 'С условием ' + dropped + ' ничего нет. Без него: ' + count;
        else if (res.partial) label = 'Точного совпадения нет. Показаны концерты по ' + (res.words.length > 1 ? 'словам' : 'слову') + ' «' + esc(res.words.join('», «')) + '»';
        else if (onlyText) label = 'В названиях нет. Упоминается в описании: ' + count;
        else if (names.length) label = esc(names.join(' · ')) + ': ' + count;
        html += '<div class="kss-label">' + label + '</div>';
        status = (res.relaxed || res.partial) ? 'Точного совпадения нет, показаны похожие концерты: ' + res.count : 'Найдено: ' + count;
        html += listHtml(res.groups.slice(0, limit));
        if (res.groups.length > limit) html += '<button type="button" class="kss-showmore" data-more>Показать ещё ' + Math.min(LIMIT, res.groups.length - limit) + '</button>';
      }
      if (res.pages.length) {
        if (!res.groups.length) status = 'Найдены страницы сайта: ' + res.pages.length;
        html += '<div class="kss-label">Страницы сайта</div><div class="kss-pages">';
        res.pages.slice(0, 4).forEach(function (p) { var u = safeUrl(p.url); html += '<a class="kss-page" data-nav href="' + esc(u) + '"><span class="kss-page-title">' + esc(p.title) + '</span><span class="kss-page-url">' + esc(win.location.host + u) + '</span></a>'; });
        html += '</div>';
      }
    }
    panel.innerHTML = html + footHtml() + '</div>';
    panel.scrollTop = keepScroll ? y : 0;
    var chips = panel.querySelector('.kss-chips'); if (chips) chips.scrollLeft = chipScroll;
    if (statusEl) statusEl.textContent = status;
  }

  /* ---------- открытие и закрытие ---------- */
  function barActive() { return bar.classList.contains('active'); }

  function open() {
    if (state === 'failed') return;
    if (!host) mount();
    if (!isOpen) {
      isOpen = true;
      limit = LIMIT;
      root.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      if (state === 'idle') loadIndex(false);
      else if (state === 'ready') {
        rebuild();                // свежие «сегодня» и «завтра», начавшиеся концерты убраны
        if (Date.now() - loadedAt > REFRESH_MS) loadIndex(true);
      }
    }
    layout();
    render();
    // строка поиска темы раскрывается плавно: после анимации уточняем положение
    setTimeout(guard(function () { if (isOpen) layout(); }), 260);
  }

  function hide() {
    if (!isOpen) return;
    isOpen = false;
    if (root) root.hidden = true;
    if (panel) panel.innerHTML = '';
    filter = { when: null, stage: null, free: false };
    input.setAttribute('aria-expanded', 'false');
  }

  /* Закрывает строку поиска темы тем же способом, что и её собственная кнопка: снимает класс active. */
  function closeBar() {
    hide();
    input.value = '';
    try { input.blur(); } catch (e) { /* не критично */ }
    bar.classList.remove('active');
  }

  function sync() {
    if (barActive()) { if (!isOpen) { open(); focusQuiet(input); } }
    else hide();
  }

  function ticketsReady() { return !!(win.ticketsCloudWidget || win.tcBuyTicketClickCallbackBinded); }
  function ticketsOpen() { return !!doc.querySelector('#tc-widget-overlay, [id^="tc-widget"], iframe[name^="tc-frame"], iframe[class*="tc-widget"], iframe[src*="ticketscloud"]'); }

  /* «Купить билет»: открываем тот же виджет Ticketscloud, что и кнопки афиши. Не открылся: ведём на страницу концерта. */
  function buy(token, url) {
    var b = doc.createElement('button');
    b.type = 'button'; b.className = 'kss-tc-proxy'; b.tabIndex = -1;
    b.setAttribute('tc-event', token); b.setAttribute('aria-hidden', 'true');
    b.style.display = 'none';
    doc.body.appendChild(b);
    b.click();
    setTimeout(function () {
      try {
        if (b.parentNode) b.parentNode.removeChild(b);
        if (!ticketsOpen() && url !== '#') win.location.href = url;
      } catch (e) { /* не критично */ }
    }, 2500);
  }

  function onPanelClick(e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var cta = t.closest('[data-tc]');
    if (cta) {
      if (e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || !ticketsReady()) return; // обычный переход по ссылке
      e.preventDefault();
      buy(cta.getAttribute('data-tc'), cta.getAttribute('href'));
      return;
    }
    var fb = t.closest('[data-f]');
    if (fb) {
      var i = +fb.getAttribute('data-f'), f = FILTERS[i];
      if (!f) return;
      if (f[0] === 'free') filter.free = !filter.free; else filter[f[0]] = filter[f[0]] === f[1] ? null : f[1];
      limit = LIMIT; render();
      var again = panel.querySelector('[data-f="' + i + '"]');
      if (again && !coarse()) focusQuiet(again);
      return;
    }
    if (t.closest('[data-reset]')) { filter = { when: null, stage: null, free: false }; limit = LIMIT; render(); return; }
    if (t.closest('[data-more]')) { limit += LIMIT; render(true); }
  }

  /* ---------- связь со строкой поиска темы ---------- */
  observer = new win.MutationObserver(guard(sync));
  observer.observe(bar, { attributes: true, attributeFilter: ['class'] });

  on(input, 'input', function () {
    if (!barActive()) return;
    if (!isOpen) open();
    limit = LIMIT; render();
  });
  on(input, 'focus', function () { if (barActive() && !isOpen) open(); });
  on(form, 'submit', function (e) {
    // пока наш поиск работает, Enter не уводит на страницу штатного поиска, а переводит к первому результату
    if (!isOpen || (state !== 'ready' && state !== 'loading')) return;
    e.preventDefault();
    var first = panel ? panel.querySelector('[data-nav]') : null;
    if (first) focusQuiet(first);
  });
  on(doc, 'keydown', function (e) {
    if (!isOpen || ticketsOpen()) return;
    if (e.key === 'Escape' || e.key === 'Esc') { e.preventDefault(); closeBar(); return; }
    if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
    var links = Array.prototype.slice.call(panel.querySelectorAll('[data-nav]'));
    if (!links.length) return;
    var inside = shadow.activeElement, i = links.indexOf(inside);
    if (i < 0 && doc.activeElement !== input && doc.activeElement !== host) return;
    e.preventDefault();
    if (e.key === 'ArrowDown') focusQuiet(links[i + 1] || links[i] || links[0]);
    else if (i <= 0) focusQuiet(input);
    else focusQuiet(links[i - 1]);
  });
  on(win, 'resize', queueLayout);
  on(win, 'orientationchange', queueLayout);
  if (win.visualViewport) { on(win.visualViewport, 'resize', queueLayout); on(win.visualViewport, 'scroll', queueLayout); }
  // страница возвращена из кэша браузера кнопкой «Назад»: состояние строки поиска могло измениться
  on(win, 'pageshow', function (e) { if (e && e.persisted) sync(); });

  // строка поиска уже раскрыта (посетитель нажал лупу до загрузки скрипта)
  if (barActive() && !/[?&]s=/.test(win.location.search)) guard(function () { open(); focusQuiet(input); })();
}

var KSS_CSS = ".kss-root{all:initial;display:block;font-family:'Roboto',system-ui,-apple-system,'Segoe UI',Arial,sans-serif;font-size:16px;line-height:1.4;color:#020817;-webkit-text-size-adjust:100%;--kss-bg:#ffffff;--kss-ink:#020817;--kss-soft:#5b6170;--kss-line:#dcdcdc;--kss-buy:#f34841;--kss-buy-a:#d53234;--kss-read:#8e9f94;--kss-read-a:#6e7e74;--kss-price:#f24841;--kss-mark:#fde0de;--kss-bar:#eeeeee;--kss-top:50px;--kss-bar-top:0px;--kss-bar-h:50px;--kss-close-left:0px;--kss-pl:30px;--kss-pr:30px;--kss-vh:100vh}.kss-root *{box-sizing:border-box}.kss-root[hidden]{display:none}.kss-shade{position:fixed;left:0;right:0;bottom:0;top:var(--kss-top);background:rgba(2,8,23,.45)}.kss-close{position:fixed;top:var(--kss-bar-top);left:var(--kss-close-left);width:44px;height:var(--kss-bar-h);display:flex;align-items:center;justify-content:center;margin:0;padding:0;border:0;border-radius:0;background:var(--kss-bar);color:var(--kss-ink);cursor:pointer;font:inherit;-webkit-appearance:none;appearance:none}.kss-close-icon{display:block;width:18px;height:18px}.kss-panel{position:fixed;left:0;right:0;top:var(--kss-top);background:var(--kss-bg);box-shadow:0 24px 40px rgba(2,8,23,.18);border-top:1px solid var(--kss-line);max-height:min(74vh,720px);overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;-webkit-overflow-scrolling:touch}.kss-in{padding:16px var(--kss-pr) 8px var(--kss-pl)}.kss-label{overflow-wrap:anywhere;font-size:12px;letter-spacing:.2em;text-transform:uppercase;color:var(--kss-soft);margin:6px 0 10px;font-weight:500}.kss-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}.kss-chip{display:inline-flex;align-items:center;min-height:36px;margin:0;padding:6px 14px;border:1px solid var(--kss-line);background:var(--kss-bg);color:var(--kss-ink);border-radius:18px;font:inherit;font-size:14px;line-height:1.2;white-space:nowrap;cursor:pointer;-webkit-appearance:none;appearance:none}.kss-chip:hover{border-color:var(--kss-ink)}.kss-chip-on,.kss-chip-on:hover{background:var(--kss-ink);border-color:var(--kss-ink);color:#fff}.kss-chip-reset{border-style:dashed;color:var(--kss-soft)}.kss-list{list-style:none;margin:0 0 10px;padding:0;border-top:1px solid var(--kss-line)}.kss-row{display:grid;grid-template-columns:112px minmax(0,1fr) auto;grid-template-areas:\"thumb main cta\";gap:6px 18px;align-items:center;margin:0;padding:12px 0;border-bottom:1px solid var(--kss-line)}.kss-row-multi{grid-template-areas:\"thumb main cta\" \"thumb more cta\"}.kss-sep{margin:0;padding:18px 0 8px;border-bottom:1px solid var(--kss-line);font-size:12px;letter-spacing:.2em;text-transform:uppercase;color:var(--kss-soft);font-weight:500}.kss-thumb{grid-area:thumb;display:block;width:112px;height:84px;background:#d9d9d9;overflow:hidden}.kss-img{display:block;width:100%;height:100%;object-fit:cover;border:0}.kss-main{grid-area:main;min-width:0;display:block;color:inherit;text-decoration:none}.kss-row-multi .kss-main{align-self:end}.kss-title{display:block;font-weight:500;text-transform:uppercase;line-height:1.25;overflow-wrap:anywhere}.kss-mark{background:var(--kss-mark);color:inherit;padding:0 .08em;margin:0 -.08em}.kss-meta{display:block;margin-top:5px;font-size:14px;color:var(--kss-soft);text-transform:uppercase;letter-spacing:.015em;font-weight:500}.kss-when{color:var(--kss-ink);font-weight:500}.kss-price{color:var(--kss-price);white-space:nowrap}.kss-note{display:block;margin-top:4px;font-size:13px;color:var(--kss-soft);overflow-wrap:anywhere}.kss-more{grid-area:more;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:13px;color:var(--kss-soft);align-self:start}.kss-date{display:inline-flex;align-items:center;min-height:28px;padding:2px 10px;border:1px solid var(--kss-line);border-radius:14px;color:var(--kss-ink);font-size:13px;white-space:nowrap;text-decoration:none}.kss-date:hover{border-color:var(--kss-ink)}.kss-cta{grid-area:cta;display:grid;place-items:center;height:44px;padding:0 20px;color:#fff;background:var(--kss-buy);font-size:12px;letter-spacing:.2em;text-transform:uppercase;white-space:nowrap;text-decoration:none;transition:background .2s}.kss-cta:hover{background:var(--kss-buy-a)}.kss-cta-read{background:var(--kss-read)}.kss-cta-read:hover{background:var(--kss-read-a)}.kss-row:hover .kss-title{text-decoration:underline;text-underline-offset:3px}.kss-main:focus-visible .kss-title{text-decoration:underline;text-underline-offset:3px}.kss-page{display:flex;justify-content:space-between;gap:16px;align-items:baseline;padding:12px 0;border-bottom:1px solid var(--kss-line);color:inherit;text-decoration:none}.kss-page-title{font-weight:500;text-transform:uppercase}.kss-page-url{color:var(--kss-soft);font-size:13px;white-space:nowrap}.kss-page:hover .kss-page-title{text-decoration:underline;text-underline-offset:3px}.kss-empty{padding:22px 0 14px}.kss-empty-title{display:block;font-size:17px;font-weight:500;text-transform:uppercase;margin-bottom:6px}.kss-empty-text{margin:0 0 14px;color:var(--kss-soft);max-width:60ch;overflow-wrap:anywhere}.kss-none{margin:0;padding:14px 0;color:var(--kss-soft)}.kss-foot{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:8px 0 10px;font-size:13px;color:var(--kss-soft)}.kss-foot-link{color:var(--kss-ink);text-decoration:underline;text-underline-offset:3px}.kss-kbd{font-family:inherit;border:1px solid var(--kss-line);border-radius:4px;padding:0 5px;font-size:12px;background:var(--kss-bg)}.kss-preview{margin:0 0 12px;padding:8px 12px;background:#fff7e0;border:1px solid #ecd9a0;font-size:13px;color:#5c4a12}.kss-showmore{display:block;width:100%;height:46px;margin:0 0 10px;padding:0;border:1px solid var(--kss-line);border-radius:0;background:var(--kss-bg);color:var(--kss-ink);font:inherit;text-transform:uppercase;font-size:12px;letter-spacing:.2em;cursor:pointer;-webkit-appearance:none;appearance:none}.kss-showmore:hover{border-color:var(--kss-ink)}.kss-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}.kss-close:focus,.kss-chip:focus,.kss-main:focus,.kss-date:focus,.kss-cta:focus,.kss-page:focus,.kss-showmore:focus,.kss-foot-link:focus{outline:2px solid var(--kss-buy);outline-offset:2px}.kss-close:focus:not(:focus-visible),.kss-chip:focus:not(:focus-visible),.kss-main:focus:not(:focus-visible),.kss-date:focus:not(:focus-visible),.kss-cta:focus:not(:focus-visible),.kss-page:focus:not(:focus-visible),.kss-showmore:focus:not(:focus-visible),.kss-foot-link:focus:not(:focus-visible){outline:none}@media (max-width:860px){.kss-panel{bottom:auto;max-height:none;height:calc(var(--kss-vh) - var(--kss-top));box-shadow:none}.kss-in{padding-bottom:calc(72px + env(safe-area-inset-bottom,0px))}.kss-keys{display:none}.kss-chips{flex-wrap:nowrap;overflow-x:auto;margin-left:calc(var(--kss-pl) * -1);margin-right:calc(var(--kss-pr) * -1);padding-left:var(--kss-pl);padding-right:var(--kss-pr);scrollbar-width:none}.kss-chips::-webkit-scrollbar{display:none}.kss-row{grid-template-columns:88px minmax(0,1fr);grid-template-areas:\"thumb main\" \"thumb cta\";gap:10px 12px;align-items:start}.kss-row-multi{grid-template-areas:\"thumb main\" \"thumb cta\" \"more more\"}.kss-thumb{width:88px;height:66px;align-self:start}.kss-row-multi .kss-main{align-self:start}.kss-title{font-size:15px}.kss-meta{font-size:13px}.kss-cta{height:42px;padding:0 12px;font-size:11px;letter-spacing:.16em}.kss-dot{display:none}.kss-price{display:block;margin-top:2px}.kss-page{flex-direction:column;gap:2px}.kss-page-url{white-space:normal;overflow-wrap:anywhere}.kss-date{min-height:34px;padding:4px 12px}}@media (min-width:521px) and (max-width:860px){.kss-cta{justify-self:start;min-width:220px}}@media (prefers-reduced-motion:reduce){.kss-cta{transition:none}}";

try { kssUI(kssEngine(), KSS_CSS); } catch (e) { /* при ошибке остаётся штатный поиск темы */ }
})();
