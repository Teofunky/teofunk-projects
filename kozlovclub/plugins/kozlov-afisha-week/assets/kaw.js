/* Kozlov Afisha Week: афиша главной по неделям.
   Сервер отдаёт видимыми ближайшие дни, остальные карточки лежат в <template class="kaw-later" data-kaw-d="ГГГГ-ММ-ДД">.
   Этот скрипт:
   - раскрывает карточки, чей день уже наступил по Москве (страница могла прийти из кэша, сохранённого вчера);
   - ставит под афишей кнопку «Показать концерты 12–18 ноября», каждое нажатие раскрывает следующие дни;
   - по событию kaw:expand на document раскрывает всё (так делает панель фильтров перед фильтром или поиском),
     по kaw:collapse (фильтры сброшены) снова прячет дни дальше открытых и возвращает кнопку;
   - при возврате кнопкой «Назад» или обновлении страницы открывает столько же дней, сколько было открыто;
   - для раскрытых карточек повторяет то, что встроенный скрипт темы сделал для остальных (высота названий);
   - кнопка «Купить билет» раскрытой карточки, которую не подхватил виджет Ticketscloud, ведёт на страницу концерта.
   Глобальных переменных нет, jQuery не нужен. Любая ошибка раскрывает все карточки: афиша остаётся полной. */
(function () {
  'use strict';

  var doc = document, win = window;
  var outer = doc.querySelector('.events_wrap.kaw-on');
  if (!outer) return;
  var box = outer.querySelector(':scope > .wrap') || outer;

  var DAY = 864e5;
  var MON = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
  function num(v, d) { v = parseInt(v, 10); return v >= 1 && v <= 62 ? v : d; }
  var FIRST = num(outer.getAttribute('data-kaw-first'), 7);
  var STEP = num(outer.getAttribute('data-kaw-step'), 7);
  var KEY = 'kaw:' + win.location.pathname;

  function todayMsk() {
    var d = new Date(Date.now() + 3 * 3600 * 1000);
    return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
  }
  function dayOf(t) {
    var s = t.getAttribute('data-kaw-d') || '';
    return /^\d{4}-\d\d-\d\d$/.test(s) ? Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)) : 0;
  }
  function label(ms) { var d = new Date(ms); return d.getUTCDate() + ' ' + MON[d.getUTCMonth()]; }
  function range(a, b) {
    var da = new Date(a), db = new Date(b);
    if (a === b) return label(a);
    if (da.getUTCMonth() === db.getUTCMonth()) return da.getUTCDate() + '–' + label(b);
    return 'с ' + label(a) + ' по ' + label(b);
  }
  function plural(n, one, few, many) {
    var a = n % 10, b = n % 100;
    return a === 1 && b !== 11 ? one : (a >= 2 && a <= 4 && (b < 12 || b > 14) ? few : many);
  }
  // ещё не показанные концерты: карточки в <template> и раскрытые, но снова спрятанные после сброса фильтров
  function later() { return Array.prototype.slice.call(box.querySelectorAll('template.kaw-later, .events > .event.kaw-off')); }
  function hasCard(cont) {
    for (var i = 0; i < cont.children.length; i++) {
      var c = cont.children[i];
      if (c.classList.contains('event') && !c.classList.contains('kaw-off')) return true;
    }
    return false;
  }
  // заголовок месяца и его блок видны, если в блоке есть показанная карточка
  function refreshMonths() {
    var head = null;
    for (var i = 0; i < box.children.length; i++) {
      var el = box.children[i];
      if (el.classList.contains('event_month')) { head = el; continue; }
      if (!el.classList.contains('events')) continue;
      var show = hasCard(el), pend = !!el.querySelector('template.kaw-later, .event.kaw-off');
      el.classList.toggle('kaw-hide', !show && pend);
      if (head) head.classList.toggle('kaw-hide', !show && pend);
      head = null;
    }
  }

  var until = todayMsk() + (FIRST - 1) * DAY; // последний открытый день, включительно
  var more = null, btn = null, sub = null, status = null;

  /* ---------- то, что встроенный скрипт темы сделал с карточками, которые были на странице ---------- */
  // тема: у названий выше 40 px класс shortened, всем названиям в плитке высота 50 px
  function fixCards(cards) {
    var cell = cards.filter(function (c) { return c.parentNode && c.parentNode.classList.contains('view_cell'); });
    var titles = cell.map(function (c) { return c.querySelector('.title'); });
    var heights = titles.map(function (t) { // высота содержимого, как $(el).height() в теме
      if (!t) return 0;
      var cs = win.getComputedStyle(t);
      return t.clientHeight - (parseFloat(cs.paddingTop) || 0) - (parseFloat(cs.paddingBottom) || 0);
    });
    titles.forEach(function (t, i) { if (t && heights[i] >= 40) t.classList.add('shortened'); });
    cell.forEach(function (c) { var s = c.querySelector('.set_height'); if (s) s.style.height = '50px'; });
  }

  /* ---------- билеты в раскрытых карточках ---------- */
  // Виджет Ticketscloud мог привязаться к кнопкам до раскрытия. Если после нажатия его окно не появилось,
  // посетитель попадает на страницу концерта, где кнопка покупки работает как обычно.
  function tcScript() { return !!doc.querySelector('script[src*="ticketscloud"], #tcwidget-js'); }
  function looksLikeTicketWindow(el) {
    if (!el || el.nodeType !== 1 || !doc.documentElement.contains(el)) return false;
    if (el.tagName === 'IFRAME') return true;
    var name = (el.id || '') + ' ' + (typeof el.className === 'string' ? el.className : '') + ' ' + (el.getAttribute('src') || '');
    if (/ticketscloud|(^|[\s_-])tc[\s_-]/i.test(' ' + name + ' ')) return true;
    var r = el.getBoundingClientRect(), cs = win.getComputedStyle(el);
    return (cs.position === 'fixed' || cs.position === 'absolute') && r.width * r.height > win.innerWidth * win.innerHeight * 0.5;
  }
  function isWindow(n) {
    if (looksLikeTicketWindow(n)) return true;
    return n && n.querySelectorAll ? Array.prototype.some.call(n.querySelectorAll('iframe'), looksLikeTicketWindow) : false;
  }
  function watchTickets(cards) {
    cards.forEach(function (card) {
      var a = card.querySelector('a.title');
      var href = a ? a.getAttribute('href') : '';
      if (!href) return;
      Array.prototype.forEach.call(card.querySelectorAll('[tc-event]'), function (b) {
        b.addEventListener('click', function () {
          if (!tcScript()) { win.location.href = href; return; }
          var added = [], opened = false;
          var mo = win.MutationObserver ? new MutationObserver(function (list) {
            list.forEach(function (m) {
              Array.prototype.forEach.call(m.addedNodes, function (n) { added.push(n); if (!opened && isWindow(n)) opened = true; });
            });
          }) : null;
          if (mo) mo.observe(doc.body, { childList: true, subtree: true });
          setTimeout(function () {
            if (mo) mo.disconnect();
            if (!opened) opened = added.some(isWindow);
            if (!opened) win.location.href = href;
          }, 2500);
        });
      });
    });
  }

  /* ---------- раскрытие ---------- */
  function unpack(list) {
    if (!list.length) return 0;
    var cards = [], shown = 0;
    list.forEach(function (t) {
      if (!t.parentNode) return;
      if (t.tagName !== 'TEMPLATE') { t.classList.remove('kaw-off'); shown++; return; } // уже раскрыта, была спрятана
      var d = t.getAttribute('data-kaw-d') || '';
      var frag = t.content;
      Array.prototype.forEach.call(frag.children, function (n) {
        if (n.classList.contains('event')) { n.setAttribute('data-kaw-d', d); cards.push(n); }
      });
      t.parentNode.replaceChild(frag, t);
    });
    refreshMonths();
    try { fixCards(cards); } catch (e) { /* названия остаются без подгонки высоты */ }
    try { watchTickets(cards); } catch (e) { /* кнопки работают как есть */ }
    return cards.length + shown;
  }
  // фильтры сброшены: дни дальше открытых снова прячутся (карточки остаются на странице)
  function collapse() {
    Array.prototype.forEach.call(box.querySelectorAll('.events > .event[data-kaw-d]'), function (c) {
      if (dayOf(c) > until) c.classList.add('kaw-off');
    });
    refreshMonths();
  }
  function upTo(ms) { return unpack(later().filter(function (t) { return dayOf(t) <= ms; })); }

  function save() {
    try { win.sessionStorage.setItem(KEY, JSON.stringify({ u: until, t: Date.now() })); } catch (e) { /* без памяти */ }
  }
  function restore() {
    var type = '';
    try {
      var nav = win.performance && win.performance.getEntriesByType ? win.performance.getEntriesByType('navigation')[0] : null;
      type = nav ? nav.type : (win.performance && win.performance.navigation && win.performance.navigation.type === 2 ? 'back_forward' : '');
    } catch (e) { type = ''; }
    if (type !== 'back_forward' && type !== 'reload') {
      try { win.sessionStorage.removeItem(KEY); } catch (e) { /* без памяти */ }
      return;
    }
    try {
      var s = JSON.parse(win.sessionStorage.getItem(KEY) || 'null');
      if (s && typeof s.u === 'number' && s.u > until && Date.now() - s.t < 3 * 3600 * 1000) { until = s.u; upTo(until); }
    } catch (e) { /* без памяти */ }
  }

  /* ---------- кнопка ---------- */
  function lastShown() {
    var conts = box.querySelectorAll('.events'), last = null;
    for (var i = 0; i < conts.length; i++) if (!conts[i].classList.contains('kaw-hide') && hasCard(conts[i])) last = conts[i];
    return last;
  }
  function nextRange(rest) {
    var from = Math.max(dayOf(rest[0]), until + DAY);
    var to = from + (STEP - 1) * DAY;
    var lastDay = dayOf(rest[rest.length - 1]);
    return { from: from, to: Math.min(to, lastDay), end: to, lastDay: lastDay };
  }
  function render() {
    var rest = later();
    if (!rest.length) {
      if (more && more.parentNode) more.parentNode.removeChild(more);
      more = null;
      return;
    }
    if (!more) {
      more = doc.createElement('div');
      more.className = 'kaw-more';
      more.innerHTML = '<button type="button" class="kaw-btn"></button>' +
        '<p class="kaw-sub"></p>' +
        '<button type="button" class="kaw-cal" data-kozlov-calendar>Выбрать дату в календаре</button>' +
        '<span class="kaw-sr" role="status" aria-live="polite"></span>';
      btn = more.querySelector('.kaw-btn');
      sub = more.querySelector('.kaw-sub');
      status = more.querySelector('.kaw-sr');
      btn.addEventListener('click', showNext);
    }
    var r = nextRange(rest);
    btn.textContent = 'Показать концерты ' + range(r.from, r.to);
    sub.textContent = 'Ещё ' + rest.length + ' ' + plural(rest.length, 'концерт', 'концерта', 'концертов') + ' до ' + label(r.lastDay);
    var anchor = lastShown();
    var parent = anchor ? anchor.parentNode : box;
    var next = anchor ? anchor.nextSibling : null;
    if (more.parentNode !== parent || more.previousSibling !== anchor) {
      var focused = doc.activeElement === btn;
      parent.insertBefore(more, next);
      if (focused) { try { btn.focus({ preventScroll: true }); } catch (e) { btn.focus(); } }
    }
  }
  function showNext() {
    var rest = later();
    if (!rest.length) return;
    var r = nextRange(rest);
    until = r.end;
    var n = upTo(until);
    save();
    render();
    if (status) status.textContent = n ? 'Добавлено ' + n + ' ' + plural(n, 'концерт', 'концерта', 'концертов') : '';
    try { doc.dispatchEvent(new CustomEvent('kaw:more', { detail: { added: n } })); } catch (e) { /* не важно */ }
  }
  function expandAll() {
    unpack(later());
    render();
  }

  try {
    doc.addEventListener('kaw:expand', function () { try { expandAll(); } catch (e) { giveUp(e); } });
    doc.addEventListener('kaw:collapse', function () { try { collapse(); render(); } catch (e) { giveUp(e); } });
    upTo(until); // наступившие дни из страницы, сохранённой в кэше раньше
    restore();
    render();
  } catch (e) {
    giveUp(e);
  }

  function giveUp(e) {
    try {
      Array.prototype.forEach.call(box.querySelectorAll('template.kaw-later'), function (t) { if (t.parentNode) t.parentNode.replaceChild(t.content, t); });
      Array.prototype.forEach.call(box.querySelectorAll('.kaw-hide, .kaw-off'), function (el) { el.classList.remove('kaw-hide', 'kaw-off'); });
      if (more && more.parentNode) more.parentNode.removeChild(more);
      outer.classList.remove('kaw-on');
    } catch (x) { /* больше нечего вернуть */ }
    if (e && win.console) win.console.warn('[kaw]', e);
  }
})();
