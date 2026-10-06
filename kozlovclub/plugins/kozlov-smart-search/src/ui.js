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

  // Строка поиска темы. На сайте она одна на всех страницах; если шаблон выведет несколько,
  // поиск работает с той, что сейчас раскрыта.
  var bars = [];
  Array.prototype.forEach.call(doc.querySelectorAll('.search_block'), function (b) {
    var f = b.querySelector('form'), i = b.querySelector('input.searchinput, input[name="s"]');
    if (f && i) bars.push({ bar: b, form: f, input: i });
  });
  if (!bars.length || !Element.prototype.attachShadow || !win.fetch || !win.MutationObserver) return;
  var cur = bars[0], bar = cur.bar, form = cur.form, input = cur.input;

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
  var observer = null, layoutQueued = false, listeners = [], buying = false;

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
      bars.forEach(function (c) { c.input.removeAttribute('aria-expanded'); });
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
  function activeOne() {
    if (barActive()) return cur;
    for (var i = 0; i < bars.length; i++) if (bars[i].bar.classList.contains('active')) return bars[i];
    return null;
  }
  /* Переключение на другую строку поиска: панель закрывается и откроется уже под ней. */
  function use(c) {
    if (c === cur) return;
    hide();
    cur = c; bar = c.bar; form = c.form; input = c.input;
  }

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
    var a = activeOne();
    if (a) { use(a); if (!isOpen) { open(); focusQuiet(input); } }
    else hide();
  }

  /* Элемент действительно виден на экране: не скрыт стилями и не пустой. */
  function shown(el) {
    if (!el || !el.getBoundingClientRect) return false;
    var r = el.getBoundingClientRect(), s = win.getComputedStyle(el);
    if (!(r.width > 0 && r.height > 0) || s.display === 'none' || s.visibility === 'hidden') return false;
    return r.bottom > 0 && r.right > 0 && r.top < (win.innerHeight || 1e6) && r.left < (win.innerWidth || 1e6);
  }
  /* Скрипт Ticketscloud начинает слушать нажатия не сразу: он сам выставляет этот флаг, когда готов (на сайте: после загрузки страницы).
     Пока флага нет, «Купить билет» работает как обычная ссылка на страницу концерта. */
  function ticketsReady() {
    return win.tcBuyTicketClickCallbackBinded === true || (!!win.ticketsCloudWidget && doc.readyState === 'complete');
  }
  /* Виден ли виджет Ticketscloud. С token: виден ли виджет именно этого концерта.
     Элементы виджета, оставшиеся на странице скрытыми после закрытия, не считаются. */
  function ticketsShown(token) {
    var frames = doc.querySelectorAll('iframe[name^="tc-frame"], iframe[data-tc-event-id], iframe[class*="tc-widget"]'), i, f;
    for (i = 0; i < frames.length; i++) {
      f = frames[i];
      if (token && f.getAttribute('data-tc-event-id') !== token && f.getAttribute('name') !== 'tc-frame-' + token) continue;
      if (shown(f)) return true;
    }
    return token ? false : shown(doc.getElementById('tc-widget-overlay'));
  }

  /* «Купить билет»: открываем тот же виджет Ticketscloud, что и кнопки афиши, тем же способом: нажатием на элемент с атрибутом tc-event.
     Затем проверяем, что на экране появился виджет именно этого концерта. Не появился за полторы секунды: ведём на страницу концерта. */
  function buy(token, url) {
    if (buying) return;
    buying = true;
    var b = doc.createElement('button'), tries = 0;
    b.type = 'button'; b.className = 'kss-tc-proxy'; b.tabIndex = -1;
    b.setAttribute('tc-event', token); b.setAttribute('aria-hidden', 'true');
    b.style.display = 'none';
    doc.body.appendChild(b);
    var done = function (opened) {
      buying = false;
      try { if (b.parentNode) b.parentNode.removeChild(b); } catch (e) { /* не критично */ }
      if (!opened && url && url !== '#') win.location.href = url;
    };
    var check = function () {
      var opened = false;
      try { opened = ticketsShown(token); } catch (e) { opened = false; }
      if (opened) { done(true); return; }
      if (++tries > 15) { done(false); return; }
      setTimeout(check, 100);
    };
    try { b.click(); } catch (e) { done(false); return; }
    check();
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
  bars.forEach(function (c) {
    observer.observe(c.bar, { attributes: true, attributeFilter: ['class'] });
    on(c.input, 'input', function () {
      if (!c.bar.classList.contains('active')) return;
      use(c);
      if (!isOpen) open();
      limit = LIMIT; render();
    });
    on(c.input, 'focus', function () {
      if (!c.bar.classList.contains('active')) return;
      use(c);
      if (!isOpen) open();
    });
    on(c.form, 'submit', function (e) {
      // пока наш поиск работает, Enter не уводит на страницу штатного поиска, а переводит к первому результату
      if (c !== cur || !isOpen || (state !== 'ready' && state !== 'loading')) return;
      e.preventDefault();
      var first = panel ? panel.querySelector('[data-nav]') : null;
      if (first) focusQuiet(first);
    });
  });
  on(doc, 'keydown', function (e) {
    if (!isOpen || ticketsShown()) return;
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
  if (activeOne() && !/[?&]s=/.test(win.location.search)) guard(sync)();
}
