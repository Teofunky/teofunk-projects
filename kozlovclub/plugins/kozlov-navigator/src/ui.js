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
