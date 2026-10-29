/* Kozlov Living Facade: mounts the living house above the home-page slider on phones.
   Safety rules:
   - phones only (max-width 767px); desktop is left exactly as it is
   - ?kzf=off in the address switches the block off for this visit
   - nothing replaces the slider unless there is at least one card and both stylesheets have loaded
   - the old slider is hidden only after the block has mounted; any error puts everything back
   - a ticket button that Ticketscloud does not pick up falls back to the concert page */
(function () {
  "use strict";

  var CFG = window.KZF_CONFIG;
  var root = document.getElementById("kzf-root");
  if (!CFG || !root) return;

  function giveUp() {
    try {
      document.documentElement.classList.remove("kzf-on");
      root.hidden = true;
      root.classList.remove("kzf-run", "kzf-playing", "kzf-lit");
    } catch (e) { /* nothing else to undo */ }
  }

  try {
    if (/[?&]kzf=off\b/.test(window.location.search)) return;
    if (!window.matchMedia || !window.matchMedia("(max-width: 767px)").matches) return;
    var slider = document.querySelector(".content_home .slider-wrapper");
    if (!slider || !slider.parentNode) return;
    if (!window.fetch) return;
    if (!root.querySelector(".kzf-slide")) return;          // no cards: keep the old slider
    if (!cssReady("--kzf-front-css")) return;               // block stylesheet missing: keep the old slider

    window.fetch(CFG.heroUrl, { credentials: "same-origin" })
      .then(function (r) { if (!r.ok) throw new Error("hero " + r.status); return r.text(); })
      .then(function (svgText) {
        if (svgText.indexOf("<svg") === -1) throw new Error("hero markup");
        mount(slider, svgText);
      })
      .catch(function (e) { giveUp(); if (window.console) console.warn("[kzf]", e); });
  } catch (e) {
    giveUp();
    if (window.console) console.warn("[kzf]", e);
  }

  function cssReady(marker) {
    try {
      return window.getComputedStyle(root).getPropertyValue(marker).trim() === "1";
    } catch (e) { return false; }
  }

  // wait for the drawing's stylesheet (up to 4 s), otherwise keep the old slider
  function whenHeroCss(done, fail) {
    var t0 = Date.now();
    (function check() {
      if (cssReady("--kzf-hero-css")) return done();
      if (Date.now() - t0 > 4000) return fail(new Error("hero.css not loaded"));
      setTimeout(check, 120);
    })();
  }

  function mount(slider, svgText) {
    whenHeroCss(function () {
      try {
        var heroBox = root.querySelector(".kzf-hero");
        heroBox.innerHTML = svgText;
        var svg = heroBox.querySelector("svg");
        if (!svg) throw new Error("no svg");

        slider.parentNode.insertBefore(root, slider);
        root.hidden = false;
        document.documentElement.classList.add("kzf-on");
        start(svg);
      } catch (e) {
        giveUp();
        if (window.console) console.warn("[kzf]", e);
      }
    }, function (e) {
      giveUp();
      if (window.console) console.warn("[kzf]", e);
    });
  }

  function start(svg) {
    var playTimer = null, endTimer = null;
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

    function play() {
      clearTimeout(endTimer);
      var wasLit = root.classList.contains("kzf-lit");
      root.classList.remove("kzf-playing");
      void root.offsetWidth;
      root.classList.add("kzf-playing");
      endTimer = setTimeout(function () {
        root.classList.add("kzf-lit");
        root.classList.remove("kzf-playing");
      }, wasLit ? 6200 : 7800);
    }

    root.classList.add("kzf-run");
    playTimer = setTimeout(play, reduced ? 0 : 3700);

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
