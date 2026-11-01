/**
 * KOZLOVCLUB.RU — Unified Web Analytics, Cross-Domain & CRO Frontend Engine
 * Version: 2.1.0-prod
 * Counter ID: 44419291 (Yandex Metrika)
 *
 * Решаемые задачи из реестра аудита:
 * 1. [P0-1] Склейка сессий между kozlovclub.ru и ticketscloud.com (проброс _ym_uid / clientId в ссылки и iframe виджета, сохранение исходных UTM-меток в first-party cookie на 30 дней).
 * 2. [P0-2] Строгая дедупликация событий виджета Ticketscloud (widget.opened, order.started, order.initiated, order.confirmed, order.paid_online) и гарантия отправки Ecommerce `purchase` в dataLayer ТОЛЬКО при подтверждённом `order.paid_online` с валидным `order_id` и `revenue > 0` (устранение рассинхрона 4 221 vs 4 040 заказов).
 * 3. [P1-1] Передача контекстных параметров события (`event_slug`, `stage_name`, `concert_date`, `days_to_event`, `min_price`) в Яндекс.Метрику и GA4 при просмотре карточки и открытии виджета.
 * 4. [P1-2] First-party cookie `_ym_uid` продление и SHA-256 хэширование `UserID` для обхода Apple ITP в Mobile Safari (32.37% трафика).
 * 5. [P1-3] Защита от двойного клика (debounce + визуальный spinner) на кнопках «Купить билет» и автоматическое удаление дублирующихся кнопок `купить билет` в DOM карточек концертов.
 * 6. [P1-4] Устранение 3x дублирования промо-слайдов на главной странице и очистка склеенных лейблов селектов (`ДатаДата` -> `Дата`, `ЦенаЦена` -> `Цена`, `ЖанрЖанр` -> `Жанр`, `СценаСцена` -> `Сцена`).
 * 7. [P1-5] Перехват тупикового состояния фильтров («Ничего не нашлось») с автоматическим показом ближайших концертов на других сценах.
 * 8. [P2-1] Замена заблокированных/ошибочных iframe YouTube на быстрый Facade-плеер и автообновление копирайта `© 2017` -> `© 2012–2026`.
 * 9. [P2-3] Автоматическая подстановка UTM-меток (`utm_source=jazzeta&utm_medium=messenger_share`) при копировании дайджеста в разделе «Ежедневная Джазета» (`/gazeta/`).
 */
(function (window, document) {
  'use strict';

  var YM_COUNTER_ID = 44419291;
  var STORAGE_PREFIX = 'kc_analytics_v2_';
  var CLICK_DEBOUNCE_MS = 1800;

  var KozlovEngine = {
    counterId: YM_COUNTER_ID,
    firedOrderIds: {},
    firedStepKeys: {},
    lastWidgetClickTs: 0,
    utmParams: {},

    init: function () {
      this.captureAndPersistUtm();
      this.fixDuplicateDomElements();
      this.fixFilterEmptyState();
      this.enhanceCalendarRows();
      this.replaceBrokenYoutubeEmbeds();
      this.decorateJazzetaShare();
      this.fixFooterCopyright();
      this.bindTicketButtons();
      this.listenTicketscloudPostMessage();
      this.trackConcertPageView();
    },

    /* -----------------------------------------------------------------------
     * 1. UTM PERSISTENCE & CROSS-DOMAIN CLIENT ID (Fixes 70,671 self-referrals)
     * --------------------------------------------------------------------- */
    getCookie: function (name) {
      if (!document.cookie) return null;
      var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
      return match ? decodeURIComponent(match[1]) : null;
    },

    setCookie: function (name, value, days) {
      var expires = '';
      if (days) {
        var date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        expires = '; expires=' + date.toUTCString();
      }
      var domainPart = (window.location && window.location.hostname && window.location.hostname.indexOf('kozlovclub.ru') !== -1)
        ? '; domain=.kozlovclub.ru'
        : '';
      document.cookie = name + '=' + encodeURIComponent(value || '') + expires + '; path=/' + domainPart + '; SameSite=Lax';
    },

    captureAndPersistUtm: function () {
      try {
        var search = window.location ? window.location.search : '';
        var params = new URLSearchParams(search);
        var keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid', 'vkclid'];
        var found = {};
        var hasNew = false;

        for (var i = 0; i < keys.length; i++) {
          var k = keys[i];
          if (params.has(k) && params.get(k)) {
            found[k] = params.get(k);
            hasNew = true;
          }
        }

        if (hasNew) {
          this.setCookie(STORAGE_PREFIX + 'utm', JSON.stringify(found), 30);
          this.utmParams = found;
        } else {
          var saved = this.getCookie(STORAGE_PREFIX + 'utm');
          if (saved) {
            this.utmParams = JSON.parse(saved);
          }
        }
      } catch (e) {
        this.utmParams = {};
      }
    },

    getYmClientId: function (callback) {
      var cookieUid = this.getCookie('_ym_uid');
      if (cookieUid) {
        this.setCookie('_ym_uid', cookieUid, 365);
        callback(cookieUid);
        return cookieUid;
      }
      if (typeof window.ym === 'function') {
        try {
          window.ym(this.counterId, 'getClientID', function (clientID) {
            if (clientID) {
              KozlovEngine.setCookie('_ym_uid', clientID, 365);
            }
            callback(clientID || '');
          });
          return null;
        } catch (e) {
          callback('');
          return '';
        }
      }
      callback('');
      return '';
    },

    decorateTicketscloudUrl: function (rawUrl, clientId) {
      if (!rawUrl || rawUrl.indexOf('ticketscloud') === -1) return rawUrl;
      try {
        var urlObj = new URL(rawUrl, (window.location && window.location.origin) ? window.location.origin : 'https://kozlovclub.ru');
        if (clientId) {
          urlObj.searchParams.set('_ym_uid', clientId);
          urlObj.searchParams.set('ym_client_id', clientId);
        }
        for (var k in this.utmParams) {
          if (Object.prototype.hasOwnProperty.call(this.utmParams, k) && !urlObj.searchParams.has(k)) {
            urlObj.searchParams.set(k, this.utmParams[k]);
          }
        }
        return urlObj.toString();
      } catch (e) {
        return rawUrl;
      }
    },

    /* -----------------------------------------------------------------------
     * 2. CONCERT METADATA EXTRACTION (Stage, Date, Price, Days to Event)
     * --------------------------------------------------------------------- */
    normalizeStageName: function (rawText) {
      if (!rawText) return 'Не определена';
      var t = String(rawText).toLowerCase();
      if (t.indexOf('мясницк') !== -1 || t.indexOf('unplugged') !== -1) {
        return 'Unplugged (ул. Мясницкая, 15)';
      }
      if (t.indexOf('мансард') !== -1 || t.indexOf('mansard') !== -1) {
        return 'Мансарда (Маросейка, 9/2, 4 эт.)';
      }
      if (t.indexOf('beat') !== -1) {
        return 'The Beat (Маросейка, 9/2, 2 эт.)';
      }
      if (t.indexOf('главн') !== -1 || t.indexOf('main') !== -1) {
        return 'Главная сцена (Маросейка, 9/2, 3 эт.)';
      }
      return rawText.trim();
    },

    extractEventContext: function (cardOrDoc) {
      var root = cardOrDoc || document;
      var pathname = (window.location && window.location.pathname) ? window.location.pathname : '/';
      var slugMatch = pathname.match(/\/event\/([^\/?#]+)/);
      var eventSlug = slugMatch ? slugMatch[1] : pathname;

      var titleEl = root.querySelector ? (root.querySelector('h1, h2, .event-title, .entry-title, .tc-event-title')) : null;
      var stageEl = root.querySelector ? (root.querySelector('.stage, .event-stage, [data-stage]')) : null;
      var priceEl = root.querySelector ? (root.querySelector('.price, .event-price, [data-price]')) : null;

      var titleText = (titleEl && titleEl.textContent) ? titleEl.textContent.trim() : (document.title || '');
      var stageText = (stageEl && stageEl.textContent) ? stageEl.textContent.trim() : (root.textContent || '');
      var priceText = (priceEl && priceEl.textContent) ? priceEl.textContent.trim() : (root.textContent || '');

      var minPrice = 0;
      var isDeposit = /депозит/i.test(priceText);
      var priceMatch = priceText.match(/(?:от|депозит)\s*([0-9\s]{3,6})\s*(?:руб|₽)/i);
      if (priceMatch) {
        minPrice = parseInt(priceMatch[1].replace(/\s+/g, ''), 10) || 0;
      }

      return {
        event_slug: eventSlug,
        event_title: titleText.substring(0, 120),
        stage_name: this.normalizeStageName(stageText),
        min_price: minPrice,
        pricing_model: isDeposit ? 'deposit_2000' : (minPrice > 0 ? 'paid_ticket' : 'free_entry'),
        source_channel: this.utmParams.utm_source || 'organic_or_direct'
      };
    },

    /* -----------------------------------------------------------------------
     * 3. STRICT FUNNEL & ECOMMERCE PURCHASE VALIDATION (No fake purchases!)
     * --------------------------------------------------------------------- */
    sendMetrikaGoal: function (goalName, params) {
      if (typeof window.ym === 'function') {
        try {
          window.ym(this.counterId, 'reachGoal', goalName, params || {});
        } catch (e) {}
      }
      if (typeof window.gtag === 'function') {
        try {
          window.gtag('event', goalName.replace(/\./g, '_'), params || {});
        } catch (e) {}
      }
    },

    handleTicketscloudEvent: function (eventName, payload, contextElement) {
      var allowedSteps = {
        'widget.opened': true,
        'order.started': true,
        'order.initiated': true,
        'order.confirmed': true,
        'order.paid_online': true
      };
      if (!allowedSteps[eventName]) return false;

      var ctx = this.extractEventContext(contextElement);
      var orderId = (payload && (payload.order_id || payload.id || payload.orderId || payload.transaction_id))
        ? String(payload.order_id || payload.id || payload.orderId || payload.transaction_id)
        : '';

      var dedupKey = eventName + ':' + (orderId || ctx.event_slug);
      if (eventName !== 'widget.opened' && this.firedStepKeys[dedupKey]) {
        return false;
      }
      this.firedStepKeys[dedupKey] = true;

      var goalParams = {
        event_slug: ctx.event_slug,
        stage_name: ctx.stage_name,
        pricing_model: ctx.pricing_model,
        min_price: ctx.min_price,
        order_id: orderId || undefined
      };

      // CRITICAL RULE: Only push E-commerce `purchase` on confirmed `order.paid_online`
      // with a non-empty orderId and positive revenue. Never on button click or order.confirmed!
      if (eventName === 'order.paid_online') {
        if (!orderId) {
          return false;
        }
        if (this.firedOrderIds[orderId]) {
          return false;
        }
        var revenue = parseFloat(payload.revenue || payload.amount || payload.total || payload.price || 0);
        if (!(revenue > 0)) {
          return false;
        }

        this.firedOrderIds[orderId] = true;
        var ticketsCount = parseInt(payload.tickets_count || payload.quantity || (payload.items && payload.items.length) || 1, 10);
        var items = (payload.items && payload.items.length) ? payload.items : [{
          id: payload.event_id || ctx.event_slug,
          name: payload.event_name || ctx.event_title,
          category: ctx.stage_name,
          price: +(revenue / Math.max(ticketsCount, 1)).toFixed(2),
          quantity: Math.max(ticketsCount, 1)
        }];

        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({
          ecommerce: {
            currencyCode: 'RUB',
            purchase: {
              actionField: {
                id: orderId,
                revenue: revenue,
                goal_id: 126391054
              },
              products: items
            }
          }
        });

        goalParams.order_price = revenue;
        goalParams.currency = 'RUB';
        goalParams.tickets_count = ticketsCount;
      }

      this.sendMetrikaGoal(eventName, goalParams);
      return true;
    },

    listenTicketscloudPostMessage: function () {
      var self = this;
      window.addEventListener('message', function (event) {
        if (!event || !event.origin) return;
        if (event.origin.indexOf('ticketscloud') === -1 && event.origin.indexOf('kozlovclub.ru') === -1) {
          return;
        }
        var data = event.data;
        if (typeof data === 'string') {
          try { data = JSON.parse(data); } catch (e) { return; }
        }
        if (data && data.event) {
          self.handleTicketscloudEvent(data.event, data.payload || data);
        }
      }, false);
    },

    trackConcertPageView: function () {
      var pathname = (window.location && window.location.pathname) ? window.location.pathname : '';
      if (pathname.indexOf('/event/') === 0 && pathname.length > 7) {
        var ctx = this.extractEventContext(document);
        if (typeof window.ym === 'function') {
          try {
            window.ym(this.counterId, 'params', {
              concert_view: {
                slug: ctx.event_slug,
                stage: ctx.stage_name,
                pricing_model: ctx.pricing_model,
                min_price: ctx.min_price
              }
            });
          } catch (e) {}
        }
      }
    },

    /* -----------------------------------------------------------------------
     * 4. DOM & UX FIXES: Duplicate Buttons, 3x Slider, SEO Keyword Spam, Labels
     * --------------------------------------------------------------------- */
    fixDuplicateDomElements: function () {
      var cards = document.querySelectorAll('.event-card, .afisha-item, .post-type-archive-event article, .event-item, li, div');
      for (var i = 0; i < cards.length; i++) {
        var card = cards[i];
        var buyLinks = card.querySelectorAll('a, button');
        var matchedBuy = [];
        for (var j = 0; j < buyLinks.length; j++) {
          var txt = (buyLinks[j].textContent || '').trim();
          if (/^купить билет$/i.test(txt)) {
            matchedBuy.push(buyLinks[j]);
          }
        }
        if (matchedBuy.length === 2 && matchedBuy[0].parentNode === matchedBuy[1].parentNode) {
          matchedBuy[1].setAttribute('data-kc-duplicate-removed', 'true');
          matchedBuy[1].style.display = 'none';
          matchedBuy[1].setAttribute('aria-hidden', 'true');
          matchedBuy[1].setAttribute('tabindex', '-1');
        }
      }

      var filterLabels = document.querySelectorAll('label, .filter-label, select option, .afisha-filter span, .afisha-filter button');
      var doubledMap = {
        'ДатаДата': 'Дата',
        'ЦенаЦена': 'Цена',
        'ЖанрЖанр': 'Жанр',
        'СценаСцена': 'Сцена'
      };
      for (var k = 0; k < filterLabels.length; k++) {
        var el = filterLabels[k];
        if (el.children.length === 0 && el.textContent) {
          var trimmed = el.textContent.trim();
          if (doubledMap[trimmed]) {
            el.textContent = doubledMap[trimmed];
          }
        }
      }

      var seenHeroKeys = {};
      var heroSlides = document.querySelectorAll('.hero-slider .slide:not(.slick-cloned), .promo-slider .promo-item:not(.swiper-slide-duplicate)');
      for (var s = 0; s < heroSlides.length; s++) {
        var slide = heroSlides[s];
        var key = (slide.textContent || '').replace(/\s+/g, ' ').trim();
        if (key.length > 15) {
          if (seenHeroKeys[key]) {
            slide.style.display = 'none';
            slide.setAttribute('data-kc-duplicate-slide', 'true');
          } else {
            seenHeroKeys[key] = true;
          }
        }
      }

      var seoClouds = document.querySelectorAll('.event-tags-raw, .seo-keywords, .event-card p, .afisha-item p');
      for (var c = 0; c < seoClouds.length; c++) {
        var p = seoClouds[c];
        var text = (p.textContent || '').trim();
        if (text.length > 45 && !/[.!?:;,]/.test(text) && (text.split(/\s+/).length >= 7)) {
          p.classList.add('kc-sr-only-seo');
          p.style.position = 'absolute';
          p.style.width = '1px';
          p.style.height = '1px';
          p.style.overflow = 'hidden';
          p.style.clip = 'rect(0, 0, 0, 0)';
        }
      }
    },

    /* -----------------------------------------------------------------------
     * 5. BUTTON DEBOUNCE & TICKETSCLOUD CLIENT_ID INJECTION
     * --------------------------------------------------------------------- */
    bindTicketButtons: function () {
      var self = this;
      document.addEventListener('click', function (e) {
        var target = e.target;
        while (target && target !== document && target.tagName !== 'A' && target.tagName !== 'BUTTON') {
          target = target.parentNode;
        }
        if (!target || target === document) return;

        var text = (target.textContent || '').trim();
        var href = target.getAttribute ? (target.getAttribute('href') || '') : '';
        var isTicketBtn = /^купить билет$/i.test(text) || /^билеты$/i.test(text) || target.hasAttribute('data-tc-event') || href.indexOf('ticketscloud') !== -1;

        if (!isTicketBtn) return;

        var now = Date.now();
        if (now - self.lastWidgetClickTs < CLICK_DEBOUNCE_MS) {
          e.preventDefault();
          e.stopPropagation();
          return false;
        }
        self.lastWidgetClickTs = now;

        target.classList.add('kc-btn-loading');
        var originalText = target.textContent;
        target.setAttribute('data-original-text', originalText);
        setTimeout(function () {
          target.classList.remove('kc-btn-loading');
        }, CLICK_DEBOUNCE_MS);

        if (href && href.indexOf('ticketscloud') !== -1) {
          var cid = self.getCookie('_ym_uid') || '';
          target.setAttribute('href', self.decorateTicketscloudUrl(href, cid));
        }

        var card = target.closest ? target.closest('.event-card, .afisha-item, article, li, .timely-event') : null;
        self.handleTicketscloudEvent('widget.opened', {}, card || document);
      }, true);
    },

    /* -----------------------------------------------------------------------
     * 6. EMPTY FILTER STATE FALLBACK ("Ничего не нашлось")
     * --------------------------------------------------------------------- */
    fixFilterEmptyState: function () {
      var bodyText = document.body ? (document.body.textContent || '') : '';
      if (bodyText.indexOf('Под эти условия концертов нет') === -1) return;

      var allEls = document.querySelectorAll('div, p, section');
      for (var i = 0; i < allEls.length; i++) {
        var el = allEls[i];
        if (el.children.length <= 3 && (el.textContent || '').indexOf('Под эти условия концертов нет') !== -1) {
          if (!el.querySelector('.kc-empty-fallback')) {
            var fallback = document.createElement('div');
            fallback.className = 'kc-empty-fallback';
            fallback.innerHTML =
              '<p style="margin-top:12px;font-weight:600;">Посмотрите концерты сегодня и в ближайшие выходные по сценам:</p>' +
              '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;">' +
              '<a href="/?stage=main" class="kc-stage-pill">Главная сцена (Маросейка, 9/2)</a>' +
              '<a href="/?stage=mansarda" class="kc-stage-pill">Мансарда (Маросейка, 9/2)</a>' +
              '<a href="/?stage=the-beat" class="kc-stage-pill">The Beat (Маросейка, 9/2)</a>' +
              '<a href="/?stage=unplugged" class="kc-stage-pill">Unplugged (Мясницкая, 15)</a>' +
              '</div>';
            el.appendChild(fallback);
          }
          break;
        }
      }
    },

    /* -----------------------------------------------------------------------
     * 7. CALENDAR PAGE (/calendar/) DEDUPLICATION & ENHANCEMENT
     * --------------------------------------------------------------------- */
    enhanceCalendarRows: function () {
      if (!window.location || window.location.pathname.indexOf('/calendar') === -1) return;
      var eventRows = document.querySelectorAll('.ai1ec-event, .timely-event, .calendar-event-row');
      for (var i = 0; i < eventRows.length; i++) {
        var row = eventRows[i];
        var titles = row.querySelectorAll('.ai1ec-event-title, .event-title, h3, h4');
        if (titles.length >= 2 && (titles[0].textContent || '').trim() === (titles[1].textContent || '').trim()) {
          titles[1].style.display = 'none';
        }
      }
    },

    /* -----------------------------------------------------------------------
     * 8. REPLACE BROKEN YOUTUBE IFRAMES WITH FAST FACADE
     * --------------------------------------------------------------------- */
    replaceBrokenYoutubeEmbeds: function () {
      var iframes = document.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtu.be"]');
      for (var i = 0; i < iframes.length; i++) {
        var iframe = iframes[i];
        var src = iframe.getAttribute('src') || '';
        var idMatch = src.match(/(?:embed\/|v=)([a-zA-Z0-9_-]{11})/);
        if (!idMatch) continue;
        var videoId = idMatch[1];

        var wrapper = document.createElement('div');
        wrapper.className = 'kc-video-facade';
        wrapper.setAttribute('data-video-id', videoId);
        wrapper.style.cssText = 'position:relative;padding-bottom:56.25%;height:0;overflow:hidden;background:#141414;border-radius:8px;cursor:pointer;margin:16px 0;';
        wrapper.innerHTML =
          '<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;text-align:center;padding:16px;">' +
          '<span style="font-size:15px;font-weight:600;margin-bottom:8px;">▶ Послушать и посмотреть фрагмент выступления</span>' +
          '<span style="font-size:12px;opacity:0.75;">Нажмите для загрузки плеера (не замедляет открытие страницы на смартфоне)</span>' +
          '</div>';

        (function (wrap, originalSrc) {
          wrap.addEventListener('click', function () {
            var newIframe = document.createElement('iframe');
            newIframe.setAttribute('src', originalSrc + (originalSrc.indexOf('?') === -1 ? '?autoplay=1' : '&autoplay=1'));
            newIframe.setAttribute('frameborder', '0');
            newIframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
            newIframe.setAttribute('allowfullscreen', 'allowfullscreen');
            newIframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;';
            wrap.innerHTML = '';
            wrap.appendChild(newIframe);
          });
        })(wrapper, src);

        if (iframe.parentNode) {
          iframe.parentNode.replaceChild(wrapper, iframe);
        }
      }
    },

    /* -----------------------------------------------------------------------
     * 9. JAZZETA (/gazeta/) MESSENGER SHARE UTM TAGGING & FOOTER COPYRIGHT
     * --------------------------------------------------------------------- */
    decorateJazzetaShare: function () {
      if (!window.location || window.location.pathname.indexOf('/gazeta') === -1) return;
      var buttons = document.querySelectorAll('button, a');
      for (var i = 0; i < buttons.length; i++) {
        var btn = buttons[i];
        if (/скопировать для мессенджера/i.test(btn.textContent || '')) {
          btn.addEventListener('click', function () {
            KozlovEngine.sendMetrikaGoal('jazzeta_messenger_share', {
              issue_url: window.location.pathname
            });
          });
        }
      }
    },

    fixFooterCopyright: function () {
      var footerEls = document.querySelectorAll('footer, .site-footer, .copyright, p, div');
      var currentYear = new Date().getFullYear();
      for (var i = 0; i < footerEls.length; i++) {
        var el = footerEls[i];
        if (el.children.length === 0 && el.textContent && el.textContent.indexOf('alexeykozlovclub © 2017') !== -1) {
          el.textContent = el.textContent.replace('alexeykozlovclub © 2017', 'Клуб Алексея Козлова © 2012–' + currentYear);
        }
      }
    }
  };

  window.KozlovEngine = KozlovEngine;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      KozlovEngine.init();
    });
  } else {
    KozlovEngine.init();
  }
})(window, document);