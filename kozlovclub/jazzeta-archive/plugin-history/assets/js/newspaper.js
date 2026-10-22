/**
 * «Ежедневный стандарт» — reader script (0.5.0).
 * Everything here is an enhancement: without JavaScript the issue, the articles, the archive
 * and the sources are fully readable in the server's default mode.
 * Reader settings (mode, palette, text size, density) live in this browser only
 * (localStorage) and never reach the server, so a cached page is the same for everyone.
 */
(function () {
  'use strict';
  var root = document.documentElement;
  var app = document.getElementById('es-newspaper');
  if (!app) { return; }
  var $ = function (sel, ctx) { return (ctx || app).querySelector(sel); };
  var $$ = function (sel, ctx) { return Array.prototype.slice.call((ctx || app).querySelectorAll(sel)); };
  var store = {
    get: function (key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } },
    set: function (key, value) { try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ } },
    drop: function (key) { try { window.localStorage.removeItem(key); } catch (e) { /* private mode */ } }
  };

  var DESIGNS = ['gazeta', 'modern', 'brief'];
  var SIZES = [90, 100, 112, 125, 140];
  var locked = root.getAttribute('data-es-lock') === '1';
  var serverDesign = root.getAttribute('data-es-default') || 'gazeta';
  var systemDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  /* ---------------------------------------------------------------- keep the reading place */
  // Before a change that moves the layout, remember the first visible block of the text and
  // where it was on screen; afterwards scroll so that the same block is in the same place.
  function snapshot() {
    if (window.scrollY < 40) { return null; }
    var candidates = $$('#es-main .es-story, #es-main .es-article__head, #es-main .es-prose > *, #es-main .es-brief__block, #es-main .es-sources, #es-main .es-issue-row, #es-main .es-digest');
    var limit = window.innerHeight * 0.4;
    var partial = null;
    for (var i = 0; i < candidates.length; i++) {
      var rect = candidates[i].getBoundingClientRect();
      if (rect.height === 0 || rect.bottom <= 0) { continue; }
      if (rect.top >= 0) {
        // A block that starts on screen, in the upper part, is the best anchor.
        if (rect.top <= limit || !partial) { return { el: candidates[i], top: rect.top, part: 0 }; }
        break;
      }
      if (!partial) { partial = { el: candidates[i], top: rect.top, part: -rect.top / rect.height }; }
    }
    return partial;
  }
  function restore(snap) {
    if (!snap) { return; }
    window.requestAnimationFrame(function () {
      var rect = snap.el.getBoundingClientRect();
      // For a block that was already partly scrolled away, keep the same share of it above the edge.
      var now = snap.part ? rect.top + snap.part * rect.height : rect.top;
      var then = snap.part ? 0 : snap.top;
      if (Math.abs(now - then) > 1) {
        // An instant jump: the page has to stay put, not glide (the CSS asks for smooth anchors).
        var behavior = root.style.scrollBehavior;
        root.style.scrollBehavior = 'auto';
        window.scrollBy(0, now - then);
        root.style.scrollBehavior = behavior;
      }
    });
  }
  function keepPlace(change) {
    var snap = snapshot();
    change();
    restore(snap);
  }

  /* ---------------------------------------------------------------- apply settings */
  function currentTheme() {
    var stored = store.get('es-theme');
    if (stored === 'light' || stored === 'dark') { return stored; }
    if (stored === null && store.get('es-night') === '1') { return 'dark'; }
    return 'auto';
  }
  function applyTheme(choice) {
    if (choice === 'light' || choice === 'dark') {
      root.setAttribute('data-es-theme', choice);
      root.removeAttribute('data-es-theme-auto');
    } else {
      root.setAttribute('data-es-theme', systemDark && systemDark.matches ? 'dark' : 'light');
      root.setAttribute('data-es-theme-auto', '1');
    }
  }
  function applySize(value) {
    root.setAttribute('data-es-size', String(value));
  }
  function applyDensity(value) {
    if (value === 'airy') { root.setAttribute('data-es-density', 'airy'); } else { root.removeAttribute('data-es-density'); }
  }
  function applyDesign(value) {
    if (!locked && DESIGNS.indexOf(value) !== -1) { root.setAttribute('data-es-design', value); }
  }

  // The system palette can change while the page is open (evening mode on a phone).
  if (systemDark) {
    var onSystem = function () { if (root.getAttribute('data-es-theme-auto') === '1') { applyTheme('auto'); } };
    if (systemDark.addEventListener) { systemDark.addEventListener('change', onSystem); } else if (systemDark.addListener) { systemDark.addListener(onSystem); }
  }

  /* ---------------------------------------------------------------- panel «Оформление» */
  var toggle = $('[data-es-prefs-toggle]');
  var panel = $('[data-es-prefs]');
  if (toggle && panel && !locked) {
    toggle.hidden = false;
    var sizeOut = $('[data-es-size-value]', panel);
    var stepDown = $('[data-es-size-step="-1"]', panel);
    var stepUp = $('[data-es-size-step="1"]', panel);

    var sync = function () {
      var design = root.getAttribute('data-es-design');
      var theme = currentTheme();
      var density = root.getAttribute('data-es-density') === 'airy' ? 'airy' : 'normal';
      $$('input[data-es-pref]', panel).forEach(function (input) {
        var kind = input.getAttribute('data-es-pref');
        var value = kind === 'design' ? design : kind === 'theme' ? theme : density;
        input.checked = input.value === value;
      });
      var size = parseInt(root.getAttribute('data-es-size'), 10) || 100;
      if (sizeOut) { sizeOut.textContent = size + '%'; }
      if (stepDown) { stepDown.disabled = size <= SIZES[0]; }
      if (stepUp) { stepUp.disabled = size >= SIZES[SIZES.length - 1]; }
    };

    var open = function () {
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      sync();
      var first = $('input[data-es-pref]:checked', panel) || $('input[data-es-pref]', panel);
      if (first) { first.focus({ preventScroll: true }); }
    };
    var close = function (returnFocus) {
      if (panel.hidden) { return; }
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      if (returnFocus) { toggle.focus(); }
    };

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      if (panel.hidden) { open(); } else { close(false); }
    });
    var closeButton = $('[data-es-prefs-close]', panel);
    if (closeButton) { closeButton.addEventListener('click', function () { close(true); }); }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !panel.hidden) { close(true); }
    });
    document.addEventListener('click', function (e) {
      if (!panel.hidden && !panel.contains(e.target) && !toggle.contains(e.target)) { close(false); }
    });

    panel.addEventListener('change', function (e) {
      var input = e.target;
      if (!input || !input.getAttribute) { return; }
      var kind = input.getAttribute('data-es-pref');
      var value = input.value;
      if (kind === 'design' && DESIGNS.indexOf(value) !== -1) {
        keepPlace(function () { applyDesign(value); });
        store.set('es-design', value);
      } else if (kind === 'theme') {
        applyTheme(value);
        store.set('es-theme', value);
        store.drop('es-night');
      } else if (kind === 'density') {
        keepPlace(function () { applyDensity(value); });
        if (value === 'airy') { store.set('es-density', 'airy'); } else { store.drop('es-density'); }
      }
      sync();
    });

    $$('[data-es-size-step]', panel).forEach(function (button) {
      button.addEventListener('click', function () {
        var size = parseInt(root.getAttribute('data-es-size'), 10) || 100;
        var index = SIZES.indexOf(size);
        if (index === -1) { index = 1; }
        var next = SIZES[Math.min(SIZES.length - 1, Math.max(0, index + parseInt(button.getAttribute('data-es-size-step'), 10)))];
        keepPlace(function () { applySize(next); });
        store.set('es-size', String(next));
        store.drop('es-reader-percent');
        sync();
      });
    });

    var reset = $('[data-es-prefs-reset]', panel);
    if (reset) {
      reset.addEventListener('click', function () {
        ['es-design', 'es-theme', 'es-size', 'es-density', 'es-night', 'es-reader-percent'].forEach(store.drop);
        keepPlace(function () {
          applyDesign(serverDesign);
          applyTheme('auto');
          applySize(100);
          applyDensity('normal');
        });
        sync();
      });
    }
    sync();
  }

  /* ---------------------------------------------------------------- region filter */
  var filterButtons = $$('[data-es-filter]');
  if (filterButtons.length) {
    $$('[data-es-filters]').forEach(function (el) { el.hidden = false; });
    filterButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        var region = button.getAttribute('data-es-filter');
        $$('.es-flow > .es-story').forEach(function (story) {
          var own = story.getAttribute('data-es-region');
          var show = region === '' || own === region || own === 'mixed';
          if (show) { story.removeAttribute('data-es-hidden'); } else { story.setAttribute('data-es-hidden', ''); }
        });
        filterButtons.forEach(function (b) { b.setAttribute('aria-pressed', String(b === button)); });
      });
    });
  }

  /* ---------------------------------------------------------------- sticky offset & progress */
  function stickyOffset() {
    var bar = document.getElementById('wpadminbar');
    var top = (bar && window.getComputedStyle(bar).position === 'fixed' ? bar.offsetHeight : 0) + 24;
    root.style.setProperty('--es-sticky-top', top + 'px');
  }
  stickyOffset();
  window.addEventListener('resize', stickyOffset);

  var prose = $('[data-es-prose]');
  var progress = $('.es-progress');
  if (prose && progress) {
    var ticking = false;
    var update = function () {
      ticking = false;
      var rect = prose.getBoundingClientRect();
      var total = rect.height - window.innerHeight * 0.6;
      var done = total > 0 ? Math.min(1, Math.max(0, -rect.top / total)) : 1;
      progress.style.setProperty('--es-read', done.toFixed(4));
    };
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; window.requestAnimationFrame(update); } }, { passive: true });
    update();
  }

  /* ---------------------------------------------------------------- copy helpers */
  function copyText(text, statusEl, okMessage) {
    var fallback = function () {
      if (!statusEl) { return; }
      statusEl.textContent = 'Скопируйте текст ниже:';
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('aria-label', 'Текст для копирования');
      statusEl.appendChild(area);
      area.focus();
      area.select();
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function () { if (statusEl) { statusEl.textContent = okMessage; } }, fallback);
    } else {
      fallback();
    }
  }

  $$('[data-es-copy-link]').forEach(function (button) {
    button.hidden = false;
    var status = $('[data-es-copy-status]');
    button.addEventListener('click', function () {
      var url = button.getAttribute('data-es-copy-link');
      if (navigator.share && window.matchMedia('(pointer: coarse)').matches) {
        navigator.share({ url: url, title: document.title }).catch(function () {});
        return;
      }
      copyText(url, status, 'Ссылка скопирована.');
    });
  });

  /* ---------------------------------------------------------------- digest & cards */
  var dataEl = document.getElementById('es-digest-data');
  if (!dataEl) { return; }
  var data;
  try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }
  var status = $('[data-es-digest-status]');

  function digestText() {
    var head = data.brand + ' · ' + (data.draft ? 'ЧЕРНОВИК' : data.number) + ' · ' + data.human_date;
    var body = data.items.map(function (item, i) {
      var lines = [(i + 1) + '. ' + item.telegram];
      if (item.disclosure) { lines.push('Связь с издателем: ' + item.disclosure); }
      if (item.url) { lines.push(item.url); }
      return lines.join('\n');
    }).join('\n\n');
    return head + '\n\n' + body + (data.issue_url ? '\n\nВесь выпуск: ' + data.issue_url : '');
  }
  var copyDigest = $('[data-es-copy-digest]');
  if (copyDigest) {
    copyDigest.hidden = false;
    copyDigest.addEventListener('click', function () { copyText(digestText(), status, 'Дайджест скопирован — можно вставлять в мессенджер.'); });
  }

  function wrap(ctx, text, maxWidth) {
    var words = String(text).split(/\s+/), lines = [], line = '';
    words.forEach(function (word) {
      var test = line ? line + ' ' + word : word;
      if (ctx.measureText(test).width > maxWidth && line) { lines.push(line); line = word; } else { line = test; }
    });
    if (line) { lines.push(line); }
    return lines;
  }

  function fitText(ctx, text, family, weight, maxSize, minSize, width, height, leading) {
    for (var size = maxSize; size >= minSize; size -= 2) {
      ctx.font = weight + ' ' + size + 'px ' + family;
      var lines = wrap(ctx, text, width);
      if (lines.length * size * leading <= height) { return { size: size, lines: lines }; }
    }
    return null;
  }

  function drawCard(item) {
    var W = 1080, H = 1350, M = 88;
    var serif = '"ES Serif", Georgia, serif';
    var sans = 'Roboto, "Helvetica Neue", Arial, sans-serif';
    var c = document.createElement('canvas');
    c.width = W; c.height = H;
    var x = c.getContext('2d');
    var paper = data.palette.paper, ink = data.palette.ink, accent = data.palette.accent;

    x.fillStyle = paper; x.fillRect(0, 0, W, H);
    x.strokeStyle = ink; x.lineWidth = 3; x.strokeRect(40, 40, W - 80, H - 80);
    x.lineWidth = 1; x.globalAlpha = 0.45; x.strokeRect(52, 52, W - 104, H - 104); x.globalAlpha = 1;

    x.fillStyle = accent; x.font = '700 24px ' + sans;
    if ('letterSpacing' in x) { x.letterSpacing = '5px'; }
    x.fillText('МУЗЫКА · ЛЮДИ · СОБЫТИЯ', M, 122);

    x.fillStyle = ink;
    if ('letterSpacing' in x) { x.letterSpacing = '14px'; }
    var over = fitText(x, String(data.over || '').toUpperCase(), serif, '600', 40, 22, W - 2 * M, 50, 1);
    if (over) { x.fillText(over.lines.join(' '), M, 196); }
    if ('letterSpacing' in x) { x.letterSpacing = '0px'; }
    var mast = fitText(x, String(data.main || data.brand).toUpperCase() + '.', serif, '700', 160, 60, W - 2 * M, 170, 0.9);
    if (mast) { x.fillText(mast.lines[0], M - 6, 340); }
    x.fillRect(M, 372, W - 2 * M, 6);
    x.fillRect(M, 384, W - 2 * M, 2);

    x.fillStyle = accent; x.font = '700 26px ' + sans;
    if ('letterSpacing' in x) { x.letterSpacing = '4px'; }
    var rubric = (item.number < 10 ? '0' : '') + item.number + '  /  ' + String(item.rubric).toUpperCase();
    x.fillText(rubric, M, 456);
    if ('letterSpacing' in x) { x.letterSpacing = '0px'; }

    var top = 500, bottom = item.disclosure ? 1110 : 1150;
    var fitted = fitText(x, item.instagram, serif, '700', 78, 34, W - 2 * M, bottom - top, 1.2);
    if (!fitted) {
      if (status) { status.textContent = 'Фраза слишком длинная для карточки. Сократите её в редакции.'; }
      return null;
    }
    x.font = '700 ' + fitted.size + 'px ' + serif;
    var block = fitted.lines.length * fitted.size * 1.2;
    var y = top + Math.max(0, (bottom - top - block) * 0.38) + fitted.size;

    x.save();
    x.globalAlpha = 0.09; x.fillStyle = accent;
    x.font = 'italic 600 300px ' + serif; x.textAlign = 'right';
    x.fillText((item.number < 10 ? '0' : '') + item.number, W - 70, 1160);
    x.restore();

    x.fillStyle = ink;
    fitted.lines.forEach(function (line, i) { x.fillText(line, M, y + i * fitted.size * 1.2); });

    if (item.disclosure) {
      x.fillStyle = accent; x.font = '700 21px ' + sans;
      x.fillText('СВЯЗЬ С ИЗДАТЕЛЕМ РАСКРЫТА В СТАТЬЕ', M, 1158);
    }
    x.fillStyle = ink; x.fillRect(M, 1186, W - 2 * M, 2);
    x.font = '700 24px ' + sans;
    if ('letterSpacing' in x) { x.letterSpacing = '3px'; }
    x.fillText((data.human_date + ' · ' + (data.draft ? 'ЧЕРНОВИК' : data.number)).toUpperCase(), M, 1244);
    x.textAlign = 'right';
    x.fillText((window.location.host + '/gazeta').toUpperCase(), W - M, 1244);
    x.textAlign = 'left';
    if ('letterSpacing' in x) { x.letterSpacing = '0px'; }

    if (data.draft) {
      x.save(); x.translate(W / 2, H / 2); x.rotate(-0.36);
      x.globalAlpha = 0.16; x.fillStyle = accent; x.font = '700 150px ' + sans; x.textAlign = 'center';
      x.fillText('ЧЕРНОВИК', 0, 0); x.restore();
    }
    return c;
  }

  function deliver(canvas, name) {
    canvas.toBlob(function (blob) {
      if (!blob) { return; }
      var file = window.File ? new File([blob], name, { type: 'image/png' }) : null;
      if (file && navigator.canShare && navigator.canShare({ files: [file] }) && window.matchMedia('(pointer: coarse)').matches) {
        navigator.share({ files: [file] }).catch(function () {});
        return;
      }
      var url = URL.createObjectURL(blob);
      var link = document.createElement('a');
      link.href = url; link.download = name;
      document.body.appendChild(link); link.click(); link.remove();
      window.setTimeout(function () { URL.revokeObjectURL(url); }, 10000);
      if (status) { status.textContent = 'Карточка сохранена: ' + name; }
    }, 'image/png');
  }

  $$('[data-es-png]').forEach(function (button) {
    button.hidden = false;
    button.addEventListener('click', function () {
      var item = null;
      data.items.forEach(function (it) { if (it.id === button.getAttribute('data-es-png')) { item = it; } });
      if (!item) { return; }
      var ready = document.fonts && document.fonts.load
        ? Promise.all([document.fonts.load('700 80px "ES Serif"'), document.fonts.load('italic 600 80px "ES Serif"')])
        : Promise.resolve();
      ready.catch(function () {}).then(function () {
        var canvas = drawCard(item);
        if (canvas) { deliver(canvas, 'standart-' + data.date + '-' + item.id + '-1080x1350.png'); }
      });
    });
  });
})();
