/* Kozlov Slider Stats 1.0.1: the home-page slider in Yandex Metrika.
   Goals (each with event parameters under «Слайдер на главной» › variant › ...):
   - kzsl_view        the slider was at least half on screen for 1 s (once per page view)
   - kzsl_slide_view  a slide stayed current for 1 s while the slider was on screen (once per slide per page view)
   - kzsl_swipe       the visitor moved the slider himself (goal once per page view; every other method as a parameter)
   - kzsl_click       any click on a slide: picture, title, button
   - kzsl_buy         the click was on a buy / register / book-a-table button inside the slider (a button, not a payment)
   - kzsl_buy_next    on a later page in the same tab (up to 2 h) a buy button was pressed after a slider click
   Every slide has a stable id: the address of its page (the concert slug, «novyj-god», «gazeta»), the same on
   desktop and phone. Variants: «Десктоп», «Планшет», «Телефон · фасад», «Телефон · театр теней», «Телефон · обычный слайдер».
   Safety: the script only listens (capture, passive), never changes the page or cancels a click, ignores
   synthetic clicks, has no globals (settings come from <script type="application/json" id="kzsl-config">)
   and swallows its own errors. Events wait up to 20 s for Metrika if its code is late.
   ?kzsl=debug turns on the check mode for this tab: events are shown in a panel at the bottom and nothing goes to
   Metrika or to the real slider-click memory; ?kzsl=stop turns it off. */
(function () {
  "use strict";

  var win = window, doc = document, html = doc.documentElement;
  var cfg = null;
  try {
    var el = doc.getElementById("kzsl-config");
    cfg = el ? JSON.parse(el.textContent || "null") : null;
  } catch (e) { cfg = null; }
  if (!cfg || !cfg.counter) return;

  function now() { return new Date().getTime(); }

  var COUNTER = parseInt(cfg.counter, 10);
  var SILENT = !!cfg.silent;
  var HOME = !!cfg.home;
  var NEXT = !!cfg.next;
  var TOP = "Слайдер на главной";
  var KEEP_MS = 2 * 60 * 60 * 1000;
  var WAIT_YM_MS = 20000;
  var PV = now() + "-" + Math.random().toString(36).slice(2, 8); // this page view
  var BUY_SEL = "[tc-event],[data-tc-event],.open__primary__widget,.btn-widget-buy:not([href])";
  var BUY_TYPES = { "Купить билет": 1, "Регистрация": 1, "Бронь стола": 1 };

  var DEBUG = false;
  try {
    var q = win.location.search || "";
    if (/[?&]kzsl=debug(&|$)/.test(q)) win.sessionStorage.setItem("kzsl_debug", "1");
    if (/[?&]kzsl=stop(&|$)/.test(q)) win.sessionStorage.removeItem("kzsl_debug");
    DEBUG = win.sessionStorage.getItem("kzsl_debug") === "1";
  } catch (e) { DEBUG = /[?&]kzsl=debug(&|$)/.test(win.location.search || ""); }
  // the check mode keeps its own memory of slider clicks, so a test never reaches real statistics
  var STORE = DEBUG ? "kzsl_click_debug" : "kzsl_click";

  function clean(s) { return String(s == null ? "" : s).replace(/\s+/g, " ").replace(/^\s+|\s+$/g, ""); }
  function cut(s, n) { s = clean(s); return s.length > n ? s.slice(0, n - 1) + "…" : s; }
  function arr(list) { return Array.prototype.slice.call(list || []); }
  function matches(node, sel) {
    var f = node && node.nodeType === 1 && (node.matches || node.msMatchesSelector || node.webkitMatchesSelector);
    try { return f ? f.call(node, sel) : false; } catch (e) { return false; }
  }
  function closest(node, sel) {
    while (node && node.nodeType === 1) { if (matches(node, sel)) return node; node = node.parentElement; }
    return null;
  }
  function elementOf(t) { return t && t.nodeType === 1 ? t : (t && t.parentElement) || null; }
  function parseUrl(href) {
    var a = doc.createElement("a");
    a.href = href;
    var path = a.pathname || "/";
    try { path = decodeURIComponent(path); } catch (e) { /* keep as is */ }
    return { host: a.host, path: path };
  }
  function pathOf(href) {
    try { return parseUrl(href).path.replace(/\/+$/, "") || "/"; } catch (e) { return ""; }
  }
  function tcOf(node) {
    if (!node) return "";
    var b = matches(node, "[tc-event],[data-tc-event]") ? node : node.querySelector("[tc-event],[data-tc-event]");
    return b ? clean(b.getAttribute("tc-event") || b.getAttribute("data-tc-event")) : "";
  }
  function trusted(e) { return !e || e.isTrusted !== false; }

  /* ---------- memory of the last slider click (this tab, 2 h) ---------- */

  function remember(rec) {
    if (SILENT && !DEBUG) return; // a logged-in editor leaves no trace for later pages
    try { win.sessionStorage.setItem(STORE, JSON.stringify(rec)); } catch (e) { /* private mode */ }
  }
  function recall() {
    try {
      var r = JSON.parse(win.sessionStorage.getItem(STORE) || "null");
      if (r && r.t && now() - r.t >= 0 && now() - r.t < KEEP_MS) return r;
    } catch (e) { /* nothing stored */ }
    return null;
  }

  // other pages: nothing to do unless this tab clicked the slider less than 2 h ago
  if (!HOME && (!NEXT || !recall())) return;

  /* ---------- sending ---------- */

  // ["Десктоп", "Клик", "nani-eva-skazochnyj-novyj-god"], "Купить билет"
  //   -> {"Слайдер на главной": {"Десктоп": {"Клик": {"nani-eva-skazochnyj-novyj-god": "Купить билет"}}}}
  function tree(path, leaf) {
    var o = leaf;
    for (var i = path.length - 1; i >= 0; i--) { var n = {}; n[path[i]] = o; o = n; }
    var r = {};
    r[TOP] = o;
    return r;
  }
  function flat(o) {
    var out = [];
    (function walk(x) {
      if (x && typeof x === "object") { for (var k in x) if (Object.prototype.hasOwnProperty.call(x, k)) { out.push(k); walk(x[k]); } }
      else if (x != null) out.push(String(x));
    })(o);
    return out.slice(1).join(" › ");
  }

  var box = null, lines = [];
  function panel(text) {
    try {
      if (!doc.body) return;
      if (!box) {
        box = doc.createElement("div");
        box.className = "kzsl-debug";
        box.setAttribute("style", "position:fixed;left:8px;right:8px;bottom:8px;z-index:2147483647;max-height:42vh;overflow:hidden;" +
          "background:rgba(18,18,18,.9);color:#fff;font:12px/1.4 -apple-system,system-ui,sans-serif;padding:8px 10px;border-radius:8px;pointer-events:none");
        doc.body.appendChild(box);
      }
      if (text) { lines.unshift(text); lines = lines.slice(0, 8); }
      box.textContent = "";
      var h = doc.createElement("div");
      h.setAttribute("style", "opacity:.65;margin-bottom:4px");
      h.textContent = "Слайдер в Метрике · проверка, в Метрику не отправляется. Выключить: ?kzsl=stop" + (SILENT ? " · вы вошли как редактор" : "");
      box.appendChild(h);
      for (var i = 0; i < lines.length; i++) {
        var d = doc.createElement("div");
        d.textContent = lines[i];
        box.appendChild(d);
      }
    } catch (e) { /* debug only */ }
  }

  // events wait for Metrika: the theme defines ym() in <head>, but an optimizer could move or delay that code
  var queue = [], waitFrom = 0, waitTimer = null;
  function flush() {
    waitTimer = null;
    if (typeof win.ym === "function") {
      while (queue.length) {
        var it = queue.shift();
        try {
          if (it[0]) win.ym(COUNTER, "reachGoal", it[0], it[1]);
          else win.ym(COUNTER, "params", it[1]);
        } catch (e) { /* never disturb the page */ }
      }
      waitFrom = 0;
      return;
    }
    if (now() - waitFrom > WAIT_YM_MS) { queue = []; return; } // Metrika never came (blocked): drop quietly
    waitTimer = win.setTimeout(flush, 500);
  }
  function send(goal, params) {
    try {
      if (DEBUG) {
        panel((goal || "параметры") + " · " + flat(params));
        if (win.console && win.console.info) win.console.info("[kzsl]", goal || "params", params);
        return;
      }
      if (SILENT) return;
      queue.push([goal, params || {}]);
      if (typeof win.ym === "function") { flush(); return; }
      if (!waitFrom) waitFrom = now();
      if (!waitTimer) waitTimer = win.setTimeout(flush, 500);
    } catch (e) { /* never disturb the page */ }
  }

  /* ---------- the slider on the home page ---------- */

  var S = null; // { kind: "kzf" | "owl", box: element, root: element }

  function detect() {
    var root = doc.getElementById("kzf-root");
    if (root && !root.hidden && html.classList.contains("kzf-on")) {
      var card = root.querySelector(".kzf-card");
      if (card) return { kind: "kzf", box: card, root: root };
    }
    var owl = doc.querySelector(".content_home .slider-wrapper .slider");
    return owl ? { kind: "owl", box: owl } : null;
  }

  function variantOf(s) {
    if (s.kind === "kzf") return s.root.classList.contains("kzf-shadows") ? "Телефон · театр теней" : "Телефон · фасад";
    var w = win.innerWidth || html.clientWidth || 0;
    return w >= 1025 ? "Десктоп" : (w >= 768 ? "Планшет" : "Телефон · обычный слайдер");
  }

  function slidesOf(s) {
    if (s.kind === "kzf") return arr(s.box.querySelectorAll(".kzf-slide"));
    var real = arr(s.box.querySelectorAll(".owl-item:not(.cloned) .slide"));
    return real.length ? real : arr(s.box.querySelectorAll(".slides .slide"));
  }

  function textWithout(node, dropSel) {
    if (!node) return "";
    var c = node.cloneNode(true);
    arr(c.querySelectorAll(dropSel)).forEach(function (x) { if (x.parentNode) x.parentNode.removeChild(x); });
    return clean(c.textContent);
  }

  // stable id of a slide: the address of its page. «nani-eva-skazochnyj-novyj-god» (a concert: WordPress keeps the
  // slug unique, a repeated concert gets -2, -9 ...), «novyj-god» (a landing page), «gazeta», another site: «host/path»
  function slideId(slide, pos) {
    if (slide.querySelector(".kzf-paper")) return "gazeta";
    var a = slide.querySelector(".title a[href], .kzf-title a[href], .thumbnail a[href], a.kzf-photo[href], a.kzf-act[href], a.kzf-buy[href], a[href]");
    if (a) {
      try {
        var u = parseUrl(a.getAttribute("href"));
        var path = u.path.replace(/^\/+|\/+$/g, "");
        if (!u.host || u.host === win.location.host) {
          if (/^event\//.test(path)) path = path.slice(6);
          if (/^gazeta(\/|$)/.test(path)) return "gazeta";
          return cut(path || "главная", 90);
        }
        return cut(u.host + (path ? "/" + path : ""), 90);
      } catch (e) { /* fall through */ }
    }
    var tc = tcOf(slide);
    return tc ? "ticketscloud-" + tc : "слайд " + (pos + 1);
  }

  // readable name for the slide-view report: «НАНИ ЕВА · 31 дек», «НОВЫЙ ГОД 2026-2027», «Джазета»
  function label(s, slide) {
    var title = "", date = "";
    if (s.kind === "kzf") {
      if (slide.querySelector(".kzf-paper")) return "Джазета";
      title = clean((slide.querySelector(".kzf-title") || {}).textContent);
      var w = slide.querySelector(".kzf-when");
      date = w ? clean(clean(w.textContent).split("·")[0].replace(/^[А-ЯЁа-яё]{2},\s*/, "")) : "";
      // a card without a date (a landing page like «НОВЫЙ ГОД» + «2026-2027»): the eyebrow is part of its name
      var eb = clean((slide.querySelector(".kzf-eyebrow") || {}).textContent);
      if (!date && eb && !/^концерт$/i.test(eb)) title = eb + " " + title;
    } else {
      title = textWithout(slide.querySelector(".title a") || slide.querySelector(".title"), ".kz-sub");
      var d = slide.querySelector(".datetime");
      date = d ? clean(clean(d.textContent).split("|")[0]) : "";
    }
    // the same date reads the same on every variant: «31 Дек» and «31 декабря» both become «31 дек»
    var m = /(\d{1,2})\s+([А-ЯЁа-яё]{3})/.exec(date);
    if (m) date = m[1] + " " + m[2].toLowerCase();
    return cut(title || "Без названия", 70) + (date ? " · " + cut(date, 24) : "");
  }

  function keyOf(slide) {
    var a = slide.querySelector(".title a, .kzf-title a, a[href]");
    return (a ? a.getAttribute("href") : "") + "|" + cut(slide.textContent, 80);
  }

  function currentIndex(s, slides) {
    if (s.kind === "kzf") {
      var tr = s.box.querySelector(".kzf-track");
      if (!tr || !tr.clientWidth) return 0;
      return Math.max(0, Math.min(slides.length - 1, Math.round(tr.scrollLeft / tr.clientWidth)));
    }
    var act = s.box.querySelector(".owl-item.active .slide");
    if (!act) return 0;
    var k = keyOf(act);
    for (var i = 0; i < slides.length; i++) if (slides[i] === act || keyOf(slides[i]) === k) return i;
    return 0;
  }

  function styleShown(node) {
    try { return win.getComputedStyle(node).visibility !== "hidden"; } catch (e) { return true; }
  }
  // at least half of the block (or half of the screen, if the block is taller) is visible
  function onScreen(node) {
    if (doc.hidden || !styleShown(node)) return false;
    var r = node.getBoundingClientRect();
    if (!r.width || !r.height) return false;
    var vh = win.innerHeight || html.clientHeight || 0;
    var shown = Math.min(r.bottom, vh) - Math.max(r.top, 0);
    return shown >= Math.min(r.height, vh) * 0.5;
  }

  var viewSent = false, visSince = 0, cur = -1, curSince = 0, seen = {}, seenCount = 0, timer = null, done = false;
  var swipeHow = {}, swipeCount = 0;

  /* visibility: IntersectionObserver wakes the 0.5 s check only while the slider is on screen and the tab is open */
  var io = null, vis = [];
  function setVis(node, v) { for (var i = 0; i < vis.length; i++) if (vis[i][0] === node) { vis[i][1] = v; return; } vis.push([node, v]); }
  function getVis(node) { for (var i = 0; i < vis.length; i++) if (vis[i][0] === node) return vis[i][1]; return null; }
  function isOn(node) {
    var v = io ? getVis(node) : null;
    if (v === null) return onScreen(node);
    return v && !doc.hidden && styleShown(node);
  }
  function wake() { if (!timer && !done) { timer = win.setInterval(tick, 500); tick(); } }
  function sleep() { if (timer) { win.clearInterval(timer); timer = null; } }
  function watch() {
    if (!win.IntersectionObserver) return;
    try {
      if (!io) {
        io = new win.IntersectionObserver(function (entries) {
          try {
            entries.forEach(function (en) {
              var rb = en.rootBounds, need = Math.min(en.boundingClientRect.height, rb ? rb.height : en.boundingClientRect.height) * 0.5;
              setVis(en.target, en.isIntersecting && en.intersectionRect.height > 0 && en.intersectionRect.height >= need);
            });
            wake();
          } catch (e) { /* keep quiet */ }
        }, { threshold: [0, 0.25, 0.5, 0.75, 1] });
      }
      // both possible sliders are watched: if the facade gives up, the old slider wakes the check
      var root = doc.getElementById("kzf-root"), card = root && root.querySelector(".kzf-card");
      var owl = doc.querySelector(".content_home .slider-wrapper .slider");
      if (card) io.observe(card);
      if (owl) io.observe(owl);
    } catch (e) { io = null; }
  }

  function stillValid(s) {
    if (!s || !html.contains(s.box)) return false;
    if (s.kind === "kzf" && (s.root.hidden || !html.classList.contains("kzf-on"))) return false; // the facade gave up
    return true;
  }

  function tick() {
    try {
      if (!stillValid(S)) { S = detect(); visSince = 0; cur = -1; if (!S) { sleep(); return; } }
      var t = now();
      if (!isOn(S.box)) { visSince = 0; cur = -1; if (io) sleep(); return; }
      if (!visSince) visSince = t;
      var slides = slidesOf(S);
      if (!slides.length) return;
      var i = currentIndex(S, slides);
      if (i !== cur) { cur = i; curSince = t; }
      var v = variantOf(S);
      if (!viewSent && t - visSince >= 1000) {
        viewSent = true;
        send("kzsl_view", tree([v, "Показ слайдера"], "слайдов: " + slides.length));
      }
      if (viewSent && t - curSince >= 1000) {
        var id = slideId(slides[i], i);
        if (!seen[id]) {
          seen[id] = 1;
          seenCount++;
          send("kzsl_slide_view", tree([v, "Показ слайда", id], "№" + (i + 1) + " · " + label(S, slides[i])));
        }
      }
      if (viewSent && seenCount >= slides.length) {
        done = true;
        sleep();
        if (io) { try { io.disconnect(); } catch (e) { /* ignore */ } }
      }
    } catch (e) { /* keep quiet */ }
  }

  // every method once per page view: the first one is the goal, the others are parameters
  function swiped(how) {
    if (!S || swipeHow[how]) return;
    swipeHow[how] = 1;
    swipeCount++;
    send(swipeCount === 1 ? "kzsl_swipe" : null, tree([variantOf(S), "Листание"], how));
  }

  function navHow(t) {
    if (closest(t, ".owl-nav, .owl-prev, .owl-next")) return "стрелки";
    if (closest(t, ".titles .nav")) return "вкладки";
    if (closest(t, ".kzf-dot, .kzf-dots")) return "точки";
    return "";
  }

  function classify(ctl) {
    if (matches(ctl, ".open__primary__widget")) return "Бронь стола";
    if (matches(ctl, "[tc-event],[data-tc-event],.btn-widget-buy:not([href])")) return /регистрац|register/i.test(ctl.textContent || "") ? "Регистрация" : "Купить билет";
    if (matches(ctl, ".thumbnail a, .kzf-photo, .kzf-paper")) return "Картинка";
    if (matches(ctl, ".title a, .kzf-title a")) return "Название";
    var txt = cut(ctl.textContent, 30);
    return txt ? "Кнопка «" + txt + "»" : "Кнопка";
  }

  var lastCtl = null, lastAt = 0;
  function dup(ctl) {
    var t = now();
    if (ctl === lastCtl && t - lastAt < 1500) return true;
    lastCtl = ctl;
    lastAt = t;
    return false;
  }

  function activate(target, viaTouch) {
    var t = elementOf(target);
    if (!t) return;

    if (HOME && !S) S = detect();
    if (HOME && S && S.box.contains(t)) {
      var how = navHow(t);
      if (how) { if (!viaTouch) swiped(how); return; }
      var slide = closest(t, ".kzf-slide, .slide");
      var ctl = closest(t, "a[href], button, [tc-event], [data-tc-event], .open__primary__widget, .btn-widget-buy");
      if (!slide || !ctl || !slide.contains(ctl)) return;
      if (viaTouch && !matches(ctl, BUY_SEL)) return; // links are counted on the click that follows the tap
      if (dup(ctl)) return;
      var slides = slidesOf(S), pos = slides.indexOf(slide);
      var v = variantOf(S), id = slideId(slide, pos < 0 ? 0 : pos), type = classify(ctl);
      send("kzsl_click", tree([v, "Клик", id], type));
      if (BUY_TYPES[type]) send("kzsl_buy", tree([v, "Кнопка покупки", id], type));
      var link = slide.querySelector(".title a[href], .kzf-title a[href], a[href]");
      remember({ t: now(), pv: PV, v: v, id: id, p: link ? pathOf(link.getAttribute("href")) : "", e: tcOf(slide) });
      return;
    }

    if (!NEXT) return;
    var buy = closest(t, BUY_SEL);
    if (!buy || (S && S.box.contains(buy))) return;
    var rec = recall();
    if (!rec || rec.pv === PV) return; // the same page view: no page was changed after the slider click
    if (dup(buy)) return;
    var bid = tcOf(buy);
    // the Ticketscloud id decides when both are known; the address only when the button has no id
    var same = (rec.e && bid) ? rec.e === bid : !!(rec.p && rec.p !== "/" && rec.p === pathOf(win.location.href));
    send("kzsl_buy_next", tree([rec.v || "?", "Кнопка покупки после клика", rec.id || "?"], same ? "этот же концерт" : "другой концерт"));
  }

  /* ---------- listeners: capture phase, never prevent anything, real user input only ---------- */

  var tx = 0, ty = 0, tt = 0, tStart = null, mx = 0, my = 0, mStart = null;
  var cap = { capture: true, passive: true };

  try {
    // Ticketscloud reacts to touchend on phones, so a tap on its button may never become a click: buttons are counted here
    doc.addEventListener("touchstart", function (e) {
      try {
        if (!trusted(e)) return;
        var p = e.touches && e.touches[0];
        if (!p) return;
        tx = p.clientX; ty = p.clientY; tt = now(); tStart = elementOf(e.target);
      } catch (x) { /* ignore */ }
    }, cap);
    doc.addEventListener("touchend", function (e) {
      try {
        if (!trusted(e)) return;
        var p = e.changedTouches && e.changedTouches[0];
        if (!p || !tStart) return;
        var dx = p.clientX - tx, dy = p.clientY - ty, from = tStart;
        tStart = null;
        if (HOME && S && S.box.contains(from) && Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) { swiped("свайп"); return; }
        if (Math.abs(dx) < 12 && Math.abs(dy) < 12 && now() - tt < 1000) {
          if (HOME && S && S.box.contains(from) && navHow(from)) { swiped(navHow(from)); return; }
          activate(e.target, true);
        }
      } catch (x) { /* ignore */ }
    }, cap);
    if (HOME) {
      doc.addEventListener("mousedown", function (e) {
        try { if (trusted(e)) { mx = e.clientX; my = e.clientY; mStart = elementOf(e.target); } } catch (x) { /* ignore */ }
      }, cap);
      doc.addEventListener("mouseup", function (e) {
        try {
          var from = mStart;
          mStart = null;
          if (!trusted(e) || !from || !S || !S.box.contains(from)) return;
          var dx = e.clientX - mx, dy = e.clientY - my;
          if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) swiped("перетаскивание");
        } catch (x) { /* ignore */ }
      }, cap);
    }
    doc.addEventListener("click", function (e) {
      try { if (trusted(e)) activate(e.target, false); } catch (x) { /* ignore */ }
    }, true);
  } catch (e) { /* old browser: no stats, the site works as before */ }

  /* ---------- start ---------- */

  function start() {
    if (DEBUG) panel("");
    if (!HOME) return;
    var t0 = now();
    (function wait() {
      try {
        // on phones the facade decides within ~2.5 s whether it replaces the slider: wait for that
        var root = doc.getElementById("kzf-root");
        var narrow = (win.innerWidth || html.clientWidth || 0) < 768;
        var decided = !root || !narrow || html.classList.contains("kzf-on") || html.classList.contains("kzf-off");
        if (!decided && now() - t0 < 5000) { win.setTimeout(wait, 200); return; }
        S = detect();
        if (!S) return;
        watch();
        doc.addEventListener("visibilitychange", function () { if (!doc.hidden) wake(); });
        wake();
      } catch (e) { /* keep quiet */ }
    })();
  }

  if (doc.readyState === "loading") doc.addEventListener("DOMContentLoaded", start);
  else start();
})();
