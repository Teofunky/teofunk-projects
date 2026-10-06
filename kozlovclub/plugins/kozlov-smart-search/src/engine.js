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
   * extra:  { genres: {code: name}, offset: смещение часового пояса клуба в миллисекундах,
   *           cutOffset: смещение часов афиши в миллисекундах (по ним концерт убирается из списка) }
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
    // часы афиши: по ним сайт решает, что концерт прошёл. Если сервер их не прислал, берём часы клуба
    var cut = (typeof extra.cutOffset === 'number' && isFinite(extra.cutOffset)) ? clubNow(now, extra.cutOffset) : m;
    // ближайшие выходные: если сегодня суббота или воскресенье, то текущие
    var wdToday = weekdayOf(today);
    var sat = wdToday === 0 ? addDays(today, -1) : addDays(today, (6 - wdToday));
    var weekend = [sat, addDays(sat, 1)];
    var items = [];
    (Array.isArray(events) ? events : []).forEach(function (e, n) {
      // строка без названия, даты или времени пропускается
      if (!e || typeof e[0] !== 'string' || !e[0] || typeof e[1] !== 'string' || !/^\d{4}-\d\d-\d\d$/.test(e[1]) || typeof e[2] !== 'string') return;
      // концерт остаётся в поиске, пока он есть на афише: сравниваем с часами афиши
      if (e[1] < cut.ymd || (e[1] === cut.ymd && e[2] <= cut.hm)) return;
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
