/* Kozlov Slider Stats 1.0.0: the home-page slider in Yandex Metrika.
   What is sent (goal + visit parameters under «Слайдер на главной» › variant):
   - kzsl_view      the slider was at least half on screen for 1 s (once per page view)
   - (params only)  «Показ слайда»: every slide that stayed current for 1 s while the slider was on screen
   - kzsl_swipe     the visitor moved the slider himself: arrows, tabs, dots, swipe or drag (once per page view)
   - kzsl_click     any click on a slide: picture, title, button
   - kzsl_buy       the click was on a buy / register / book-a-table button inside the slider
   - kzsl_buy_next  later in the same tab (up to 2 h) a buy button on another page was pressed
   Variants: «Десктоп», «Планшет», «Телефон · фасад», «Телефон · театр теней», «Телефон · обычный слайдер».
   Safety: the script only listens (capture, passive), never changes the page or cancels a click,
   has no globals (settings come from <script type="application/json" id="kzsl-config">), and swallows its own errors.
   ?kzsl=debug in the address: events are shown in a small panel at the bottom and in the console, nothing goes to Metrika. */
(function () {
  "use strict";

  var win = window, doc = document, html = doc.documentElement;
  var cfg = null;
  try {
    var el = doc.getElementById("kzsl-config");
    cfg = el ? JSON.parse(el.textContent || "null") : null;
  } catch (e) { cfg = null; }
  if (!cfg || !cfg.counter) return;

  var COUNTER = parseInt(cfg.counter, 10);
  var DEBUG = /[?&]kzsl=debug(&|$)/.test(win.location.search || "");
  var SILENT = !!cfg.silent;
  var HOME = !!cfg.home;
  var NEXT = !!cfg.next;
  var TOP = "Слайдер на главной";
  var STORE = "kzsl_click";
  var KEEP_MS = 2 * 60 * 60 * 1000;
  var BUY_SEL = "[tc-event],[data-tc-event],.open__primary__widget,.btn-widget-buy:not([href])";
  var BUY_TYPES = { "Купить билет": 1, "Регистрация": 1, "Бронь стола": 1 };

  function now() { return new Date().getTime(); }
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
  function pathOf(href) {
    try {
      var a = doc.createElement("a");
      a.href = href;
      return (a.pathname || "/").replace(/\/+$/, "") || "/";
    } catch (e) { return ""; }
  }
  function tcOf(node) {
    if (!node) return "";
    var b = matches(node, "[tc-event],[data-tc-event]") ? node : node.querySelector("[tc-event],[data-tc-event]");
    return b ? clean(b.getAttribute("tc-event") || b.getAttribute("data-tc-event")) : "";
  }

  /* ---------- sending ---------- */

  // ["Десктоп", "Клик", "НАНИ ЕВА · 31 дек"], "Купить билет" -> {"Слайдер на главной": {"Десктоп": {...: "Купить билет"}}}
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
      lines.unshift(text);
      lines = lines.slice(0, 8);
      box.textContent = "";
      var h = doc.createElement("div");
      h.setAttribute("style", "opacity:.65;margin-bottom:4px");
      h.textContent = "Слайдер в Метрике · проверка, в Метрику не отправляется" + (SILENT ? " (вы вошли как редактор)" : "");
      box.appendChild(h);
      for (var i = 0; i < lines.length; i++) {
        var d = doc.createElement("div");
        d.textContent = lines[i];
        box.appendChild(d);
      }
    } catch (e) { /* debug only */ }
  }

  function send(goal, params) {
    try {
      if (DEBUG) {
        panel((goal || "параметры") + " · " + flat(params));
        if (win.console && win.console.info) win.console.info("[kzsl]", goal || "params", params);
        return;
      }
      if (SILENT || typeof win.ym !== "function") return;
      if (goal) win.ym(COUNTER, "reachGoal", goal, params || {});
      else win.ym(COUNTER, "params", params);
    } catch (e) { /* never disturb the page */ }
  }

  function remember(rec) {
    try { win.sessionStorage.setItem(STORE, JSON.stringify(rec)); } catch (e) { /* private mode */ }
  }
  function recall() {
    try {
      var r = JSON.parse(win.sessionStorage.getItem(STORE) || "null");
      if (r && r.t && now() - r.t >= 0 && now() - r.t < KEEP_MS) return r;
    } catch (e) { /* nothing stored */ }
    return null;
  }

  /* ---------- the slider on the home page ---------- */

  var S = null; // { kind: "kzf" | "owl", box: element }

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

  // «НАНИ ЕВА · 31 дек», «НОВЫЙ ГОД 2026-2027», «Джазета»
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

  function onScreen(node) {
    if (doc.hidden) return false;
    var r = node.getBoundingClientRect();
    if (!r.width || !r.height) return false;
    try { if (win.getComputedStyle(node).visibility === "hidden") return false; } catch (e) { /* ignore */ }
    var vh = win.innerHeight || html.clientHeight || 0;
    var shown = Math.min(r.bottom, vh) - Math.max(r.top, 0);
    return shown >= r.height * 0.5;
  }

  var viewSent = false, visSince = 0, cur = -1, curSince = 0, seen = {}, seenCount = 0, timer = null, swipeSent = false;

  function stillValid(s) {
    if (!s || !html.contains(s.box)) return false;
    if (s.kind === "kzf" && (s.root.hidden || !html.classList.contains("kzf-on"))) return false; // the facade gave up
    return true;
  }

  function tick() {
    try {
      if (!stillValid(S)) { S = detect(); visSince = 0; cur = -1; if (!S) return; }
      var t = now();
      if (!onScreen(S.box)) { visSince = 0; cur = -1; return; }
      if (!visSince) visSince = t;
      var slides = slidesOf(S);
      if (!slides.length) return;
      var i = currentIndex(S, slides);
      if (i !== cur) { cur = i; curSince = t; }
      if (!viewSent && t - visSince >= 1000) {
        viewSent = true;
        send("kzsl_view", tree([variantOf(S), "Показ слайдера"], "да"));
      }
      if (viewSent && t - curSince >= 1000) {
        var lb = label(S, slides[i]);
        if (!seen[lb]) {
          seen[lb] = 1;
          seenCount++;
          send(null, tree([variantOf(S), "Показ слайда", lb], "позиция " + (i + 1)));
        }
      }
      if (viewSent && seenCount >= slides.length && timer) { win.clearInterval(timer); timer = null; }
    } catch (e) { /* keep quiet */ }
  }

  function swiped(how) {
    if (swipeSent || !S) return;
    swipeSent = true;
    send("kzsl_swipe", tree([variantOf(S), "Листание"], how));
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
      var v = variantOf(S), lb = label(S, slide), type = classify(ctl);
      send("kzsl_click", tree([v, "Клик", lb], type));
      if (BUY_TYPES[type]) send("kzsl_buy", tree([v, "Кнопка покупки", lb], type));
      var link = slide.querySelector(".title a[href], .kzf-title a[href], a[href]");
      remember({ t: now(), v: v, l: lb, p: link ? pathOf(link.getAttribute("href")) : "", e: tcOf(slide) });
      return;
    }

    if (!NEXT) return;
    var buy = closest(t, BUY_SEL);
    if (!buy || (S && S.box.contains(buy))) return;
    var rec = recall();
    if (!rec || dup(buy)) return;
    var id = tcOf(buy);
    var same = (rec.e && id && rec.e === id) || (rec.p && rec.p !== "/" && rec.p === pathOf(win.location.href));
    send("kzsl_buy_next", tree([rec.v || "?", "Покупка после клика", rec.l || "?"], same ? "этот же концерт" : "другой концерт"));
  }

  /* ---------- listeners: capture phase, never prevent anything ---------- */

  var tx = 0, ty = 0, tt = 0, tStart = null, mx = 0, my = 0, mStart = null;
  var cap = { capture: true, passive: true };

  try {
    // Ticketscloud reacts to touchend on phones, so a tap on its button may never become a click: buttons are counted here
    doc.addEventListener("touchstart", function (e) {
      try {
        var p = e.touches && e.touches[0];
        if (!p) return;
        tx = p.clientX; ty = p.clientY; tt = now(); tStart = elementOf(e.target);
      } catch (x) { /* ignore */ }
    }, cap);
    doc.addEventListener("touchend", function (e) {
      try {
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
    doc.addEventListener("mousedown", function (e) {
      try { mx = e.clientX; my = e.clientY; mStart = elementOf(e.target); } catch (x) { /* ignore */ }
    }, cap);
    doc.addEventListener("mouseup", function (e) {
      try {
        var from = mStart;
        mStart = null;
        if (!from || !HOME || !S || !S.box.contains(from)) return;
        var dx = e.clientX - mx, dy = e.clientY - my;
        if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) swiped("перетаскивание");
      } catch (x) { /* ignore */ }
    }, cap);
    doc.addEventListener("click", function (e) {
      try { activate(e.target, false); } catch (x) { /* ignore */ }
    }, true);
  } catch (e) { /* old browser: no stats, the site works as before */ }

  /* ---------- start ---------- */

  function start() {
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
        if (!timer) timer = win.setInterval(tick, 500);
      } catch (e) { /* keep quiet */ }
    })();
  }

  if (doc.readyState === "loading") doc.addEventListener("DOMContentLoaded", start);
  else start();
})();
