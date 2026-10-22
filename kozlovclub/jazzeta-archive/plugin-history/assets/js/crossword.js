/**
 * «Ежедневный стандарт» — кроссворд недели (0.5.2).
 * Enhancement over plain one-letter fields: moving through words, checking, hints, timer and
 * progress kept in this browser only (localStorage). Nothing is sent to the server.
 * Keyboard: letters fill the word, arrows move, Space switches direction, Enter / Shift+Enter
 * go to the next / previous word, Backspace erases. Tab leaves the grid (no keyboard trap).
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-es-cw]');
  if (!root) { return; }
  var data;
  try { data = JSON.parse(root.getAttribute('data-es-cw-data') || ''); } catch (e) { return; }
  if (!data || !data.rows || !data.cols) { return; }

  var ALPHABET = 'АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯABCDEFGHIJKLMNOPQRSTUVWXYZ';
  var SIZE = ALPHABET.length;
  var LATIN_TWINS = { A: 'А', B: 'В', C: 'С', E: 'Е', H: 'Н', K: 'К', M: 'М', O: 'О', P: 'Р', T: 'Т', X: 'Х', Y: 'У' };
  var NAMES = { a: 'по горизонтали', d: 'по вертикали' };
  var ZOOM = [24, 28, 32, 36, 42, 48];
  var rows = data.rows;
  var cols = data.cols;
  var total = rows * cols;
  var $ = function (sel, ctx) { return (ctx || root).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); };
  var store = {
    get: function (key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } },
    set: function (key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ } },
    drop: function (key) { try { window.localStorage.removeItem(key); } catch (e) { /* private mode */ } }
  };

  // ------------------------------------------------------------ solution and cells
  var solution = new Array(total);
  for (var k = 0; k < total; k++) {
    var ch = data.cells.charAt(k);
    if (ch === '.' || ch === '') { solution[k] = null; continue; }
    var idx = ALPHABET.indexOf(ch);
    solution[k] = ALPHABET.charAt((((idx - data.key - (k * 7) % SIZE) % SIZE) + SIZE * 2) % SIZE);
  }
  // The grid's children are the cells in reading order: <i> empty, <span> with an input.
  var inputs = new Array(total);
  Array.prototype.forEach.call($('.es-cw__grid').children, function (cell, index) {
    var input = cell.tagName === 'SPAN' ? cell.querySelector('input') : null;
    if (!input || index >= total) { return; }
    inputs[index] = input;
    input.setAttribute('data-k', String(index));
    input.removeAttribute('maxlength');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('autocapitalize', 'characters');
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('enterkeyhint', 'next');
    input.tabIndex = -1;
  });

  var entries = data.entries.map(function (e) {
    var cells = [];
    for (var i = 0; i < e.len; i++) {
      cells.push((e.r + (e.d === 'd' ? i : 0)) * cols + e.c + (e.d === 'a' ? i : 0));
    }
    return { n: e.n, d: e.d, len: e.len, clue: e.clue, enumeration: e.enum, cells: cells, id: e.d + e.n };
  });
  var cellEntry = {};
  entries.forEach(function (entry, i) {
    entry.cells.forEach(function (index) { (cellEntry[index] = cellEntry[index] || {})[entry.d] = i; });
  });
  var whites = [];
  for (k = 0; k < total; k++) { if (solution[k] !== null && inputs[k]) { whites.push(k); } }

  // ------------------------------------------------------------ state
  var KEY = 'es-cw-' + data.id;
  var letters = {};
  var revealed = {};
  var state = { time: 0, hints: 0, checks: 0, done: false, started: false };
  var active = { entry: -1, k: whites[0] };
  var saved = null;
  try { saved = JSON.parse(store.get(KEY) || 'null'); } catch (e) { saved = null; }
  if (saved && saved.layout === data.layout) {
    whites.forEach(function (index, i) {
      var l = (saved.l || '').charAt(i);
      if (l && l !== ' ') { letters[index] = l; }
      if ((saved.r || '').charAt(i) === '1') { revealed[index] = true; }
    });
    state.time = saved.t || 0;
    state.hints = saved.h || 0;
    state.checks = saved.c || 0;
    state.done = !!saved.done;
    state.started = state.time > 0 || Object.keys(letters).length > 0;
  }
  var save = function () {
    var l = '';
    var rv = '';
    whites.forEach(function (index) { l += letters[index] || ' '; rv += revealed[index] ? '1' : '0'; });
    store.set(KEY, JSON.stringify({ layout: data.layout, l: l, r: rv, t: state.time, h: state.hints, c: state.checks, done: state.done }));
  };

  // ------------------------------------------------------------ elements
  var tools = $('[data-es-cw-tools]');
  var bar = $('[data-es-cw-current]');
  var barId = $('[data-es-cw-current-id]');
  var barText = $('[data-es-cw-current-text]');
  var status = $('[data-es-cw-status]');
  var timeEl = $('[data-es-cw-time]');
  var grid = $('.es-cw__grid');
  var scroller = $('[data-es-cw-scroll]');
  tools.hidden = false;
  bar.hidden = false;
  root.classList.add('is-ready');

  var clueItems = {};
  $$('[data-es-cw-clue]').forEach(function (li) {
    var id = li.getAttribute('data-es-cw-clue');
    var holder = li.firstElementChild;
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'es-cw__cbtn';
    while (holder && holder.firstChild) { button.appendChild(holder.firstChild); }
    if (holder) { li.replaceChild(button, holder); } else { li.appendChild(button); }
    clueItems[id] = li;
    button.addEventListener('click', function () {
      var i = indexOfEntry(id);
      if (i >= 0) { activate(i, firstEmpty(i), true); }
    });
  });
  if (data.open) {
    var group = $('.es-cw__group--end', tools);
    var all = document.createElement('button');
    all.type = 'button';
    all.className = 'es-cw__btn';
    all.setAttribute('data-es-cw-action', 'reveal-all');
    all.textContent = 'Открыть всё';
    group.insertBefore(all, group.firstChild);
  }

  function indexOfEntry(id) {
    for (var i = 0; i < entries.length; i++) { if (entries[i].id === id) { return i; } }
    return -1;
  }
  function firstEmpty(i) {
    var cells = entries[i].cells;
    for (var j = 0; j < cells.length; j++) { if (!letters[cells[j]]) { return cells[j]; } }
    return cells[0];
  }
  function entryFor(index, dir) {
    var map = cellEntry[index] || {};
    if (map[dir] !== undefined) { return map[dir]; }
    return map.a !== undefined ? map.a : map.d;
  }

  // ------------------------------------------------------------ drawing
  function paint(index) {
    var input = inputs[index];
    input.value = letters[index] || '';
    input.classList.toggle('is-revealed', !!revealed[index]);
    input.readOnly = !!revealed[index];
    if (!letters[index]) { setWrong(index, false); }
  }
  function setWrong(index, wrong) {
    var input = inputs[index];
    input.classList.toggle('is-wrong', wrong);
    if (wrong) { input.setAttribute('aria-invalid', 'true'); } else { input.removeAttribute('aria-invalid'); }
  }
  function activate(entryIndex, index, focus) {
    var previous = active.entry;
    if (previous >= 0) {
      entries[previous].cells.forEach(function (c) { inputs[c].parentNode.classList.remove('is-word'); });
      var oldLi = clueItems[entries[previous].id];
      if (oldLi) { oldLi.classList.remove('is-active'); }
    }
    if (inputs[active.k]) { inputs[active.k].parentNode.classList.remove('is-active'); inputs[active.k].tabIndex = -1; }
    active.entry = entryIndex;
    active.k = index;
    var entry = entries[entryIndex];
    entry.cells.forEach(function (c) { inputs[c].parentNode.classList.add('is-word'); });
    inputs[index].parentNode.classList.add('is-active');
    inputs[index].tabIndex = 0;
    var li = clueItems[entry.id];
    if (li) {
      li.classList.add('is-active');
      if (previous !== entryIndex && window.matchMedia('(min-width: 1024px)').matches) { li.scrollIntoView({ block: 'nearest' }); }
    }
    if (previous !== entryIndex) {
      barId.textContent = entry.n + ' ' + NAMES[entry.d] + ' (' + entry.enumeration + ')';
      barText.textContent = entry.clue;
    }
    if (focus) {
      inputs[index].focus({ preventScroll: true });
      try { inputs[index].select(); } catch (e) { /* old browsers */ }
      inputs[index].scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
  }
  function move(step) {
    var cells = entries[active.entry].cells;
    var pos = cells.indexOf(active.k) + step;
    if (pos >= 0 && pos < cells.length) { activate(active.entry, cells[pos], true); }
  }
  function nextEntry(step) {
    var i = (active.entry + step + entries.length) % entries.length;
    activate(i, firstEmpty(i), true);
  }
  function arrow(dr, dc) {
    var r = Math.floor(active.k / cols);
    var c = active.k % cols;
    var dir = dr !== 0 ? 'd' : 'a';
    if (entries[active.entry].d !== dir && (cellEntry[active.k] || {})[dir] !== undefined) {
      activate(entryFor(active.k, dir), active.k, true);
      return;
    }
    for (r += dr, c += dc; r >= 0 && c >= 0 && r < rows && c < cols; r += dr, c += dc) {
      var index = r * cols + c;
      if (inputs[index]) { activate(entryFor(index, dir), index, true); return; }
    }
  }

  // ------------------------------------------------------------ typing
  function letterOf(raw, index, previous) {
    var text = String(raw || '').toUpperCase().replace(/Ё/g, 'Е');
    // A letter typed next to the old one (the caret may stand on either side): keep the new one.
    if (previous && text.length > 1) {
      var at = text.indexOf(previous);
      if (at >= 0) { text = text.slice(0, at) + text.slice(at + 1); }
    }
    for (var i = text.length - 1; i >= 0; i--) {
      var ch = text.charAt(i);
      if (/[А-ЯA-Z]/.test(ch)) {
        if (LATIN_TWINS[ch] && /[А-Я]/.test(solution[index] || '')) { return LATIN_TWINS[ch]; }
        return ch;
      }
    }
    return '';
  }
  function put(index, letter) {
    if (revealed[index]) { return; }
    letters[index] = letter;
    if (!letter) { delete letters[index]; }
    setWrong(index, false);
    paint(index);
    start();
  }
  function afterChange() {
    save();
    var filled = whites.every(function (i) { return !!letters[i]; });
    if (!filled) { return; }
    var right = whites.every(function (i) { return letters[i] === solution[i]; });
    if (right && !state.done) {
      state.done = true;
      save();
      finish();
    } else if (!right) {
      say('Все клетки заполнены, но где-то ошибка. «Проверить всё» покажет, где.');
    }
  }

  grid.addEventListener('focusin', function (e) {
    var index = parseInt(e.target.getAttribute('data-k'), 10);
    if (isNaN(index)) { return; }
    if (index !== active.k || active.entry < 0) {
      var keep = active.entry >= 0 ? entries[active.entry].d : 'a';
      activate(entryFor(index, keep), index, false);
    }
    try { e.target.select(); } catch (err) { /* old browsers */ }
  });
  var wasActive = false;
  grid.addEventListener('pointerdown', function (e) {
    var index = parseInt(e.target.getAttribute('data-k'), 10);
    wasActive = !isNaN(index) && index === active.k && document.activeElement === e.target;
  });
  grid.addEventListener('click', function (e) {
    var index = parseInt(e.target.getAttribute('data-k'), 10);
    if (isNaN(index) || !wasActive) { return; }
    var other = entries[active.entry].d === 'a' ? 'd' : 'a';
    if ((cellEntry[index] || {})[other] !== undefined) { activate(cellEntry[index][other], index, true); }
  });
  grid.addEventListener('input', function (e) {
    var index = parseInt(e.target.getAttribute('data-k'), 10);
    if (isNaN(index)) { return; }
    var letter = letterOf(e.target.value, index, letters[index] || '');
    if (revealed[index]) { paint(index); return; }
    put(index, letter);
    if (letter) { move(1); }
    afterChange();
  });
  grid.addEventListener('paste', function (e) {
    var index = parseInt(e.target.getAttribute('data-k'), 10);
    if (isNaN(index)) { return; }
    e.preventDefault();
    var text = ((e.clipboardData || window.clipboardData).getData('text') || '').toUpperCase().replace(/Ё/g, 'Е').replace(/[^А-ЯA-Z]/g, '');
    var cells = entries[active.entry].cells;
    var pos = cells.indexOf(index);
    for (var i = 0; i < text.length && pos + i < cells.length; i++) {
      put(cells[pos + i], letterOf(text.charAt(i), cells[pos + i]));
    }
    activate(active.entry, cells[Math.min(cells.length - 1, pos + text.length)], true);
    afterChange();
  });
  grid.addEventListener('keydown', function (e) {
    var index = parseInt(e.target.getAttribute('data-k'), 10);
    if (isNaN(index) || e.altKey || e.ctrlKey || e.metaKey) { return; }
    switch (e.key) {
      case 'ArrowLeft': arrow(0, -1); break;
      case 'ArrowRight': arrow(0, 1); break;
      case 'ArrowUp': arrow(-1, 0); break;
      case 'ArrowDown': arrow(1, 0); break;
      case 'Enter': nextEntry(e.shiftKey ? -1 : 1); break;
      case ' ':
        var other = entries[active.entry].d === 'a' ? 'd' : 'a';
        if ((cellEntry[index] || {})[other] !== undefined) { activate(cellEntry[index][other], index, true); }
        break;
      case 'Backspace':
        if (letters[index] && !revealed[index]) { put(index, ''); } else { move(-1); if (!revealed[active.k]) { put(active.k, ''); } }
        afterChange();
        break;
      case 'Delete':
        put(index, '');
        afterChange();
        break;
      default:
        return;
    }
    e.preventDefault();
  });

  // ------------------------------------------------------------ actions
  function say(text) { status.textContent = text; }
  function check(cells) {
    var wrong = 0;
    var empty = 0;
    cells.forEach(function (i) {
      if (!letters[i]) { empty++; return; }
      var bad = letters[i] !== solution[i];
      setWrong(i, bad);
      if (bad) { wrong++; }
    });
    state.checks++;
    save();
    return { wrong: wrong, empty: empty };
  }
  function plural(n, one, few, many) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) { return one; }
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) { return few; }
    return many;
  }
  function reveal(cells) {
    var opened = 0;
    cells.forEach(function (i) {
      if (letters[i] !== solution[i] || !revealed[i]) {
        if (letters[i] !== solution[i]) { opened++; }
        letters[i] = solution[i];
        revealed[i] = true;
        setWrong(i, false);
        paint(i);
      }
    });
    if (opened) { state.hints += opened; }
    start();
    afterChange();
  }
  var resetArmed = null;
  root.addEventListener('click', function (e) {
    var button = e.target.closest ? e.target.closest('[data-es-cw-action]') : null;
    if (!button || !root.contains(button)) { return; }
    var action = button.getAttribute('data-es-cw-action');
    var entry = entries[active.entry] || entries[0];
    if (action === 'check-word') {
      var res = check(entry.cells);
      say(res.wrong ? 'В слове ' + res.wrong + ' ' + plural(res.wrong, 'ошибка', 'ошибки', 'ошибок') + '.' : (res.empty ? 'Ошибок нет, осталось ' + res.empty + ' ' + plural(res.empty, 'клетка', 'клетки', 'клеток') + '.' : 'Слово верно.'));
    } else if (action === 'check-all') {
      var all = check(whites);
      say(all.wrong ? 'Ошибок: ' + all.wrong + '. Они отмечены в сетке.' : 'Ошибок нет' + (all.empty ? ', осталось ' + all.empty + ' ' + plural(all.empty, 'клетка', 'клетки', 'клеток') + '.' : '.'));
    } else if (action === 'reveal-letter') {
      reveal([active.k]);
      say('Буква открыта.');
    } else if (action === 'reveal-word') {
      reveal(entry.cells);
      say('Слово открыто.');
    } else if (action === 'reveal-all') {
      reveal(whites);
      say('Ответы вписаны в сетку.');
    } else if (action === 'zoom-in' || action === 'zoom-out') {
      zoom(action === 'zoom-in' ? 1 : -1);
    } else if (action === 'print') {
      window.print();
    } else if (action === 'next' || action === 'prev') {
      nextEntry(action === 'next' ? 1 : -1);
    } else if (action === 'reset') {
      if (resetArmed) {
        window.clearTimeout(resetArmed);
        resetArmed = null;
        button.textContent = 'Сначала';
        letters = {};
        revealed = {};
        state = { time: 0, hints: 0, checks: 0, done: false, started: false };
        whites.forEach(function (i) { setWrong(i, false); paint(i); });
        root.classList.remove('is-done');
        store.drop(KEY);
        tick();
        say('Кроссворд очищен.');
        activate(0, entries[0].cells[0], true);
      } else {
        button.textContent = 'Точно стереть всё?';
        resetArmed = window.setTimeout(function () { button.textContent = 'Сначала'; resetArmed = null; }, 4000);
      }
    }
  });

  // ------------------------------------------------------------ zoom
  var zoomStep = parseInt(store.get('es-cw-zoom') || '', 10);
  function zoom(delta) {
    if (isNaN(zoomStep)) { zoomStep = 2; }
    zoomStep = Math.max(0, Math.min(ZOOM.length - 1, zoomStep + delta));
    root.style.setProperty('--cw-cell', ZOOM[zoomStep] + 'px');
    store.set('es-cw-zoom', String(zoomStep));
    $('[data-es-cw-action="zoom-out"]').disabled = zoomStep === 0;
    $('[data-es-cw-action="zoom-in"]').disabled = zoomStep === ZOOM.length - 1;
  }
  if (!isNaN(zoomStep)) { zoom(0); }

  // ------------------------------------------------------------ timer and finish
  var timer = null;
  function format(seconds) {
    var h = Math.floor(seconds / 3600);
    var m = Math.floor(seconds / 60) % 60;
    var s = seconds % 60;
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }
  function tick() { timeEl.textContent = format(state.time); }
  function start() {
    if (state.done) { return; }
    state.started = true;
    if (!timer) {
      timer = window.setInterval(function () {
        if (document.hidden || state.done) { return; }
        state.time++;
        tick();
        if (state.time % 5 === 0) { save(); }
      }, 1000);
    }
  }
  function finish() {
    if (timer) { window.clearInterval(timer); timer = null; }
    root.classList.add('is-done');
    say('Кроссворд решён за ' + format(state.time) + (state.hints ? ', подсказок: ' + state.hints + '.' : ' — без подсказок.'));
  }

  // ------------------------------------------------------------ start
  whites.forEach(paint);
  tick();
  activate(0, entries[0].cells[0], false);
  if (state.done) { finish(); } else if (state.started) {
    say('Продолжаем с того места, где вы остановились.');
  }
})();
