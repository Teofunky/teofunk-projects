/*! Kozlov Smart Search: загрузчик. Ничего не рисует и ничего не меняет на странице: подгружает поиск, когда он понадобится. */
(function () {
  'use strict';
  try {
    var d = document, w = window, el = d.getElementById('kss-config');
    if (!el || el.getAttribute('data-kss-loader')) return;
    el.setAttribute('data-kss-loader', '1');
    if (!w.Element || !Element.prototype.attachShadow || !w.fetch || !w.MutationObserver) return;
    var cfg = JSON.parse(el.textContent || '{}');
    if (!cfg || typeof cfg.app !== 'string' || !cfg.app || !cfg.index) return;
    // поиск подключается только к строке поиска темы; на страницах без неё скрипт ничего не делает
    var bar = d.querySelector('.search_block');
    if (!bar || !bar.querySelector('form') || !bar.querySelector('input.searchinput, input[name="s"]')) return;

    var done = false, mo = null;
    var load = function () {
      if (done) return;
      done = true;
      try { if (mo) mo.disconnect(); } catch (e) { /* не критично */ }
      var s = d.createElement('script');
      s.src = cfg.app; s.async = true; s.id = 'kss-app-js';
      (d.head || d.documentElement).appendChild(s);
    };

    if (bar.classList.contains('active')) { load(); return; }
    mo = new w.MutationObserver(load);
    mo.observe(bar, { attributes: true, attributeFilter: ['class'] });
    var btns = d.querySelectorAll('.search-btn'), i;
    for (i = 0; i < btns.length; i++) {
      btns[i].addEventListener('mouseenter', load, { once: true, passive: true });
      btns[i].addEventListener('touchstart', load, { once: true, passive: true });
    }
    // в простое после загрузки страницы скрипт поиска подгружается заранее, чтобы первое открытие было мгновенным
    var idle = function () {
      if (w.requestIdleCallback) w.requestIdleCallback(load, { timeout: 4000 });
      else w.setTimeout(load, 2500);
    };
    if (d.readyState === 'complete') idle();
    else w.addEventListener('load', idle, { once: true });
  } catch (e) { /* при ошибке остаётся штатный поиск темы */ }
})();
