/* Kozlov Living Facade: mounts the living house above the home-page slider on phones.
   Safety rules:
   - phones only (max-width 767px); desktop is left exactly as it is
   - ?kzf=off in the address switches the block off for this visit
   - nothing replaces the slider unless there is at least one card and the block stylesheet has loaded
   - the old slider is kept invisible from the first paint (CSS printed in <head>) and is given back at
     once (class kzf-off) whenever the block does not mount; any later error puts everything back
   - while the house is on screen the site header takes the facade's paper colour (class kzf-top)
   - a ticket button that Ticketscloud does not pick up falls back to the concert page */
(function () {
  "use strict";

  var html = document.documentElement;
  var CFG = window.KZF_CONFIG;
  var root = document.getElementById("kzf-root");

  function release() { html.classList.add("kzf-off"); }

  function giveUp(e) {
    try {
      html.classList.remove("kzf-on", "kzf-top");
      release();
      if (root) {
        root.hidden = true;
        root.classList.remove("kzf-run", "kzf-playing", "kzf-lit", "kzf-dim");
      }
    } catch (x) { /* nothing else to undo */ }
    if (e && window.console) console.warn("[kzf]", e);
  }

  // wait until a stylesheet marker shows up on the root (up to `ms`)
  function whenCss(marker, ms, done, fail) {
    var t0 = Date.now();
    (function check() {
      var ok = false;
      try { ok = window.getComputedStyle(root).getPropertyValue(marker).trim() === "1"; } catch (e) { ok = false; }
      if (ok) return done();
      if (Date.now() - t0 > ms) return fail(new Error(marker + " not loaded"));
      setTimeout(check, 100);
    })();
  }

  // The house is drawn only once the page has loaded and the phone is free: on the home page the
  // site's own scripts and the long afisha keep the phone busy for the first seconds, and a drawing
  // started in that time stutters (measured on a copy of the live page: pauses of 0.2-1 s).
  // Free = the page has loaded and 8 frames in a row came on time. Never waits more than 5 s.
  function whenCalm(done) {
    var fired = false, t0 = Date.now();
    function go() { if (!fired) { fired = true; done(); } }
    setTimeout(go, 5000);
    function quiet() {
      if (!window.requestAnimationFrame) { go(); return; }
      var good = 0, last = 0;
      window.requestAnimationFrame(function tick(t) {
        if (fired) return;
        good = (last && t - last < 40) ? good + 1 : 0;
        last = t;
        if (good >= 8) { go(); return; }
        window.requestAnimationFrame(tick);
      });
    }
    if (document.readyState === "complete") quiet();
    else window.addEventListener("load", quiet);
  }

  var header = document.querySelector("header.header") || document.querySelector(".header");

  // one paper sheet: the facade runs edge to edge and up to the header, with no white strips
  function fitSheet() {
    try {
      var st = root.style;
      st.marginLeft = st.paddingLeft = st.paddingRight = st.width = st.marginTop = st.paddingTop = "";
      var vw = html.clientWidth;
      var r = root.getBoundingClientRect();
      var left = Math.round(r.left), right = Math.round(vw - r.right);
      if (left > 0 && left <= 48 && right >= 0 && right <= 48) {
        st.marginLeft = -left + "px";
        st.paddingLeft = left + "px";
        st.paddingRight = right + "px";
        st.width = vw + "px";
      }
      if (header && window.pageYOffset < 4) {
        var gap = Math.round(root.getBoundingClientRect().top - header.getBoundingClientRect().bottom);
        if (gap > 0 && gap <= 60) { st.marginTop = -gap + "px"; st.paddingTop = gap + "px"; }
      }
    } catch (e) { /* the block simply stays boxed */ }
  }

  // the header is paper-coloured while the house is under it, white again further down the page
  var headerTick = false;
  function syncHeader() {
    headerTick = false;
    if (!header || !html.classList.contains("kzf-on")) return;
    var under = root.getBoundingClientRect().bottom > header.getBoundingClientRect().bottom + 8;
    html.classList.toggle("kzf-top", under);
  }
  function onScroll() { if (!headerTick) { headerTick = true; window.requestAnimationFrame(syncHeader); } }

  // a card whose concert is over is removed here as well: the home page may come from a page cache
  // that was saved before the concert ended. Returns how many cards are left.
  function pruneExpired() {
    var now = Date.now() / 1000;
    var slides = Array.prototype.slice.call(root.querySelectorAll(".kzf-slide"));
    var dots = Array.prototype.slice.call(root.querySelectorAll(".kzf-dot"));
    var gone = false;
    slides.forEach(function (sl, i) {
      var until = parseInt(sl.getAttribute("data-kzf-until") || "0", 10);
      if (until && until < now) {
        sl.parentNode.removeChild(sl);
        if (dots[i] && dots[i].parentNode) dots[i].parentNode.removeChild(dots[i]);
        gone = true;
      }
    });
    var left = root.querySelectorAll(".kzf-slide").length;
    if (gone) {
      var box = root.querySelector(".kzf-dots");
      if (box && left < 2) box.parentNode.removeChild(box);
      var card = root.querySelector(".kzf-card");
      if (card) card.style.setProperty("--kzf-dots", left > 1 ? left : 0);
      Array.prototype.forEach.call(root.querySelectorAll(".kzf-slide"), function (sl, i) { sl.setAttribute("aria-label", (i + 1) + " из " + left); });
    }
    return left;
  }

  var slider;
  try {
    if (!CFG || !root) { release(); return; }
    if (/[?&]kzf=off\b/.test(window.location.search)) { release(); return; }
    if (!window.matchMedia || !window.matchMedia("(max-width: 767px)").matches) { release(); return; }
    slider = document.querySelector(".content_home .slider-wrapper");
    if (!slider || !slider.parentNode || !window.fetch) { release(); return; }
    pruneExpired();
    if (!root.querySelector(".kzf-slide")) { release(); return; }   // no cards: keep the old slider
  } catch (e) {
    giveUp(e);
    return;
  }

  // the drawing is fetched while the page finishes
  var svgPromise = window.fetch(CFG.heroUrl, { credentials: "same-origin" })
    .then(function (r) { if (!r.ok) throw new Error("hero " + r.status); return r.text(); })
    .then(function (t) { if (t.indexOf("<svg") === -1) throw new Error("hero markup"); return t; });
  svgPromise.catch(function () { /* handled below */ });

  // 1. as soon as the block stylesheet is there: the block takes the slider's place, card first
  whenCss("--kzf-front-css", 2500, function () {
    try {
      if (CFG.viewW && CFG.viewH) root.style.setProperty("--kzf-ratio", CFG.viewW + " / " + CFG.viewH);
      slider.parentNode.insertBefore(root, slider);
      root.hidden = false;
      html.classList.add("kzf-on");
      fitSheet();
      syncHeader();
      window.addEventListener("scroll", onScroll, { passive: true });
      window.addEventListener("resize", function () { fitSheet(); syncHeader(); });
      window.addEventListener("load", fitSheet);
    } catch (e) { giveUp(e); return; }

    // 2. the house draws itself once the drawing and its stylesheet are in
    svgPromise.then(function (svgText) {
      whenCss("--kzf-hero-css", 2500, function () {
        var still = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        (still ? function (f) { f(); } : whenCalm)(function () {
          try {
            var heroBox = root.querySelector(".kzf-hero");
            heroBox.innerHTML = svgText;
            var svg = heroBox.querySelector("svg");
            if (!svg) throw new Error("no svg");
            start(svg);
          } catch (e) { giveUp(e); }
        });
      }, giveUp);
    }).catch(giveUp);
  }, giveUp);

  function start(svg) {
    var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // the line from the club door to the card frame
    var card = root.querySelector(".kzf-card");
    function placeDoor() {
      if (!card) return;
      var s = svg.getBoundingClientRect();
      var c = card.getBoundingClientRect();
      var x = s.left + (CFG.doorX / CFG.viewW) * s.width - c.left;
      root.style.setProperty("--kzf-door", Math.max(8, Math.round(x)) + "px");
    }
    placeDoor();
    window.addEventListener("resize", placeDoor);

    // The show. Load: the house draws itself (3.7 s), the saxophonist plays: a wave of light runs
    // through the windows, the people wake and the walls take a pale watercolour. From then on the
    // band plays without stopping and the guests drink and clink now and then (up to 4 windows),
    // like the swifts and clouds that never stop.
    // Tap on the saxophonist: the house fades and redraws fast (1.3 s), card and sky stay, then the wave.
    // On the hour and half hour (Moscow): only the wave, the house is not redrawn.
    var T = { end: null, dim: null, play: null, out: null, token: null };
    var guests = Array.prototype.slice.call(svg.querySelectorAll(".kzf-who.kzf-guest"));
    function clearAll() {
      T.token = null;
      root.classList.remove("kzf-hold");   // never left holding if something else takes over
      ["end", "dim", "play", "out"].forEach(function (k) { clearTimeout(T[k]); });
    }
    function resetPeople() {
      Array.prototype.forEach.call(svg.querySelectorAll(".kzf-on, .kzf-solo"), function (el) {
        el.classList.remove("kzf-on", "kzf-solo");
      });
    }
    function sing() {
      resetPeople();
      root.classList.remove("kzf-dim", "kzf-lit", "kzf-playing", "kzf-alive");
      void root.offsetWidth;
      if (reduced) { root.classList.add("kzf-lit"); return; }
      root.classList.add("kzf-playing", "kzf-alive");
      T.end = setTimeout(function () {
        root.classList.add("kzf-lit");
        root.classList.remove("kzf-playing");
      }, 7800);
    }
    function play() {
      clearAll();
      if (!root.classList.contains("kzf-lit") && !root.classList.contains("kzf-playing")) { sing(); return; }
      root.classList.add("kzf-dim");
      root.classList.remove("kzf-alive");
      T.dim = setTimeout(sing, 380);
    }
    // Smooth start: the drawing is set up with its motion held at zero (kzf-hold) and released two
    // frames later. The heavy first frame (hundreds of lines get their styles, the picture is painted
    // for the first time) then happens before the clock starts, so the first strokes do not jump.
    function afterFrames(n, fn) {
      if (!window.requestAnimationFrame) { setTimeout(fn, 50); return; }
      (function step() { if (n-- <= 0) fn(); else window.requestAnimationFrame(step); })();
    }
    function run(fast) {
      clearAll();
      resetPeople();
      root.classList.remove("kzf-run", "kzf-playing", "kzf-lit", "kzf-dim", "kzf-alive", "kzf-out");
      root.classList.toggle("kzf-keep", !!fast);
      root.style.setProperty("--k", fast ? "0.42" : "1");
      root.style.setProperty("--kd", fast ? "0.6" : "1");
      void root.offsetWidth;
      root.classList.add("kzf-hold", "kzf-run");
      var token = T.token = {};
      afterFrames(2, function () {
        if (T.token !== token) return;
        root.classList.remove("kzf-hold");
        T.play = setTimeout(sing, reduced ? 0 : (fast ? 1300 : 3700));
      });
    }
    function replay() {
      if (reduced) { play(); return; }
      clearAll();
      root.classList.add("kzf-out");
      T.out = setTimeout(function () { run(true); }, 260);
    }
    run(false);

    // guests: now and then one of them drinks or clinks glasses
    function act(who) {
      who._kzfLast = Date.now();
      who.classList.remove("kzf-solo");
      void who.getBoundingClientRect();
      who.classList.add("kzf-solo");
      clearTimeout(who._kzfT);
      who._kzfT = setTimeout(function () { who.classList.remove("kzf-solo"); }, 3200);
    }
    setInterval(function () {
      if (reduced || document.hidden || root.classList.contains("kzf-paused") || !root.classList.contains("kzf-lit")) return;
      var now = Date.now();
      var busy = guests.filter(function (g) { return g.classList.contains("kzf-solo"); }).length;
      if (busy >= 4) return;
      var free = guests.filter(function (g) { return !g.classList.contains("kzf-solo") && now - (g._kzfLast || 0) > 5000; });
      if (free.length) act(free[Math.floor(Math.random() * free.length)]);
    }, 1200);

    // a tapped window lights up and its people move
    function solo(w) {
      var who = svg.querySelector('.kzf-who[data-w="' + w + '"]');
      var glow = svg.querySelector('.kzf-glow[data-w="' + w + '"]');
      if (!who) return;
      if (glow) glow.classList.add("kzf-on");
      who.classList.add("kzf-on");
      act(who);
    }
    function bindTap(el, fn) {
      el.addEventListener("click", fn);
      el.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); fn(); }
      });
    }
    Array.prototype.forEach.call(svg.querySelectorAll(".kzf-hit"), function (h) {
      if (h.classList.contains("kzf-saxhit")) bindTap(h, replay);
      else bindTap(h, function () { solo(h.getAttribute("data-w")); });
    });

    // in step with the figure on the street: on the hour and half hour, Moscow time
    if (CFG.streetSync) {
      var lastSlot = null;
      setInterval(function () {
        if (document.hidden) return;
        var msk = new Date(Date.now() + 3 * 3600 * 1000);
        var m = msk.getUTCMinutes();
        var slot = msk.getUTCHours() + ":" + (m < 30 ? "00" : "30");
        if ((m === 0 || m === 30) && slot !== lastSlot) { lastSlot = slot; play(); }
      }, 15000);
    }

    setInterval(function () { if (!pruneExpired()) giveUp(); }, 60000);

    // no motion while nobody sees it: tab hidden or the block scrolled away
    var offscreen = false;
    function syncPause() {
      var stop = document.hidden || offscreen;
      root.classList.toggle("kzf-paused", stop);
      try { if (stop) svg.pauseAnimations(); else svg.unpauseAnimations(); } catch (e) { /* older engines */ }
    }
    document.addEventListener("visibilitychange", syncPause);
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(function (es) { offscreen = !es[0].isIntersecting; syncPause(); }).observe(root);
    }

    // tickets. The buttons carry tc-event like the theme's own, the Ticketscloud script on the page opens
    // its window. No script on the page: straight to the concert page. Script there: watch what the click
    // adds to the page for 4 s; only a frame or a window of the widget counts (ticketscloud / tc in its
    // name, or something covering most of the screen). Nothing like that: go to the concert page.
    // looked up at the moment of the click: on the site the widget script is printed among the footer scripts
    function tcScript() { return !!document.querySelector('script[src*="ticketscloud"], #tcwidget-js'); }
    function looksLikeTicketWindow(el) {
      if (!el || el.nodeType !== 1 || root.contains(el) || !document.documentElement.contains(el)) return false;
      if (el.tagName === "IFRAME") return true;
      var name = (el.id || "") + " " + (typeof el.className === "string" ? el.className : "") + " " + (el.getAttribute("src") || "");
      if (/ticketscloud|(^|[\s_-])tc[\s_-]/i.test(" " + name + " ")) return true;
      var r = el.getBoundingClientRect(), cs = window.getComputedStyle(el);
      return (cs.position === "fixed" || cs.position === "absolute") && r.width * r.height > window.innerWidth * window.innerHeight * 0.5;
    }
    Array.prototype.forEach.call(root.querySelectorAll(".kzf-buy[tc-event]"), function (btn) {
      btn.addEventListener("click", function () {
        var href = btn.getAttribute("data-href");
        if (!href) return;
        if (!tcScript()) { window.location.href = href; return; }
        // checked both when something is added (a window the visitor closes within 4 s still counts)
        // and at the end (a window whose size or class is set a moment after it is added)
        var added = [], opened = false;
        function isWindow(n) {
          if (looksLikeTicketWindow(n)) return true;
          return n && n.querySelectorAll ? Array.prototype.some.call(n.querySelectorAll("iframe"), looksLikeTicketWindow) : false;
        }
        var mo = window.MutationObserver ? new MutationObserver(function (list) {
          list.forEach(function (m) {
            Array.prototype.forEach.call(m.addedNodes, function (n) { added.push(n); if (!opened && isWindow(n)) opened = true; });
          });
        }) : null;
        if (mo) mo.observe(document.body, { childList: true, subtree: true });
        setTimeout(function () {
          if (mo) mo.disconnect();
          if (!opened) opened = added.some(isWindow);
          if (!opened) window.location.href = href;
        }, 4000);
      });
    });

    // card slides
    var track = root.querySelector(".kzf-track");
    var dots = Array.prototype.slice.call(root.querySelectorAll(".kzf-dot"));
    if (track && dots.length) {
      var setDot = function () {
        var i = Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
        dots.forEach(function (d, k) { d.setAttribute("aria-current", k === i ? "true" : "false"); });
      };
      track.addEventListener("scroll", function () { window.requestAnimationFrame(setDot); }, { passive: true });
      dots.forEach(function (d, k) {
        d.addEventListener("click", function () { track.scrollTo({ left: k * track.clientWidth, behavior: "smooth" }); });
      });
    }
  }
})();
