/* Kozlov Living Facade: mounts the living house above the home-page slider on phones.
   Safety rules:
   - phones only (max-width 767px); desktop is left exactly as it is
   - ?kzf=off in the address switches the block off for this visit
   - the old slider is hidden only after the block has mounted; any error puts everything back */
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

  function mount(slider, svgText) {
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
  }

  function start(svg) {
    var playTimer = null, endTimer = null;
    var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // the line from the club door to the card frame
    function placeDoor() {
      var w = root.clientWidth || 390;
      var x = (CFG.doorX / CFG.viewW) * w - 16;
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
