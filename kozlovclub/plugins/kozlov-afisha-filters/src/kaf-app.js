/* Kozlov Afisha Filters: панель фильтров афиши на главной странице kozlovclub.ru.
   Ставит панель сразу под слайдером в Shadow DOM, концерты берёт из индекса Kozlov Smart Search,
   поиск по музыкантам и словам из анонсов берёт из его текстов анонсов.
   При выбранном фильтре прячет лишние карточки афиши темы (inline display:none), при сбросе возвращает как было.
   Глобальных переменных не создаёт, jQuery не использует.
   Если индекс не загрузился, панель выключена на сайте или в её коде случилась ошибка, панель убирает себя.
   Если данные индекса и карточки на странице разошлись, фильтры отключаются и показывается вся афиша. */
(function () {
  'use strict';

  var CSS = __KAF_CSS__;
  var doc = document, win = window;

  /* запуск после разбора страницы: скрипт подключён с defer, но оптимизаторы иногда переносят скрипты в начало страницы */
  function boot() {

    var cfgEl = doc.getElementById('kaf-config');
    if (!cfgEl || cfgEl.getAttribute('data-kaf')) return;
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent || '{}'); } catch (e) { return; }
    if (!cfg || typeof cfg.index !== 'string' || !cfg.index) return;
    if (!Element.prototype.attachShadow || !win.fetch || !win.URLSearchParams || typeof WeakMap !== 'function' || !Object.create) return;
    function flag(s) { try { cfgEl.setAttribute('data-kaf', s); } catch (e) { /* не критично */ } }
    flag('start');

    /* ---------- место на странице: сразу после слайдера в блоке афиши темы ---------- */
    var wrap = doc.querySelector('.events_wrap > .wrap');
    var anchor = null, viewtabs = null;
    if (wrap) {
      for (var ci = 0; ci < wrap.children.length; ci++) {
        var ch = wrap.children[ci];
        if (!anchor && ch.classList.contains('slider-wrapper')) anchor = ch;
        if (!viewtabs && ch.classList.contains('viewtabs')) viewtabs = ch;
      }
    }
    if (!anchor) { flag('off'); return; }

    /* ---------- постоянные ---------- */
    // словарь без прототипа: ключи вроде constructor или toString в нём не находятся
    function dict(o) { var r = Object.create(null); for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) r[k] = o[k]; return r; }
    var P = 'kaf_'; // префикс параметров в адресе страницы: голые genre или view могли бы перехватить WordPress и тема
    var STAGES = dict({
      g: { name: 'Главная сцена', short: 'Главная', addr: 'Маросейка, 9/2', color: 'var(--st-g)', alias: 'главная сцена главный зал большой зал маросейка' },
      m: { name: 'Мансарда', short: 'Мансарда', addr: 'Маросейка, 9/2', color: 'var(--st-m)', alias: 'мансарда маросейка' },
      u: { name: 'Сцена на Мясницкой, 15', short: 'Мясницкая', addr: 'Мясницкая, 15', color: 'var(--st-u)', alias: 'сцена на мясницкой мясницкая мясницкой unplugged анплаггед анплагд' },
      b: { name: 'The Beat', short: 'The Beat', addr: 'Маросейка, 9/2', color: 'var(--st-b)', alias: 'the beat бит зе бит битс маросейка' }
    });
    var STAGE_ORDER = ['g', 'm', 'u', 'b'];
    var STAGE_SLUG = dict({ g: 'main', m: 'mansarda', u: 'myasnitskaya', b: 'beat' });
    // праздники по статье 112 Трудового кодекса: в календаре окрашены как выходные. Переносы выходных не учитываются
    var HOLI = dict({ '01-01': 'Новогодние каникулы', '01-02': 'Новогодние каникулы', '01-03': 'Новогодние каникулы', '01-04': 'Новогодние каникулы', '01-05': 'Новогодние каникулы', '01-06': 'Новогодние каникулы',
      '01-07': 'Рождество Христово', '01-08': 'Новогодние каникулы', '02-23': 'День защитника Отечества', '03-08': 'Международный женский день', '05-01': 'Праздник Весны и Труда',
      '05-09': 'День Победы', '06-12': 'День России', '11-04': 'День народного единства' });
    var MS = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    var MG = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    var MN = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    var WDS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    var CONC = ['концерт', 'концерта', 'концертов'];
    var BUDGETS = [null, 1000, 1500, 2000, 3000];
    var FORMATS = [['ticket', 'Билет'], ['deposit', 'Депозит'], ['free', 'Вход свободный']];
    var FSHORT = dict({ ticket: 'Билет', deposit: 'Депозит', free: 'Бесплатно' });
    var TITLES = dict({ date: 'Дата', price: 'Цена', genre: 'Жанр', stage: 'Сцена' });
    var STOP = dict({ 'и': 1, 'в': 1, 'на': 1, 'с': 1, 'во': 1, 'к': 1, 'у': 1 });
    // месяц и день недели из карточки темы («Окт | 14:00 | Суббота»), по-русски и по-английски
    var MONTH_IDX = dict({ 'янв': 0, 'фев': 1, 'мар': 2, 'апр': 3, 'мая': 4, 'май': 4, 'июн': 5, 'июл': 6, 'авг': 7, 'сен': 8, 'окт': 9, 'ноя': 10, 'дек': 11,
      'jan': 0, 'feb': 1, 'mar': 2, 'apr': 3, 'may': 4, 'jun': 5, 'jul': 6, 'aug': 7, 'sep': 8, 'oct': 9, 'nov': 10, 'dec': 11 });
    var WEEKDAY_IDX = dict({ 'понедельник': 1, 'вторник': 2, 'среда': 3, 'четверг': 4, 'пятница': 5, 'суббота': 6, 'воскресенье': 7,
      'monday': 1, 'tuesday': 2, 'wednesday': 3, 'thursday': 4, 'friday': 5, 'saturday': 6, 'sunday': 7 });
    var DAY = 86400000, REFRESH_MS = 5 * 60 * 1000, DEFAULT_MIN = 150;

    var ICO = {
      search: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/></svg>',
      cal: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>',
      chev: '<svg class="kaf-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
      x: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
      left: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg>',
      right: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>',
      list: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="3"/><rect x="3" y="10.5" width="18" height="3"/><rect x="3" y="17" width="18" height="3"/></svg>',
      grid: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="8"/><rect x="13" y="3" width="8" height="8"/><rect x="3" y="13" width="8" height="8"/><rect x="13" y="13" width="8" height="8"/></svg>'
    };

    /* ---------- состояние ---------- */
    var state = { q: '', qw: [], when: null, range: null, budget: null, formats: Object.create(null), genres: Object.create(null), stages: Object.create(null) };
    var cards = [], groups = [], events = [], genreNames = Object.create(null), genreOrder = [];
    var raw = null, loadedAt = 0, skew = 0, off = 3 * 3600, ready = false, dead = false, bgBusy = false, degraded = false, changes = 0;
    // тексты анонсов: 0 не загружены, 1 загружаются, 2 готовы, 3 недоступны (поиск идёт по названию, жанру и сцене)
    var texts = null, textsState = 0, textsVer = 0, textCache = Object.create(null);
    var host = null, hroot = null, layer = null, lroot = null, panel = null, pb = null, pf = null, scrim = null;
    var open = null, trigger = null, calMonth = null, vals = [];
    var listeners = [], intervals = [], hiddenList = [], saved = new WeakMap(), lockedOverflow = null, fontsAsked = false, urlTimer = 0, pokeTimer = 0, inputTimer = 0;
    var mq = win.matchMedia ? win.matchMedia('(max-width: 719px)') : { matches: false };

    /* ---------- защита: любая ошибка убирает панель и возвращает афишу ---------- */
    function guard(fn) {
      return function () {
        if (dead) return undefined;
        try { return fn.apply(this, arguments); } catch (e) { teardown(e); return undefined; }
      };
    }
    function on(target, type, fn, opts) {
      var g = guard(fn);
      target.addEventListener(type, g, opts || false);
      listeners.push([target, type, g, opts || false]);
    }
    function teardown(e) {
      if (dead) return;
      dead = true;
      try {
        listeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
        listeners = [];
        intervals.forEach(function (t) { clearInterval(t); });
        intervals = [];
        clearTimeout(urlTimer); clearTimeout(pokeTimer); clearTimeout(inputTimer);
        lockBody(false);
        showAll();
        if (host && host.parentNode) host.parentNode.removeChild(host);
        if (layer && layer.parentNode) layer.parentNode.removeChild(layer);
        host = hroot = layer = lroot = null;
      } catch (x) { /* уже нечего убирать */ }
      flag('off');
      try { if (cfg.preview && e && win.console) win.console.warn('[kozlov-afisha-filters] панель выключена:', e); } catch (x) { /* не критично */ }
    }

    /* ---------- прятать и возвращать элементы темы: только inline display, с запоминанием прежнего значения ---------- */
    function setHidden(el, hide) {
      if (!el) return;
      var st = saved.get(el);
      if (hide) {
        if (st && st.on) return;
        if (!st) { st = {}; saved.set(el, st); hiddenList.push(el); }
        st.on = true;
        changes++;
        st.raw = el.getAttribute('style'); // null, если атрибута не было
        st.d = el.style.getPropertyValue('display');
        st.p = el.style.getPropertyPriority('display');
        el.style.setProperty('display', 'none', 'important');
        st.after = el.getAttribute('style');
      } else if (st && st.on) {
        st.on = false;
        changes++;
        restoreDisplay(el, st);
      }
    }
    function restoreDisplay(el, st) {
      // после нас стиль никто не менял: возвращаем атрибут слово в слово
      if (el.getAttribute('style') === st.after) {
        if (st.raw === null) el.removeAttribute('style'); else el.setAttribute('style', st.raw);
        return;
      }
      // стиль успел поменять другой скрипт: возвращаем только display
      if (st.d) el.style.setProperty('display', st.d, st.p); else el.style.removeProperty('display');
      if (st.raw === null && !el.style.length) el.removeAttribute('style');
    }
    function showAll() {
      hiddenList.forEach(function (el) { var st = saved.get(el); if (st && st.on) { st.on = false; restoreDisplay(el, st); } });
      hiddenList = [];
      saved = new WeakMap();
    }

    /* ---------- общие помощники ---------- */
    function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function plural(n, f) { var a = n % 10, b = n % 100; return f[(a === 1 && b !== 11) ? 0 : (a >= 2 && a <= 4 && (b < 10 || b >= 20)) ? 1 : 2]; }
    function budgetLabel(b) { return 'до ' + fmtNum(b) + '\u00a0₽'; } // неразрывные пробелы: сумма не переносится
    function fmtNum(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0'); }
    function keyOf(ms) { return new Date(ms).toISOString().slice(0, 10); }
    function addDays(k, n) { var p = k.split('-'); return keyOf(Date.UTC(+p[0], +p[1] - 1, +p[2] + n)); }
    function isoDow(k) { var d = new Date(k + 'T00:00:00Z').getUTCDay(); return d === 0 ? 7 : d; }
    function norm(s) { return String(s || '').toLowerCase().replace(/ё/g, 'е').replace(/[«»"'’`“”&:;,.!?()\/\\–—\-+%]+/g, ' ').replace(/\s+/g, ' ').trim(); }
    function count(o) { var n = 0; for (var k in o) if (o[k]) n++; return n; }
    function keys(o) { var a = []; for (var k in o) if (o[k]) a.push(k); return a; }
    function focusQuiet(el) { if (!el) return; try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } }
    function decode(s) { try { return decodeURIComponent(s); } catch (e) { return s; } }

    function parsePrice(s) {
      var rawp = String(s || '').trim(), low = rawp.toLowerCase();
      var m = rawp.match(/\d[\d\s]*\d|\d/);
      var amt = m ? parseInt(m[0].replace(/\s/g, ''), 10) : null;
      if (!rawp) return { type: 'unknown', amt: null };
      if (low.indexOf('свобод') >= 0 || low.indexOf('бесплат') >= 0) return { type: 'free', amt: 0 };
      if (low.indexOf('депозит') >= 0) return { type: 'deposit', amt: amt };
      if (amt === null) return { type: 'unknown', amt: null };
      return { type: 'ticket', amt: amt };
    }

    /* поиск с опечатками: Дамерау-Левенштейн с ранним выходом */
    function lev(a, b, max) {
      var la = a.length, lb = b.length, i, j;
      if (Math.abs(la - lb) > max) return max + 1;
      var p2 = null, p1 = [];
      for (j = 0; j <= lb; j++) p1.push(j);
      for (i = 1; i <= la; i++) {
        var cur = [i], best = i;
        for (j = 1; j <= lb; j++) {
          var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
          var v = Math.min(p1[j] + 1, cur[j - 1] + 1, p1[j - 1] + cost);
          if (p2 && i > 1 && j > 1 && a.charAt(i - 1) === b.charAt(j - 2) && a.charAt(i - 2) === b.charAt(j - 1)) v = Math.min(v, p2[j - 2] + 1);
          cur.push(v); if (v < best) best = v;
        }
        if (best > max) return max + 1;
        p2 = p1; p1 = cur;
      }
      return p1[lb];
    }
    function wordMatch(w, toks) {
      var k = w.length >= 8 ? 2 : w.length >= 5 ? 1 : 0;
      for (var i = 0; i < toks.length; i++) {
        var t = toks[i];
        if (t.indexOf(w) === 0) return true;
        if (w.length >= 4 && t.indexOf(w) >= 0) return true;
        if (k) {
          if (lev(w, t, k) <= k) return true;
          if (t.length > w.length && lev(w, t.slice(0, w.length), k) <= k) return true;
        }
      }
      return false;
    }
    /* ---------- тексты анонсов: слово запроса ищется точно, началом слова или с другим окончанием, без опечаток (как в Kozlov Smart Search) ---------- */
    function stemRu(w) {
      if (w.length < 5) return '';
      var m = w.match(/^(.{4,}?)(ами|ями|ого|его|ому|ему|ыми|ими|ой|ей|ом|ем|ым|им|ах|ях|ов|ев|ам|ям|ую|юю|ая|яя|ое|ее|ые|ие|а|я|ы|и|у|ю|е|о|ь)$/);
      return m ? m[1] : '';
    }
    function textDocs(w) {
      if (textCache[w]) return textCache[w];
      var out = Object.create(null), st = stemRu(w), i, j;
      textCache[w] = out;
      if (!texts || w.length < 3 || /^\d/.test(w)) return out;
      for (i = 0; i < texts.words.length; i++) {
        var x = texts.words[i];
        if (x === w || (w.length >= 4 && x.lastIndexOf(w, 0) === 0) || (st && x.lastIndexOf(st, 0) === 0)) {
          var list = texts.post[i];
          if (list) for (j = 0; j < list.length; j++) out[list[j]] = true;
        }
      }
      return out;
    }
    function inText(w, tid) { return tid >= 0 && !!texts && !!textDocs(w)[tid]; }
    function parseQuery(q) { return norm(q).split(' ').filter(function (w) { return w && (w.length > 1 ? !STOP[w] : /\d/.test(w)); }); }

    /* ---------- время: часы клуба. Индекс даёт смещение off и время сервера now ---------- */
    function wallNow() { return Date.now() + skew + off * 1000; }
    function weekendKeys(today) {
      var dw = isoDow(today), from = dw <= 4 ? addDays(today, 5 - dw) : today, to = addDays(today, 7 - dw), out = [];
      for (var k = from; k <= to; k = addDays(k, 1)) out.push(k);
      return out;
    }
    function ctx() { var N = wallNow(), today = keyOf(N); return { N: N, today: today, tomorrow: addDays(today, 1), weekend: weekendKeys(today) }; }
    function isLive(e, c) { return c.N >= e.start && c.N < e.end; }
    function inWhen(e, c, when, range) {
      switch (when) {
        case 'today': return e.date === c.today || isLive(e, c);
        case 'tomorrow': return e.date === c.tomorrow;
        case 'weekend': return c.weekend.indexOf(e.date) >= 0 || (isLive(e, c) && c.weekend.indexOf(c.today) >= 0);
        case 'range': return !!range && e.date >= range.from && e.date <= range.to;
        default: return true;
      }
    }
    function budgetOk(e, b) { return b === null || (e.p.amt !== null && e.p.amt <= b); }
    function matches(e, c, skip) {
      if (c.N >= e.end) return false; // концерт закончился
      if (skip !== 'when' && !inWhen(e, c, state.when, state.range)) return false;
      if (state.qw.length) {
        var qk = state.q + '|' + textsVer;
        if (e.qk !== qk) { e.qk = qk; e.qok = state.qw.every(function (w) { return wordMatch(w, e.toks) || inText(w, e.tid); }); } // считается один раз на запрос
        if (!e.qok) return false;
      }
      if (skip !== 'budget' && !budgetOk(e, state.budget)) return false;
      if (skip !== 'format' && count(state.formats) && !state.formats[e.p.type]) return false;
      if (skip !== 'genre' && count(state.genres) && !e.g.some(function (g) { return state.genres[g]; })) return false;
      if (skip !== 'stage' && count(state.stages) && !e.st.split('').some(function (s) { return state.stages[s]; })) return false;
      return true;
    }
    function anyActive() { return !!(state.when || state.budget !== null || count(state.formats) || count(state.genres) || count(state.stages) || state.q); }

    function fmtDay(k) { var p = k.split('-'); return +p[2] + ' ' + MG[+p[1] - 1]; }
    function fmtRange(a, b) {
      if (a === b) return fmtDay(a);
      var pa = a.split('-'), pb2 = b.split('-');
      return (pa[0] === pb2[0] && pa[1] === pb2[1]) ? (+pa[2]) + '–' + (+pb2[2]) + ' ' + MG[+pa[1] - 1] : fmtDay(a) + ' – ' + fmtDay(b);
    }
    function shortRange(r) {
      var s = function (k) { var p = k.split('-'); return +p[2] + ' ' + MS[+p[1] - 1]; };
      if (r.from === r.to) return s(r.from);
      var a = r.from.split('-'), b = r.to.split('-');
      return a[1] === b[1] ? (+a[2]) + '–' + (+b[2]) + ' ' + MS[+a[1] - 1] : s(r.from) + ' – ' + s(r.to);
    }
    /** Заголовок по датам: [начало, даты]. Даты выводятся без переноса внутри. */
    function headingForWhen(c) {
      switch (state.when) {
        case 'today': return ['Концерты сегодня,', fmtDay(c.today)];
        case 'tomorrow': return ['Концерты завтра,', fmtDay(c.tomorrow)];
        case 'weekend': return ['Концерты в выходные,', fmtRange(c.weekend[0], c.weekend[c.weekend.length - 1])];
        case 'range': return ['Концерты', fmtRange(state.range.from, state.range.to)];
        default: return ['', ''];
      }
    }

    /* ---------- карточки афиши темы: ключ «slug|ГГГГ-ММ-ДД|ЧЧ:ММ» ---------- */
    function slugOf(href) {
      try {
        var m = new URL(href, win.location.href).pathname.match(/\/event\/([^\/]+)\/?$/);
        return m ? decode(m[1]).toLowerCase() : '';
      } catch (e) { return ''; }
    }
    function monthOf(txt) {
      var w = String(txt || '').toLowerCase().match(/[a-zа-яё]{3,}/g) || [];
      for (var i = 0; i < w.length; i++) { var m = MONTH_IDX[w[i].slice(0, 3)]; if (m !== undefined) return m; }
      return -1;
    }
    function weekdayOf(txt) {
      var w = String(txt || '').toLowerCase().match(/[a-zа-яё]+/g) || [];
      for (var i = 0; i < w.length; i++) { var d = WEEKDAY_IDX[w[i]]; if (d !== undefined) return d; }
      return 0;
    }
    /** Год в карточке не пишется: берётся год, в котором дата попадает в ближайший год афиши и совпадает день недели. */
    function cardDate(day, mon, wd) {
      if (mon < 0) return '';
      var base = Date.now() + off * 1000, today = Date.UTC(new Date(base).getUTCFullYear(), new Date(base).getUTCMonth(), new Date(base).getUTCDate());
      var y0 = new Date(base).getUTCFullYear(), found = [];
      for (var y = y0 - 1; y <= y0 + 1; y++) {
        var ms = Date.UTC(y, mon, day), dt = new Date(ms);
        if (dt.getUTCMonth() !== mon || dt.getUTCDate() !== day) continue; // 31 ноября и подобное
        if (ms < today - 2 * DAY || ms > today + 400 * DAY) continue;
        if (wd && isoDow(keyOf(ms)) !== wd) continue;
        found.push(keyOf(ms));
      }
      return found.length === 1 || (found.length > 1 && !wd) ? found[0] : ''; // неоднозначно: карточка без ключа
    }
    function collectCards() {
      var month = null;
      for (var i = 0; i < wrap.children.length; i++) {
        var el = wrap.children[i];
        if (el.classList.contains('event_month')) { month = el; continue; }
        if (!el.classList.contains('events')) continue;
        var grp = { cont: el, month: month, cards: [] };
        month = null;
        for (var j = 0; j < el.children.length; j++) {
          var card = el.children[j];
          if (!card.classList.contains('event')) continue;
          var a = card.querySelector('a.title'), c = card.querySelector('.cell_1'), r = card.querySelector('.row_1');
          var slug = a ? slugOf(a.getAttribute('href') || '') : '';
          var day = c ? parseInt((c.textContent || '').replace(/\D/g, ''), 10) : 0;
          var rowTxt = r ? (r.textContent || '') : '';
          var tm = rowTxt.match(/(\d{1,2}):(\d\d)/);
          var c2 = card.querySelector('.cell_2'); // в плитке день недели в той же строке, в списке строкой ниже
          var date = (day && tm) ? cardDate(day, monthOf(rowTxt), weekdayOf(c2 ? c2.textContent : rowTxt)) : '';
          var item = { el: card, grp: grp, ev: null, key: '', start: 0 };
          if (slug && date) {
            var hh = ('0' + tm[1]).slice(-2);
            item.key = slug + '|' + date + '|' + hh + ':' + tm[2];
            item.start = Date.UTC(+date.slice(0, 4), +date.slice(5, 7) - 1, +date.slice(8, 10), +hh, +tm[2]); // те же «часы афиши», что start в базе
          }
          grp.cards.push(item);
          cards.push(item);
        }
        groups.push(grp);
      }
    }

    /* ---------- концерты из индекса, привязанные к карточкам ---------- */
    function buildEvents(d) {
      var byKey = Object.create(null), out = [], gcount = Object.create(null);
      genreNames = Object.create(null);
      cards.forEach(function (c) { c.ev = null; if (c.key) (byKey[c.key] || (byKey[c.key] = [])).push(c); });
      var gnames = (d.genres && typeof d.genres === 'object' && !Array.isArray(d.genres)) ? d.genres : {};
      d.ev.forEach(function (e, i) {
        if (!Array.isArray(e) || typeof e[0] !== 'string' || typeof e[1] !== 'string' || !/^\d{4}-\d\d-\d\d$/.test(e[1]) || typeof e[2] !== 'string' || !/^\d\d:\d\d$/.test(e[2]) || typeof e[5] !== 'string') return;
        var key = decode(e[5]).toLowerCase() + '|' + e[1] + '|' + e[2];
        var list = byKey[key];
        if (!list || !list.length) return; // концерта нет на афише этой страницы
        var card = list.shift();
        var y = +e[1].slice(0, 4), mo = +e[1].slice(5, 7), dd = +e[1].slice(8, 10), hh = +e[2].slice(0, 2), mi = +e[2].slice(3, 5);
        var nominal = Date.UTC(y, mo - 1, dd, hh, mi);
        var night = e[2] === '23:59'; // полночь вносится как 23:59, чтобы концерт остался в своей дате
        var dur = (typeof e[12] === 'number' && e[12] > 0 && e[12] <= 4320) ? e[12] : DEFAULT_MIN;
        var g = (Array.isArray(e[8]) ? e[8] : []).filter(function (x) { return typeof x === 'string' && Object.prototype.hasOwnProperty.call(gnames, x); });
        var st = String(e[3] || '').split('').filter(function (s) { return STAGES[s]; }).join('');
        var names = g.map(function (x) { return String(gnames[x]); });
        var stg = st.split('').map(function (s) { return STAGES[s].name + ' ' + STAGES[s].alias; }).join(' ');
        var ev = {
          i: i, t: e[0], date: e[1], st: st, g: g, p: parsePrice(e[4]), card: card,
          start: night ? Date.UTC(y, mo - 1, dd + 1, 0, 0) : nominal,
          end: nominal + dur * 60000,
          toks: norm([e[0], names.join(' '), stg].join(' ')).split(' '),
          tid: (typeof e[9] === 'number' && e[9] >= 0) ? e[9] : -1 // номер текста анонса
        };
        card.ev = ev;
        g.forEach(function (x) { gcount[x] = (gcount[x] || 0) + 1; genreNames[x] = String(gnames[x]); });
        out.push(ev);
      });
      genreOrder = Object.keys(gcount).sort(function (a, b) { return gcount[b] - gcount[a]; });
      return out;
    }

    /* ---------- загрузка индекса ---------- */
    function getIndex(fresh, cb) { getJSON(cfg.index, fresh, cb); }
    function getJSON(url, fresh, cb) {
      var headers = { 'Accept': 'application/json' };
      if (cfg.nonce) headers['X-WP-Nonce'] = String(cfg.nonce);
      var ctrl = win.AbortController ? new win.AbortController() : null;
      var timer = setTimeout(function () { if (ctrl) ctrl.abort(); }, 15000);
      // те же адреса, что у Kozlov Smart Search: браузер берёт одну и ту же копию индекса и текстов
      var u = url + (url.indexOf('?') < 0 ? '?' : '&') + 'kss_t=' + Math.floor(Date.now() / REFRESH_MS);
      win.fetch(u, { credentials: 'same-origin', headers: headers, cache: fresh ? 'reload' : 'default', signal: ctrl ? ctrl.signal : undefined })
        .then(function (r) {
          if (!r.ok) { var er = new Error('http ' + r.status); er.status = r.status; throw er; }
          return r.json();
        })
        .then(function (d) { clearTimeout(timer); cb(null, d); }, function (e) { clearTimeout(timer); cb(e || new Error('fetch')); });
    }
    function validIndex(d) { return !!d && d.v === 1 && Array.isArray(d.ev); }
    /* 403: поиск выключен; 404 и 410: маршрута нет. Это решение сайта: панель убирается. */
    function refused(err) { return !!err && (err.status === 403 || err.status === 404 || err.status === 410); }
    function accept(d) {
      raw = d; loadedAt = Date.now();
      off = (typeof d.off === 'number' && isFinite(d.off)) ? d.off : off;
      var diff = (typeof d.now === 'number' && d.now > 1.6e9) ? d.now * 1000 - Date.now() : 0; // время сервера, если оно правдоподобно
      skew = Math.abs(diff) > 20 * 60 * 1000 ? diff : 0;
      events = buildEvents(d);
      for (var gk in state.genres) if (!genreNames[gk]) delete state.genres[gk]; // жанр из адреса, которого нет в афише
      if ((texts && texts.built !== d.built) || textsState === 3) dropTexts(); // номера текстов действуют только внутри одной сборки
      if (state.qw.length) loadTexts(false);
    }
    function dropTexts() { texts = null; textsState = 0; textsVer++; textCache = Object.create(null); }
    /** Тексты анонсов грузятся, когда посетитель берётся за поиск. Они необязательны: без них поиск идёт по названию, жанру и сцене. */
    function loadTexts(fresh) {
      if (!cfg.texts || !raw || !raw.texts || textsState === 1 || textsState === 2 || (textsState === 3 && !fresh)) return;
      textsState = 1;
      var built = raw.built;
      getJSON(cfg.texts, fresh, guard(function (err, d) {
        if (!raw || raw.built !== built) { textsState = 0; if (state.qw.length) loadTexts(false); return; } // индекс успел обновиться
        if (err || !d || d.v !== 1 || typeof d.vocab !== 'string' || !Array.isArray(d.docs)) { textsState = 3; apply(false); return; }
        if (d.built !== built) {
          // тексты из другой сборки (один из ответов взят из кэша браузера): один раз запрашиваем заново
          textsState = fresh ? 3 : 0;
          if (fresh) apply(false); else loadTexts(true);
          return;
        }
        var words = d.vocab ? d.vocab.split(' ') : [], post = new Array(words.length);
        d.docs.forEach(function (deltas, t) {
          var id = 0;
          if (Array.isArray(deltas)) deltas.forEach(function (dl) { id += dl; if (id >= 0 && id < words.length) (post[id] || (post[id] = [])).push(t); });
        });
        texts = { words: words, post: post, built: built };
        textsState = 2; textsVer++; textCache = Object.create(null);
        try { cfgEl.setAttribute('data-kaf-texts', String(words.length)); } catch (e) { /* не критично */ }
        if (state.qw.length) apply(false);
      }));
    }
    /** Режим панели приходит в ответе индекса (поле kaf добавляет PHP-часть плагина). Нет поля или режим выключен: панель убирается. */
    function allowed(d) { return d.kaf === 'on' || (d.kaf === 'preview' && !!cfg.preview); }
    /**
     * Сверка карточек страницы с индексом. Индекс содержит концерты, которые ещё не начались к моменту его сборки (как и афиша темы).
     * Карточка без записи в индексе допустима, только если концерт начался до сборки индекса: тема тоже уберёт её при обновлении страницы.
     * Остальные карточки без записи значат, что страница и индекс разошлись: фильтровать по таким данным нельзя.
     */
    function coverage(d) {
      var builtMs = (typeof d.built === 'number' ? d.built : 0) * 1000, matched = 0, bad = 0;
      cards.forEach(function (cd) {
        if (!cd.el.isConnected) return; // тема могла удалить карточку своим скриптом
        if (cd.ev) { matched++; return; }
        if (cd.key && cd.start <= builtMs + 60000) return;
        bad++;
      });
      return { matched: matched, bad: bad };
    }
    function setDegraded(v) {
      if (v !== degraded && ready) flag(v ? 'degraded' : 'ready');
      degraded = v;
    }
    function load(fresh, attempt) {
      getIndex(fresh, guard(function (err, d) {
        if (err || !validIndex(d)) {
          // повтор идёт мимо кэша браузера: там мог остаться ответ защиты хостинга вместо индекса
          if (!refused(err) && !attempt) { setTimeout(guard(function () { load(true, 1); }), 1000); return; }
          teardown(err || new Error('bad index'));
          return;
        }
        if (!allowed(d)) { teardown(new Error('panel is off')); return; }
        accept(d);
        var cov = coverage(d);
        if (!cov.matched) { teardown(new Error('no cards matched')); return; } // разметка темы изменилась
        if (cov.bad && !fresh) { load(true, 1); return; } // ещё раз мимо кэша браузера: индекс мог быть старше страницы
        degraded = cov.bad > 0;
        ready = true;
        flag(degraded ? 'degraded' : 'ready');
        apply(false);
        startRefresh();
      }));
    }
    function startRefresh() {
      var due = function () { return ready && !bgBusy && Date.now() - loadedAt >= REFRESH_MS && (!doc.visibilityState || doc.visibilityState === 'visible'); };
      var check = function () {
        if (!due()) return;
        bgBusy = true;
        getIndex(true, guard(function (err, d) {
          bgBusy = false;
          if (refused(err)) { teardown(err); return; }
          if (err || !validIndex(d)) return; // временный сбой: работаем на прежних данных
          if (!allowed(d)) { teardown(new Error('panel is off')); return; } // панель выключили в настройках
          if (raw && d.built === raw.built) { loadedAt = Date.now(); tick(); return; }
          accept(d);
          var cov = coverage(d);
          if (!cov.matched) { teardown(new Error('no cards matched')); return; }
          setDegraded(cov.bad > 0);
          apply(false);
        }));
      };
      // раз в минуту: проверка индекса и пересчёт по времени (смена суток, закончившиеся концерты)
      intervals.push(setInterval(guard(function () { check(); tick(); }), 60 * 1000));
      on(doc, 'visibilitychange', function () { check(); tick(); });
    }
    function tick() { if (ready && !open && !dead) apply(false); }

    /* ---------- адрес страницы ---------- */
    function readUrl() {
      var u;
      try { u = new URLSearchParams(win.location.search); } catch (e) { return; }
      var g = function (k) { return u.get(P + k) || ''; }, re = /^\d{4}-\d\d-\d\d$/;
      var w = g('when');
      if (w === 'today' || w === 'tomorrow' || w === 'weekend') state.when = w;
      var f = g('from'), t = g('to');
      if (re.test(f)) { state.when = 'range'; state.range = { from: f, to: (re.test(t) && t >= f) ? t : f }; }
      var p = Number(g('price'));
      if (BUDGETS.indexOf(p) > 0) state.budget = p;
      g('entry').split(',').forEach(function (x) { if (FSHORT[x]) state.formats[x] = true; });
      g('genre').split(',').forEach(function (x) { if (/^[a-z0-9 _-]{1,40}$/i.test(x)) state.genres[x] = true; }); // проверяются по индексу после загрузки
      g('stage').split(',').forEach(function (x) { for (var k in STAGE_SLUG) if (STAGE_SLUG[k] === x) state.stages[k] = true; });
      var q = g('q').slice(0, 80);
      if (q) { state.q = q; state.qw = parseQuery(q); }
    }
    function urlWith(extra) {
      var u = new URLSearchParams(win.location.search);
      ['when', 'from', 'to', 'price', 'entry', 'genre', 'stage', 'q'].forEach(function (k) { u.delete(P + k); });
      if (state.when && state.when !== 'range') u.set(P + 'when', state.when);
      if (state.when === 'range' && state.range) { u.set(P + 'from', state.range.from); if (state.range.to !== state.range.from) u.set(P + 'to', state.range.to); }
      if (state.budget !== null) u.set(P + 'price', String(state.budget));
      if (count(state.formats)) u.set(P + 'entry', keys(state.formats).join(','));
      if (count(state.genres)) u.set(P + 'genre', keys(state.genres).join(','));
      if (count(state.stages)) u.set(P + 'stage', keys(state.stages).map(function (k) { return STAGE_SLUG[k]; }).join(','));
      if (state.q) u.set(P + 'q', state.q);
      if (extra) for (var k in extra) u.set(k, extra[k]);
      var qs = u.toString();
      return win.location.pathname + (qs ? '?' + qs : '') + win.location.hash;
    }
    function writeUrl() {
      try {
        var u = urlWith(null);
        if (u !== win.location.pathname + win.location.search + win.location.hash) win.history.replaceState(win.history.state, '', u);
      } catch (e) { /* адрес не меняется: фильтры всё равно работают */ }
    }

    /* ---------- шрифт окон: Roboto Condensed из папки плагина, регистрируется без CSS на странице ---------- */
    function loadFonts() {
      if (fontsAsked || !cfg.fonts || !win.FontFace || !doc.fonts || !doc.fonts.add) return;
      fontsAsked = true;
      var R = {
        cyrillic: 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116',
        latin: 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'
      };
      ['600', '700'].forEach(function (w) {
        ['cyrillic', 'latin'].forEach(function (s) {
          try {
            var f = new win.FontFace('KAF Roboto Condensed', 'url(' + cfg.fonts + 'roboto-condensed-' + s + '-' + w + '-normal.woff2) format("woff2")', { weight: w, style: 'normal', unicodeRange: R[s], display: 'swap' });
            doc.fonts.add(f);
            f.load().catch(function () { /* без шрифта окна покажутся шрифтом Roboto */ });
          } catch (e) { /* не критично */ }
        });
      });
    }

    /* ---------- разметка ---------- */
    function mount() {
      host = doc.createElement('div');
      host.id = 'kaf-filters';
      anchor.parentNode.insertBefore(host, anchor.nextSibling);
      hroot = host.attachShadow({ mode: 'open' });
      var dd = function (v, label, icon) {
        return '<button type="button" class="kaf-c kaf-dd" data-act="open" data-v="' + v + '" aria-haspopup="dialog" aria-expanded="false">' + (icon || '') +
          '<span class="kaf-l">' + label + '</span><span class="kaf-k">' + label + '</span>' + ICO.chev + '</button>';
      };
      var q = function (v, label) { return '<button type="button" class="kaf-c" data-act="when" data-v="' + v + '" aria-pressed="false">' + label + ' <span class="kaf-n"></span></button>'; };
      hroot.innerHTML = '<style>' + CSS + '</style><div class="kaf">' +
        '<div class="kaf-bar">' +
          '<label class="kaf-search">' + ICO.search + '<input class="kaf-q" type="search" placeholder="Артист или концерт" aria-label="Поиск по афише: артист, концерт, жанр или сцена" autocomplete="off" enterkeyhint="search" maxlength="80"></label>' +
          '<div class="kaf-chips" role="group" aria-label="Фильтры афиши">' +
            q('today', 'Сегодня') + q('tomorrow', 'Завтра') + q('weekend', 'Выходные') + '<span class="kaf-sep" aria-hidden="true"></span>' +
            dd('date', 'Дата', ICO.cal) + dd('price', 'Цена') + dd('genre', 'Жанр') + dd('stage', 'Сцена') +
          '</div>' +
        '</div>' +
        '<p class="kaf-wait" hidden>Подбираем концерты…</p>' +
        '<div class="kaf-note" role="status" hidden><span>Фильтры временно недоступны: афиша на странице обновилась. Показаны все концерты.</span>' +
          '<button type="button" class="kaf-btn" data-act="reload">Обновить страницу</button></div>' +
        '<div class="kaf-top" hidden><span class="kaf-vals"></span><div class="kaf-head"></div>' +
          '<div class="kaf-right"><button type="button" class="kaf-reset" data-act="clear" data-v="all">Сбросить<span class="kaf-rw"> фильтры</span>' + ICO.x + '</button>' +
          '<nav class="kaf-views" aria-label="Вид афиши"><a class="kaf-v" data-view="line" aria-label="Списком">' + ICO.list + '</a><a class="kaf-v" data-view="cell" aria-label="Плиткой">' + ICO.grid + '</a></nav></div>' +
        '</div>' +
        '<div class="kaf-empty" hidden><strong>Ничего не нашлось</strong><span>Под эти условия концертов нет. Уберите один из фильтров или измените запрос.</span>' +
          '<button type="button" class="kaf-btn" data-act="clear" data-v="all">Сбросить фильтры</button></div>' +
        '<div class="kaf-sr" aria-live="polite"></div>' +
      '</div>';
      on(hroot, 'click', onClick);
      on(hroot, 'keydown', onKey);
      var qIn = hroot.querySelector('.kaf-q');
      if (state.q) qIn.value = state.q;
      on(qIn, 'focus', function () { loadTexts(false); });
      on(qIn, 'input', function () {
        state.q = qIn.value.trim(); state.qw = parseQuery(state.q);
        if (state.qw.length) loadTexts(false);
        clearTimeout(inputTimer);
        inputTimer = setTimeout(guard(function () { apply(true); }), 120);
      });
      on(qIn, 'keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); qIn.blur(); } });
    }
    function ensureLayer() {
      if (layer) return;
      layer = doc.createElement('div');
      layer.id = 'kaf-layer';
      // свой слой поверх страницы: окна не зависят от z-index и overflow блоков темы
      layer.style.cssText = 'position:fixed;left:0;top:0;width:0;height:0;z-index:2147483000;';
      doc.body.appendChild(layer);
      lroot = layer.attachShadow({ mode: 'open' });
      lroot.innerHTML = '<style>' + CSS + '</style><div class="kaf">' +
        '<div class="kaf-scrim" hidden></div>' +
        '<div class="kaf-panel" role="dialog" aria-modal="true" aria-labelledby="kaf-pt" hidden>' +
          '<div class="kaf-handle" aria-hidden="true"></div>' +
          '<div class="kaf-phd"><h3 id="kaf-pt"></h3><button type="button" class="kaf-x" data-act="close" aria-label="Закрыть">' + ICO.x + '</button></div>' +
          '<div class="kaf-pb"></div><div class="kaf-pf"></div>' +
        '</div></div>';
      panel = lroot.querySelector('.kaf-panel'); pb = lroot.querySelector('.kaf-pb'); pf = lroot.querySelector('.kaf-pf'); scrim = lroot.querySelector('.kaf-scrim');
      on(lroot, 'click', onClick);
      on(lroot, 'keydown', onKey);
    }

    /* ---------- кнопки фильтров: обновляются на месте, чтобы не терять фокус ---------- */
    function setDD(f, label, isOn, dot, full) {
      var b = hroot.querySelector('.kaf-dd[data-v="' + f + '"]');
      b.querySelector('.kaf-l').textContent = label;
      var d = b.querySelector('.kaf-bdot');
      if (dot && !d) { d = doc.createElement('i'); d.className = 'kaf-dot kaf-bdot'; b.insertBefore(d, b.firstChild); }
      if (d) { d.hidden = !dot; if (dot) d.style.background = dot; }
      b.classList.toggle('kaf-on', isOn);
      b.setAttribute('aria-expanded', String(open === f));
      b.setAttribute('aria-label', isOn ? TITLES[f] + ': ' + (full || label) : TITLES[f]);
      b.title = isOn ? (full || label) : '';
      if (isOn) vals.push(full || label);
    }
    function updateBar(c) {
      vals = [];
      if (state.when && state.when !== 'range') vals.push({ today: 'Сегодня', tomorrow: 'Завтра', weekend: 'Выходные' }[state.when]);
      Array.prototype.forEach.call(hroot.querySelectorAll('.kaf-c[data-act="when"]'), function (b) {
        var isOn = state.when === b.dataset.v;
        b.classList.toggle('kaf-on', isOn);
        b.setAttribute('aria-pressed', String(isOn));
        b.querySelector('.kaf-n').textContent = ready ? String(events.filter(function (e) { return matches(e, c, 'when') && inWhen(e, c, b.dataset.v, null); }).length) : '';
      });
      setDD('date', state.when === 'range' && state.range ? shortRange(state.range) : 'Дата', state.when === 'range', null, state.when === 'range' && state.range ? fmtRange(state.range.from, state.range.to) : '');
      var more = function (n) { return n > 0 ? ' +' + n : ''; };
      var fs = FORMATS.filter(function (f) { return state.formats[f[0]]; });
      var budgetTxt = state.budget !== null ? budgetLabel(state.budget) : '';
      var priceShort = budgetTxt ? budgetTxt + more(fs.length) : fs.length ? FSHORT[fs[0][0]] + more(fs.length - 1) : 'Цена';
      setDD('price', priceShort, !!(budgetTxt || fs.length), null, [budgetTxt, fs.map(function (f) { return f[1]; }).join(', ')].filter(Boolean).join(', '));
      var gs = keys(state.genres).filter(function (k) { return !ready || genreNames[k]; }), ss = keys(state.stages);
      setDD('genre', gs.length ? (genreNames[gs[0]] || gs[0]) + more(gs.length - 1) : 'Жанр', gs.length > 0, null, gs.map(function (k) { return genreNames[k] || k; }).join(', '));
      setDD('stage', ss.length ? STAGES[ss[0]].short + more(ss.length - 1) : 'Сцена', ss.length > 0, ss.length ? STAGES[ss[0]].color : null, ss.map(function (k) { return STAGES[k].name; }).join(', '));
    }

    /* ---------- применение: какие карточки темы видны ---------- */
    function currentView() {
      var a = viewtabs && viewtabs.querySelector('.tab_line.active, .tab_cell.active');
      if (a) return a.classList.contains('tab_line') ? 'line' : 'cell';
      var ew = wrap.parentNode;
      return (ew && ew.classList && ew.classList.contains('view_line')) ? 'line' : 'cell';
    }
    function setDisabled(dis) {
      Array.prototype.forEach.call(hroot.querySelectorAll('.kaf-c, .kaf-q'), function (b) { b.disabled = dis; });
    }
    function setText(sel, text, html) {
      var el = hroot.querySelector(sel);
      if (html) { if (el.innerHTML !== text) el.innerHTML = text; } else if (el.textContent !== text) el.textContent = text;
    }
    function apply(fromInput) {
      if (!hroot) return;
      var c = ctx(), active = anyActive();
      var top = hroot.querySelector('.kaf-top'), wait = hroot.querySelector('.kaf-wait'), empty = hroot.querySelector('.kaf-empty'), note = hroot.querySelector('.kaf-note');
      changes = 0;
      updateBar(c);
      if (ready && degraded) {
        // страница и индекс разошлись: вся афиша как без плагина, фильтры отключены до обновления данных
        if (open) closePanel(false);
        showAll();
        wait.hidden = true; top.hidden = true; empty.hidden = true; note.hidden = false;
        setDisabled(true);
        setText('.kaf-sr', '');
        return;
      }
      note.hidden = true;
      setDisabled(false);
      if (!ready) {
        // до загрузки индекса: если фильтры пришли из адреса, афишу сразу прячем, чтобы она не мигала
        setText('.kaf-wait', 'Подбираем концерты…');
        wait.hidden = !active;
        groups.forEach(function (g) { setHidden(g.cont, active); setHidden(g.month, active); });
        setHidden(viewtabs, active);
        return;
      }
      wait.hidden = true;
      var n = 0, dated = !!state.when;
      cards.forEach(function (cd) {
        if (!cd.el.isConnected) { cd.vis = false; return; } // тема могла удалить карточку своим скриптом
        var vis = !active || (!!cd.ev && matches(cd.ev, c, null));
        setHidden(cd.el, !vis);
        cd.vis = vis;
        if (vis) n++;
      });
      groups.forEach(function (g) {
        var any = g.cards.some(function (cd) { return cd.vis; });
        setHidden(g.cont, active && !any);
        // при фильтре по датам заголовки месяцев заменяет наш заголовок с датами
        setHidden(g.month, active && (dated || !any));
      });
      setHidden(viewtabs, active); // при фильтре переключатель вида показывает панель: он сохраняет выбранные фильтры
      top.hidden = !active;
      var searching = active && n === 0 && state.qw.length > 0 && textsState === 1; // ответ может найтись в анонсах
      empty.hidden = !(active && n === 0) || searching;
      if (searching) setText('.kaf-wait', 'Ищем в анонсах…');
      wait.hidden = !searching;
      if (active) {
        var word = plural(n, CONC);
        setText('.kaf-head', dated
          ? (function (h) { return '<h2 class="kaf-h">' + esc(h[0]) + ' <span class="kaf-nw">' + esc(h[1]) + '<small>' + n + '</small></span></h2>'; })(headingForWhen(c))
          : '<p class="kaf-found">Найдено <b>' + n + '</b> ' + word + '</p>', true);
        setText('.kaf-vals', (state.q ? ['«' + state.q + '»'] : []).concat(vals).join(' · '));
        var view = currentView();
        Array.prototype.forEach.call(hroot.querySelectorAll('.kaf-v'), function (a) {
          var v = a.getAttribute('data-view');
          a.setAttribute('href', urlWith({ view: v }));
          a.setAttribute('aria-current', String(v === view));
        });
        setText('.kaf-sr', 'Найдено ' + n + ' ' + word); // читалка экрана объявит число, только если оно изменилось
      } else {
        setText('.kaf-sr', '');
      }
      if (open) renderPanel(c);
      clearTimeout(urlTimer);
      urlTimer = setTimeout(guard(writeUrl), fromInput ? 400 : 0);
      // ленивая загрузка картинок темы проверяет экран при прокрутке: показанные карточки должны получить фото.
      // a3 Lazy Load (Lazy Load XT) слушает прокрутку на document, другие загрузчики на window
      if (changes) {
        clearTimeout(pokeTimer);
        pokeTimer = setTimeout(function () { try { doc.dispatchEvent(new Event('scroll')); win.dispatchEvent(new Event('scroll')); } catch (e) { /* не критично */ } }, 60);
      }
    }

    /* ---------- окна ---------- */
    function opt(act, v, label, n, checked, radio, sub, dot) {
      var dis = n === 0 && !checked;
      return '<button type="button" class="kaf-opt" role="' + (radio ? 'radio' : 'checkbox') + '" aria-checked="' + checked + '" data-act="' + act + '" data-v="' + esc(v) + '"' + (dis ? ' disabled' : '') + '>' +
        '<span class="kaf-box' + (radio ? ' kaf-rd' : '') + '"></span>' + (dot ? '<i class="kaf-dot" style="background:' + dot + '"></i>' : '') +
        '<span class="kaf-tx">' + esc(label) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</span><span class="kaf-n">' + n + '</span></button>';
    }
    function calHTML(c) {
      var y = calMonth[0], m = calMonth[1];
      var first = keyOf(Date.UTC(y, m, 1)), days = new Date(Date.UTC(y, m + 1, 0)).getUTCDate(), lead = isoDow(first) - 1;
      var last = events.reduce(function (a, e) { return e.date > a ? e.date : a; }, c.today);
      var minM = c.today.slice(0, 7), maxM = last.slice(0, 7), curM = first.slice(0, 7);
      var r = state.when === 'range' ? state.range : null, i, cells = '';
      var perDay = Object.create(null);
      events.forEach(function (e) { if (matches(e, c, 'when')) perDay[e.date] = (perDay[e.date] || 0) + 1; });
      WDS.forEach(function (w, j) { cells += '<span class="kaf-dow' + (j >= 5 ? ' kaf-we' : '') + '" aria-hidden="true">' + w + '</span>'; });
      for (i = 0; i < lead; i++) cells += '<span></span>';
      for (var d = 1; d <= days; d++) {
        var k = keyOf(Date.UTC(y, m, d)), n = perDay[k] || 0, cls = ['kaf-d'];
        var hol = HOLI[k.slice(5)] || '';
          if (isoDow(k) >= 6 || hol) cls.push('kaf-we');
        if (k === c.today) cls.push('kaf-today');
        var inR = r && k >= r.from && k <= r.to;
        if (inR) cls.push(k === r.from || k === r.to ? 'kaf-edge' : 'kaf-in');
        cells += '<button type="button" class="' + cls.join(' ') + '" data-act="day" data-v="' + k + '"' + (k < c.today || n === 0 ? ' disabled' : '') +
          ' aria-label="' + d + ' ' + MG[m] + (hol ? ', ' + hol : '') + ': ' + n + ' ' + plural(n, CONC) + '"' + (inR ? ' aria-pressed="true"' : '') + '>' + d + '<small>' + (n || '') + '</small></button>';
      }
      var hint = 'Выберите день. Для периода нажмите второй день.';
      if (r && r.from === r.to) hint = 'Выбрано ' + fmtRange(r.from, r.to) + '. Нажмите ещё один день, чтобы выбрать период.';
      else if (r) hint = 'Период: ' + fmtRange(r.from, r.to) + '.';
      return '<div class="kaf-ch"><button type="button" class="kaf-nav" data-act="mon" data-v="-1"' + (curM <= minM ? ' disabled' : '') + ' aria-label="Предыдущий месяц">' + ICO.left + '</button>' +
        '<strong>' + MN[m] + ' ' + y + '</strong>' +
        '<button type="button" class="kaf-nav" data-act="mon" data-v="1"' + (curM >= maxM ? ' disabled' : '') + ' aria-label="Следующий месяц">' + ICO.right + '</button></div>' +
        '<div class="kaf-cal">' + cells + '</div><p class="kaf-hint">' + hint + ' Цифра под датой: сколько концертов подходит под остальные фильтры.</p>';
    }
    function renderPanel(c) {
      var a = lroot.activeElement;
      var key = (a && (pb.contains(a) || pf.contains(a)) && a.getAttribute('data-act')) ? [a.getAttribute('data-act'), a.getAttribute('data-v')] : null;
      var f = open, html = '';
      lroot.getElementById('kaf-pt').textContent = TITLES[f];
      if (f === 'date') html = calHTML(c);
      if (f === 'price') {
        html = '<div class="kaf-lbl">Бюджет на человека</div>' + BUDGETS.map(function (b) {
          var n = events.filter(function (e) { return matches(e, c, 'budget') && budgetOk(e, b); }).length;
          return opt('budget', b === null ? '' : String(b), b === null ? 'Любой' : budgetLabel(b), n, state.budget === b, true);
        }).join('') + '<div class="kaf-lbl">Вход</div>' + FORMATS.map(function (fm) {
          var n = events.filter(function (e) { return matches(e, c, 'format') && e.p.type === fm[0]; }).length;
          return opt('fmt', fm[0], fm[1], n, !!state.formats[fm[0]], false);
        }).join('');
      }
      if (f === 'genre') {
        var on1 = [], off1 = [];
        genreOrder.forEach(function (k) {
          var n = events.filter(function (e) { return matches(e, c, 'genre') && e.g.indexOf(k) >= 0; }).length, isOn = !!state.genres[k];
          var b = '<button type="button" class="kaf-g" data-act="genre" data-v="' + esc(k) + '" aria-pressed="' + isOn + '"' + (n === 0 && !isOn ? ' disabled' : '') + '>' + esc(genreNames[k]) + ' <span class="kaf-n">' + n + '</span></button>';
          (n > 0 || isOn ? on1 : off1).push(b); // недоступные при остальных фильтрах уходят в конец
        });
        html = '<div class="kaf-gch">' + on1.join('') + off1.join('') + '</div>';
      }
      if (f === 'stage') {
        html = STAGE_ORDER.map(function (k) {
          var n = events.filter(function (e) { return matches(e, c, 'stage') && e.st.indexOf(k) >= 0; }).length;
          return opt('stage', k, STAGES[k].name, n, !!state.stages[k], false, STAGES[k].addr, STAGES[k].color);
        }).join('');
      }
      var st = pb.scrollTop;
      pb.innerHTML = html;
      pb.scrollTop = st;
      var n = events.filter(function (e) { return matches(e, c, null); }).length;
      pf.innerHTML = '<button type="button" class="kaf-btn" data-act="clear" data-v="' + f + '">Сбросить</button>' +
        '<button type="button" class="kaf-btn kaf-main" data-act="close"' + (n === 0 ? ' disabled' : '') + '>' + (n === 0 ? 'Нет концертов' : 'Показать ' + n + ' ' + plural(n, CONC)) + '</button>';
      if (key) {
        var sel = '[data-act="' + key[0] + '"]' + (key[1] !== null ? '[data-v="' + String(key[1]).replace(/["\\]/g, '\\$&') + '"]' : '');
        var t = panel.querySelector(sel);
        focusQuiet(t && !t.disabled ? t : lroot.querySelector('.kaf-x'));
      }
      place();
    }
    function place() {
      if (!open || !trigger || !panel) return;
      if (mq.matches) { panel.style.left = ''; panel.style.top = ''; return; }
      var r = trigger.getBoundingClientRect(), w = panel.offsetWidth, vw = win.innerWidth || doc.documentElement.clientWidth;
      panel.style.left = Math.max(8, Math.min(r.left, vw - w - 8)) + 'px';
      panel.style.top = (r.bottom + 6) + 'px';
    }
    function lockBody(lock) {
      try {
        var el = doc.documentElement;
        if (lock && lockedOverflow === null) { lockedOverflow = el.style.overflow; el.style.overflow = 'hidden'; }
        if (!lock && lockedOverflow !== null) { el.style.overflow = lockedOverflow; lockedOverflow = null; }
      } catch (e) { /* не критично */ }
    }
    function openPanel(f, btn) {
      ensureLayer();
      loadFonts();
      open = f; trigger = btn;
      if (f === 'date') {
        var k = state.when === 'range' && state.range ? state.range.from : ctx().today;
        calMonth = [+k.slice(0, 4), +k.slice(5, 7) - 1];
      }
      panel.classList.toggle('kaf-wide', f === 'genre');
      panel.hidden = false;
      scrim.hidden = !mq.matches;
      lockBody(mq.matches);
      btn.setAttribute('aria-expanded', 'true');
      renderPanel(ctx());
      setTimeout(guard(function () {
        if (!open) return;
        var t = pb.querySelector('[aria-checked="true"]:not(:disabled),[aria-pressed="true"]:not(:disabled),.kaf-d.kaf-edge') || pb.querySelector('button:not([disabled])') || lroot.querySelector('.kaf-x');
        focusQuiet(t);
      }), 0);
    }
    function closePanel(returnFocus) {
      if (!open) return;
      var t = trigger;
      open = null;
      panel.hidden = true; scrim.hidden = true;
      lockBody(false);
      if (t) t.setAttribute('aria-expanded', 'false');
      if (returnFocus !== false && t) focusQuiet(t);
    }
    function clear(f) {
      if ((f === 'date' && state.when === 'range') || f === 'all') { state.when = null; state.range = null; }
      if (f === 'price' || f === 'all') { state.budget = null; state.formats = Object.create(null); }
      if (f === 'genre' || f === 'all') state.genres = Object.create(null);
      if (f === 'stage' || f === 'all') state.stages = Object.create(null);
      if (f === 'all') {
        state.q = ''; state.qw = [];
        var qIn = hroot.querySelector('.kaf-q'); if (qIn) qIn.value = '';
        if (open) closePanel(false);
      }
    }

    /* ---------- события ---------- */
    function onClick(ev) {
      if (scrim && ev.target === scrim) { closePanel(); return; }
      var t = ev.target && ev.target.closest ? ev.target.closest('[data-act]') : null;
      if (!t || t.disabled) return;
      var v = t.getAttribute('data-v'), act = t.getAttribute('data-act');
      switch (act) {
        case 'when': state.when = state.when === v ? null : v; state.range = null; if (open) closePanel(false); break;
        case 'reload': try { win.location.reload(); } catch (e) { /* не критично */ } return;
        case 'open':
          if (!ready || degraded) return;
          if (open === v) { closePanel(); return; }
          if (open) closePanel(false);
          openPanel(v, t);
          return;
        case 'close': closePanel(); return;
        case 'day': {
          var r = state.when === 'range' ? state.range : null;
          if (!r || r.from !== r.to) state.range = { from: v, to: v };
          else if (v > r.from) state.range = { from: r.from, to: v };
          else state.range = { from: v, to: v };
          state.when = 'range';
          break;
        }
        case 'mon': {
          var y = calMonth[0], m = calMonth[1] + Number(v);
          if (m < 0) { m = 11; y--; } else if (m > 11) { m = 0; y++; }
          calMonth = [y, m];
          renderPanel(ctx());
          return;
        }
        case 'budget': state.budget = v === '' ? null : Number(v); break;
        case 'fmt': state.formats[v] = !state.formats[v]; break;
        case 'genre': state.genres[v] = !state.genres[v]; break;
        case 'stage': state.stages[v] = !state.stages[v]; break;
        case 'clear': clear(v); break;
        default: return;
      }
      apply(false);
    }
    function onKey(ev) {
      if (ev.key === 'Escape' && open) { ev.preventDefault(); closePanel(); return; }
      if (ev.key !== 'Tab' || !open || !panel) return;
      var list = Array.prototype.filter.call(panel.querySelectorAll('button:not([disabled]),a[href],input:not([disabled])'), function (x) { return x.getClientRects().length; });
      if (!list.length) return;
      var a = lroot.activeElement, first = list[0], last = list[list.length - 1];
      if (ev.shiftKey && (a === first || !panel.contains(a))) { ev.preventDefault(); focusQuiet(last); }
      else if (!ev.shiftKey && (a === last || !panel.contains(a))) { ev.preventDefault(); focusQuiet(first); }
    }

    /* ---------- запуск ---------- */
    try {
      collectCards();
      if (!cards.length) { flag('off'); return; }
      readUrl();
      mount();
      flag('loading');
      apply(false);
      // клик вне окна на компьютере закрывает его; на телефоне это делает затемнение
      on(doc, 'click', function (ev) {
        if (!open || mq.matches) return;
        var path = ev.composedPath ? ev.composedPath() : [];
        if (path.indexOf(panel) >= 0 || path.indexOf(trigger) >= 0) return;
        closePanel(false);
      }, true);
      on(win, 'resize', place);
      on(win, 'scroll', function () { if (open && !mq.matches) place(); }, { passive: true });
      if (mq.addEventListener) on(mq, 'change', function () { if (open) closePanel(false); });
      load(false, 0);
    } catch (e) {
      teardown(e);
    }
  }

  if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', boot); else boot();
})();
