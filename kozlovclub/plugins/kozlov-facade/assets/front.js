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

  var slider;
  try {
    if (!CFG || !root) { release(); return; }
    if (/[?&]kzf=off\b/.test(window.location.search)) { release(); return; }
    if (!window.matchMedia || !window.matchMedia("(max-width: 767px)").matches) { release(); return; }
    slider = document.querySelector(".content_home .slider-wrapper");
    if (!slider || !slider.parentNode || !window.fetch) { release(); return; }
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
  whenCss("--kzf-front-css", 4000, function () {
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
      whenCss("--kzf-hero-css", 4000, function () {
        try {
          var heroBox = root.querySelector(".kzf-hero");
          heroBox.innerHTML = svgText;
          var svg = heroBox.querySelector("svg");
          if (!svg) throw new Error("no svg");
          start(svg);
        } catch (e) { giveUp(e); }
      }, giveUp);
    }).catch(giveUp);
  }, giveUp);

  function start(svg) {
    var endTimer = null, dimTimer = null;
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

    // the song: windows light up one by one and their people play; afterwards the lights stay on
    function sing() {
      Array.prototype.forEach.call(svg.querySelectorAll(".kzf-glow.kzf-on, .kzf-who.kzf-on"), function (el) {
        el.classList.remove("kzf-on", "kzf-solo");
      });
      root.classList.remove("kzf-dim", "kzf-lit", "kzf-playing");
      void root.offsetWidth;
      root.classList.add("kzf-playing");
      endTimer = setTimeout(function () {
        root.classList.add("kzf-lit");
        root.classList.remove("kzf-playing");
      }, 7800);
    }
    // every new song starts from dark windows; lit ones fade out first
    function play() {
      clearTimeout(endTimer);
      clearTimeout(dimTimer);
      var anyLit = root.classList.contains("kzf-lit") || root.classList.contains("kzf-playing") ||
        !!svg.querySelector(".kzf-glow.kzf-on");
      if (!anyLit || reduced) { sing(); return; }
      root.classList.add("kzf-dim");
      dimTimer = setTimeout(sing, 380);
    }

    root.classList.add("kzf-run");
    setTimeout(play, reduced ? 0 : 3700);

    // a tapped window lights up and its people play
    function solo(w) {
      var who = svg.querySelector('.kzf-who[data-w="' + w + '"]');
      var glow = svg.querySelector('.kzf-glow[data-w="' + w + '"]');
      if (!who) return;
      if (glow) glow.classList.add("kzf-on");
      who.classList.add("kzf-on");
      who.classList.remove("kzf-solo");
      void who.getBoundingClientRect();
      who.classList.add("kzf-solo");
      clearTimeout(who._kzfT);
      who._kzfT = setTimeout(function () { who.classList.remove("kzf-solo"); }, 3400);
    }
    function bindTap(el, fn) {
      el.addEventListener("click", fn);
      el.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); fn(); }
      });
    }
    Array.prototype.forEach.call(svg.querySelectorAll(".kzf-hit"), function (h) {
      if (h.classList.contains("kzf-saxhit")) bindTap(h, play);
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

    // no work while the tab is hidden
    document.addEventListener("visibilitychange", function () {
      root.classList.toggle("kzf-paused", document.hidden);
      try { if (document.hidden) svg.pauseAnimations(); else svg.unpauseAnimations(); } catch (e) { /* older engines */ }
    });

    // a ticket window usually sits fixed over the middle of the screen
    function overlayAtCenter() {
      var el = document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2);
      while (el && el !== document.body && el !== document.documentElement) {
        if (root.contains(el)) return false;
        if (window.getComputedStyle(el).position === "fixed") return true;
        el = el.parentElement;
      }
      return false;
    }

    // tickets: Ticketscloud opens its own window; if nothing opens, go to the concert page
    Array.prototype.forEach.call(root.querySelectorAll(".kzf-buy[tc-event]"), function (btn) {
      btn.addEventListener("click", function () {
        var href = btn.getAttribute("data-href");
        if (!href) return;
        var before = document.querySelectorAll('iframe[src*="ticketscloud"]').length;
        setTimeout(function () {
          var frames = document.querySelectorAll('iframe[src*="ticketscloud"]');
          var shown = false;
          Array.prototype.forEach.call(frames, function (f) {
            var r = f.getBoundingClientRect();
            if (r.width > 40 && r.height > 40) shown = true;
          });
          if (!shown) shown = overlayAtCenter();
          if (!shown && frames.length <= before) window.location.href = href;
        }, 1800);
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
