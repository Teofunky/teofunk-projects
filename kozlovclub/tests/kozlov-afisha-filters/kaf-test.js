// Проверка панели Kozlov Afisha Filters в jsdom на модели главной страницы kozlovclub.ru
// node kaf-test.js <путь к assets/kaf-app.js>
const fs = require('fs');
const { JSDOM } = require('jsdom');

const BUNDLE = fs.readFileSync(process.argv[2], 'utf8');
const SNAP = JSON.parse(fs.readFileSync(__dirname + '/kaf-index-snapshot.json', 'utf8'));
const MSK = 3 * 3600 * 1000;
const clubTs = (y, mo, d, h, mi) => Date.UTC(y, mo - 1, d, h, mi) - MSK; // время клуба -> настоящий момент

// индекс как у Kozlov Smart Search 1.0.4: кортеж из 13 полей, длительность в минутах в поле 12
function makeIndex(opts = {}) {
  const ev = SNAP.ev.map((e) => {
    const t = e.slice(0, 9);
    while (t.length < 12) t.push(0);
    t[9] = -1; t[10] = 0; t[11] = 0; // -1: у концерта нет текста анонса
    t.push(/brunch/.test(e[5]) ? 180 : e[2] === '23:59' ? 240 : 120);
    return t;
  });
  return Object.assign({ v: 1, built: 1000, off: 10800, cut: 0, evBase: '/event/', imgBase: '/wp-content/uploads/', genres: SNAP.genres, ev, pages: [], now: 0, kaf: 'on' }, opts);
}

const MONTHS = { '01': 'Январе', '10': 'Октябре', '11': 'Ноябре', '12': 'Декабре' };
const MABBR = ['Янв', 'Фев', 'Мар', 'Апр', 'Мая', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
const WDAY = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
// разметка даты как у темы: в плитке «Окт | 14:00 | Суббота», в списке день недели строкой ниже
function dtHtml(e, view) {
  const [y, mo, d] = e[1].split('-').map(Number), wd = WDAY[new Date(Date.UTC(y, mo - 1, d)).getUTCDay()], ab = MABBR[mo - 1];
  return view === 'line'
    ? '<div class="datetime"><div class="cell cell_1">' + d + '</div><div class="cell cell_2"><div class="row row_1">' + ab + ' | ' + e[2] + '</div><div class="row row_2">' + wd + '</div></div></div>'
    : '<div class="datetime"><div class="wrap"><div class="cell cell_1">' + d + '</div><div class="cell cell_2"><div class="row row_1">' + ab + ' | ' + e[2] + ' | ' + wd + '</div><div class="row row_2"> ' + e[4] + ' </div></div></div></div>';
}
function pageHtml(index, extra = {}) {
  let html = '<!doctype html><html><head></head><body><div class="inner content_inner events_wrap view_cell"><div class="wrap">' +
    (extra.noSlider ? '' : '<div class="slider-wrapper">SLIDER</div>') +
    '<h1 class="home_h1">Джаз-клуб</h1><div class="viewtabs" id="events"><a href="#" class="tab_item tab_calendar active calendar-toggler"></a>' +
    '<a href="?view=line" class="tab_item tab_line "></a><a href="?view=cell" class="tab_item tab_cell active"></a></div>';
  let month = '';
  index.ev.forEach((e, i) => {
    const m = e[1].slice(5, 7);
    if (m !== month) {
      if (month) html += '</div>';
      month = m;
      html += '<div class="event_month">Концерты в ' + MONTHS[m] + '</div><div class="events view_cell">';
    }
    const style = i === 3 ? ' style="color: red; display: flex !important"' : i === 4 ? ' style="opacity: 0.9"' : '';
    html += '<div class="event"' + style + '><a class="title" href="/event/' + e[5] + '/">' + e[0] + '</a>' + dtHtml(e, extra.view) + '</div>';
  });
  html += '</div></div></div>';
  if (!extra.noConfig) html += '<script type="application/json" id="kaf-config">' + JSON.stringify({ v: '1.0.0', index: '/wp-json/kozlov-search/v1/index', fonts: '/wp-content/plugins/kozlov-afisha-filters/assets/fonts/', preview: 0 }) + '</script>';
  return html + '</body></html>';
}

function makePage(o = {}) {
  const index = o.index || makeIndex();
  const dom = new JSDOM(o.html || pageHtml(index, o.extra), { url: 'https://kozlovclub.ru/' + (o.search || ''), runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  const clock = { now: o.now || clubTs(2026, 10, 17, 18, 0) };
  w.Date.now = () => clock.now;
  const calls = [];
  const ctl = { handler: o.fetch || (() => ({ status: 200, body: Object.assign({}, index, { now: Math.floor(clock.now / 1000) }) })) };
  w.fetch = (url, init) => {
    calls.push({ url, init });
    return new Promise((res, rej) => {
      const run = () => {
        let r;
        try { r = ctl.handler(url, init, calls.length); } catch (e) { rej(e); return; }
        if (r instanceof Error) { rej(r); return; }
        const send = () => res({ ok: r.status >= 200 && r.status < 300, status: r.status, json: () => Promise.resolve(JSON.parse(JSON.stringify(r.body))) });
        if (r.delay) setTimeout(send, r.delay); else send();
      };
      if (o.fetchDelay) setTimeout(run, o.fetchDelay); else run();
    });
  };
  const intervals = [];
  w.setInterval = (fn, ms) => { intervals.push(fn); return intervals.length; };
  w.clearInterval = () => {};
  if (o.mobile) w.matchMedia = () => ({ matches: true, addEventListener() {} });
  if (o.before) o.before(w);
  w.eval(BUNDLE);
  if (o.twice) w.eval(BUNDLE);
  const d = w.document;
  const api = {
    w, d, index, clock, calls, ctl, intervals,
    host: () => d.getElementById('kaf-filters'),
    root: () => d.getElementById('kaf-filters') && d.getElementById('kaf-filters').shadowRoot,
    lroot: () => d.getElementById('kaf-layer') && d.getElementById('kaf-layer').shadowRoot,
    flag: () => d.getElementById('kaf-config') && d.getElementById('kaf-config').getAttribute('data-kaf'),
    cards: () => Array.from(d.querySelectorAll('.events > .event')),
    visible: () => api.cards().filter((c) => c.style.getPropertyValue('display') !== 'none' || c.style.getPropertyPriority('display') !== 'important'),
    visibleSlugs: () => api.visible().map((c) => c.querySelector('a.title').getAttribute('href').split('/')[2]),
    styles: () => api.cards().map((c) => c.getAttribute('style')),
    q: (sel) => api.root().querySelector(sel),
    lq: (sel) => api.lroot().querySelector(sel),
    click: (el) => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true, composed: true })),
    key: (el, key, shift) => el.dispatchEvent(new w.KeyboardEvent('keydown', { key, shiftKey: !!shift, bubbles: true, composed: true })),
    search: () => new w.URLSearchParams(w.location.search),
  };
  return api;
}

const headText = (p) => { const h = p.q('.kaf-h'); const sm = h.querySelector('small'); return h.textContent.slice(0, h.textContent.length - (sm ? sm.textContent.length : 0)); };
const tick = (ms = 0) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
function ok(cond, name) { if (cond) pass++; else { fail++; console.log('FAIL', name); } }
function eq(a, b, name) { const s1 = JSON.stringify(a), s2 = JSON.stringify(b); if (s1 === s2) pass++; else { fail++; console.log('FAIL', name, '\n  got     ', s1.slice(0, 300), '\n  expected', s2.slice(0, 300)); } }

// независимый расчёт ожиданий
function evInfo(e) {
  const [y, mo, d] = e[1].split('-').map(Number), [h, mi] = e[2].split(':').map(Number);
  const nominal = Date.UTC(y, mo - 1, d, h, mi);
  const start = e[2] === '23:59' ? Date.UTC(y, mo - 1, d + 1) : nominal;
  return { date: e[1], start, end: nominal + e[12] * 60000, slug: e[5], st: e[3], g: e[8], price: e[4] };
}
const wall = (p) => p.clock.now + MSK;
const keyOf = (ms) => new Date(ms).toISOString().slice(0, 10);

(async () => {
  // 1. Запуск без фильтров: панель под слайдером, афиша не тронута
  {
    const p = makePage();
    const before = p.styles();
    await tick(5);
    ok(p.host() && p.host().previousElementSibling.className === 'slider-wrapper', 'панель стоит сразу после слайдера');
    eq(p.flag(), 'ready', 'флаг ready');
    eq(p.visible().length, 290, 'без фильтров видны все 290 карточек');
    eq(p.styles(), before, 'без фильтров стили карточек не изменены');
    ok(p.d.querySelector('.viewtabs').getAttribute('style') === null, 'переключатель вида темы на месте');
    ok(p.q('.kaf-top').hidden, 'итог скрыт без фильтров');
    const today = p.index.ev.map(evInfo).filter((e) => e.date === '2026-10-17' && e.end > wall(p)).length;
    eq(p.q('[data-act="when"][data-v="today"] .kaf-n').textContent, String(today), 'счётчик на кнопке Сегодня');
    ok(p.calls[0].url.indexOf('/wp-json/kozlov-search/v1/index?kss_t=') === 0, 'адрес индекса с kss_t');
    ok(!p.calls[0].init.headers['X-WP-Nonce'], 'гостю nonce не передаётся');
    ok(!p.d.getElementById('kaf-layer'), 'слой окон создаётся только при открытии');
  }

  // 2. Сегодня, сброс, точное восстановление стилей
  {
    const p = makePage();
    await tick(5);
    const before = p.styles();
    p.click(p.q('[data-act="when"][data-v="today"]'));
    const exp = p.index.ev.map(evInfo).filter((e) => e.date === '2026-10-17' && e.end > wall(p)).map((e) => e.slug);
    eq(p.visibleSlugs(), exp, 'Сегодня: только сегодняшние незакончившиеся');
    ok(!exp.some((s) => /brunch/.test(s)), 'бранч 14:00-17:00 в 18:00 уже не показан');
    ok(exp.some((s) => /santana/.test(s) || /orquesta/.test(s)), 'ночной концерт 23:59 в сегодняшнем списке');
    eq(headText(p), 'Концерты сегодня, 17 октября', 'заголовок по дате');
    eq(p.q('.kaf-h small').textContent, String(exp.length), 'число в заголовке');
    ok(Array.from(p.d.querySelectorAll('.event_month')).every((m) => m.style.display === 'none'), 'заголовки месяцев скрыты при фильтре по дате');
    eq(p.d.querySelector('.viewtabs').style.display, 'none', 'переключатель темы скрыт при фильтре');
    await tick(5);
    eq(p.search().get('kaf_when'), 'today', 'выбор записан в адрес');
    const ln = p.q('.kaf-v[data-view="line"]').getAttribute('href');
    ok(/view=line/.test(ln) && /kaf_when=today/.test(ln), 'ссылка «списком» сохраняет фильтры');
    eq(p.q('.kaf-v[data-view="cell"]').getAttribute('aria-current'), 'true', 'текущий вид плиткой');
    eq(p.q('[data-act="when"][data-v="today"]').getAttribute('aria-pressed'), 'true', 'aria-pressed у Сегодня');
    p.click(p.q('.kaf-reset'));
    eq(p.styles(), before, 'после сброса стили карточек как были, включая свои inline-стили темы');
    ok(Array.from(p.d.querySelectorAll('.event_month, .events, .viewtabs')).every((m) => m.getAttribute('style') === null), 'после сброса месяцы, блоки и вкладки без следов');
    await tick(5);
    ok(!/kaf_/.test(p.w.location.search), 'после сброса адрес без kaf_');
    ok(p.q('.kaf-top').hidden, 'итог скрыт после сброса');
  }

  // 3. Жанр в окне: выбор, подпись на кнопке, Esc и возврат фокуса
  {
    const p = makePage();
    await tick(5);
    const btn = p.q('.kaf-dd[data-v="genre"]');
    btn.focus();
    p.click(btn);
    ok(p.lroot() && !p.lq('.kaf-panel').hidden, 'окно жанров открылось');
    eq(btn.getAttribute('aria-expanded'), 'true', 'aria-expanded true');
    eq(p.lq('#kaf-pt').textContent, 'Жанр', 'заголовок окна');
    await tick(5);
    p.click(p.lq('.kaf-g[data-v="jazz"]'));
    const exp = p.index.ev.map(evInfo).filter((e) => e.end > wall(p) && e.g.indexOf('jazz') >= 0).map((e) => e.slug);
    eq(p.visibleSlugs(), exp, 'Жанр Джаз');
    eq(btn.querySelector('.kaf-l').textContent, 'Джаз', 'значение на кнопке');
    ok(btn.classList.contains('kaf-on'), 'кнопка выделена');
    eq(p.lq('.kaf-g[data-v="jazz"]').getAttribute('aria-pressed'), 'true', 'жанр отмечен в окне');
    ok(/Показать \d+/.test(p.lq('.kaf-main').textContent), 'кнопка «Показать N»');
    p.click(p.lq('.kaf-g[data-v="funk"]'));
    eq(btn.querySelector('.kaf-l').textContent, 'Джаз +1', 'два жанра: «Джаз +1»');
    eq(btn.getAttribute('aria-label'), 'Жанр: Джаз, Фанк', 'полное значение в aria-label');
    eq(p.q('.kaf-found b').textContent, String(p.visible().length), 'Найдено N без фильтра дат');
    ok(Array.from(p.d.querySelectorAll('.event_month')).some((m) => m.style.display !== 'none'), 'заголовки месяцев остаются без фильтра дат');
    p.key(p.lq('.kaf-panel'), 'Escape');
    ok(p.lq('.kaf-panel').hidden, 'Esc закрывает окно');
    ok(p.root().activeElement === btn, 'фокус вернулся на кнопку Жанр');
    eq(btn.getAttribute('aria-expanded'), 'false', 'aria-expanded false');
    await tick(5);
    eq(p.search().get('kaf_genre'), 'jazz,funk', 'жанры в адресе');
  }

  // 4. Сцена, цена
  {
    const p = makePage();
    await tick(5);
    p.click(p.q('.kaf-dd[data-v="stage"]'));
    p.click(p.lq('.kaf-opt[data-v="u"]'));
    const ex = p.index.ev.map(evInfo).filter((e) => e.end > wall(p) && e.st.indexOf('u') >= 0).map((e) => e.slug);
    eq(p.visibleSlugs(), ex, 'Сцена на Мясницкой');
    eq(p.q('.kaf-dd[data-v="stage"] .kaf-l').textContent, 'Мясницкая', 'короткое имя сцены на кнопке');
    ok(!!p.q('.kaf-dd[data-v="stage"] .kaf-bdot'), 'цветная точка сцены на кнопке');
    ok(/Мясницкая, 15/.test(p.lq('.kaf-opt[data-v="u"]').textContent), 'адрес сцены в окне');
    p.click(p.lq('[data-act="close"].kaf-main'));
    ok(p.lq('.kaf-panel').hidden, 'Показать закрывает окно');
    await tick(5);
    eq(p.search().get('kaf_stage'), 'myasnitskaya', 'сцена в адресе');
    p.click(p.q('.kaf-dd[data-v="price"]'));
    p.click(p.lq('.kaf-opt[data-act="budget"][data-v="1500"]'));
    const amt = (s) => { const m = String(s).match(/\d[\d\s]*\d|\d/); return m ? parseInt(m[0].replace(/\s/g, ''), 10) : null; };
    const ex2 = p.index.ev.map(evInfo).filter((e) => e.end > wall(p) && e.st.indexOf('u') >= 0 && e.price && amt(e.price) !== null && amt(e.price) <= 1500 && !/свобод/i.test(e.price)).map((e) => e.slug);
    eq(p.visibleSlugs(), ex2, 'Цена до 1 500 вместе со сценой');
    eq(p.q('.kaf-dd[data-v="price"] .kaf-l').textContent, 'до 1 500 ₽', 'цена на кнопке');
    ok(p.lq('.kaf-opt[data-act="fmt"][data-v="deposit"]').disabled, 'депозит при бюджете до 1 500 недоступен: таких концертов нет');
    p.click(p.lq('.kaf-opt[data-act="fmt"][data-v="ticket"]'));
    eq(p.q('.kaf-dd[data-v="price"] .kaf-l').textContent, 'до 1 500 ₽ +1', 'цена и формат: «до 1 500 ₽ +1»');
    eq(p.q('.kaf-dd[data-v="price"]').getAttribute('aria-label'), 'Цена: до 1 500 ₽, Билет', 'полное значение цены');
    p.click(p.lq('.kaf-pf [data-act="clear"]'));
    eq(p.q('.kaf-dd[data-v="price"] .kaf-l').textContent, 'Цена', 'Сбросить в окне цены');
    eq(p.visibleSlugs(), ex, 'после сброса цены остаётся сцена');
  }

  // 5. Календарь: период
  {
    const p = makePage();
    await tick(5);
    p.click(p.q('.kaf-dd[data-v="date"]'));
    eq(p.lq('.kaf-ch strong').textContent, 'Октябрь 2026', 'календарь открыт на октябре');
    ok(p.lq('.kaf-d[data-v="2026-10-16"]').disabled, 'прошедший день недоступен');
    p.click(p.lq('.kaf-d[data-v="2026-10-20"]'));
    p.click(p.lq('.kaf-d[data-v="2026-10-25"]'));
    const ex = p.index.ev.map(evInfo).filter((e) => e.date >= '2026-10-20' && e.date <= '2026-10-25').map((e) => e.slug);
    eq(p.visibleSlugs(), ex, 'период 20-25 октября');
    eq(headText(p), 'Концерты 20–25 октября', 'заголовок периода');
    eq(p.q('.kaf-dd[data-v="date"] .kaf-l').textContent, '20–25 окт', 'период на кнопке');
    ok(p.lq('.kaf-d[data-v="2026-10-22"]').classList.contains('kaf-in'), 'дни внутри периода подсвечены');
    p.click(p.lq('.kaf-nav[data-v="1"]'));
    eq(p.lq('.kaf-ch strong').textContent, 'Ноябрь 2026', 'следующий месяц');
    ok(p.lq('.kaf-d[data-v="2026-11-04"]').classList.contains('kaf-we'), '4 ноября окрашен как праздник');
    ok(/День народного единства/.test(p.lq('.kaf-d[data-v="2026-11-04"]').getAttribute('aria-label')), 'праздник в подписи дня');
    await tick(5);
    eq([p.search().get('kaf_from'), p.search().get('kaf_to')], ['2026-10-20', '2026-10-25'], 'период в адресе');
  }

  // 6. Поиск: опечатка, площадка, адрес
  {
    const p = makePage();
    await tick(5);
    const input = p.q('.kaf-q');
    const type = async (v) => { input.value = v; input.dispatchEvent(new p.w.Event('input', { bubbles: true })); await tick(160); };
    await type('Жаваронкова');
    ok(p.visible().length >= 2 && p.visible().every((c) => /ЖАВОРОНКОВ/.test(c.textContent)), 'опечатка «Жаваронкова» находит Жаворонкову');
    await type('Мясницкая');
    const u = p.index.ev.map(evInfo).filter((e) => e.end > wall(p) && e.st.indexOf('u') >= 0).length;
    eq(p.visible().length, u, '«Мясницкая» находит все концерты сцены на Мясницкой');
    await type('мансарда фанк');
    ok(p.visible().length > 0 && p.visible().length < 40, 'сцена + жанр словами');
    eq(p.q('.kaf-vals').textContent, '«мансарда фанк»', 'запрос в строке значений');
    await type('qqqqzzzz');
    ok(!p.q('.kaf-empty').hidden && p.visible().length === 0, 'пустой результат с подсказкой');
    await type('');
    eq(p.visible().length, 290, 'пустой запрос возвращает афишу');
    ok(p.q('.kaf-empty').hidden, 'подсказка скрыта');
  }

  // 7. Фильтры из адреса: до загрузки афиша скрыта и есть «Подбираем», потом выбор применён
  {
    const p = makePage({ search: '?kaf_when=weekend&kaf_stage=main,beat&kaf_genre=nope', fetchDelay: 40 });
    await tick(1);
    ok(!p.q('.kaf-wait').hidden, 'до загрузки «Подбираем концерты»');
    ok(Array.from(p.d.querySelectorAll('.events')).every((e) => e.style.display === 'none'), 'до загрузки афиша скрыта, без мигания');
    await tick(80);
    ok(p.q('.kaf-wait').hidden, '«Подбираем» скрыто после загрузки');
    const wk = ['2026-10-17', '2026-10-18'];
    const ex = p.index.ev.map(evInfo).filter((e) => e.end > wall(p) && wk.indexOf(e.date) >= 0 && /[gb]/.test(e.st)).map((e) => e.slug);
    eq(p.visibleSlugs(), ex, 'Выходные + две сцены из адреса');
    ok(!p.q('.kaf-dd[data-v="genre"]').classList.contains('kaf-on'), 'неизвестный жанр из адреса не выбран');
    eq(headText(p), 'Концерты в выходные, 17–18 октября', 'заголовок выходных');
  }

  // 8. 403 при загрузке: панель убирается, афиша восстановлена
  {
    const p = makePage({ search: '?kaf_when=today', fetch: () => ({ status: 403, body: { code: 'kss_disabled' } }) });
    const before = p.styles();
    await tick(10);
    ok(!p.host(), '403: панели нет');
    eq(p.flag(), 'off', '403: флаг off');
    eq(p.styles(), before, '403: карточки не тронуты');
    ok(Array.from(p.d.querySelectorAll('.event_month, .events, .viewtabs')).every((m) => m.getAttribute('style') === null), '403: скрытые до загрузки блоки возвращены');
    eq(p.calls.length, 1, '403: без повтора');
  }

  // 9. Сетевая ошибка: один повтор через секунду
  {
    const p = makePage({ fetch: (u, i, n) => (n === 1 ? new Error('network') : { status: 200, body: Object.assign(makeIndex(), { now: Math.floor(clubTs(2026, 10, 17, 18, 0) / 1000) }) }) });
    await tick(1600);
    eq(p.flag(), 'ready', 'после повтора панель работает');
    eq(p.calls.length, 2, 'ровно один повтор');
  }
  {
    const p = makePage({ fetch: () => new Error('network') });
    await tick(1600);
    ok(!p.host() && p.flag() === 'off', 'две сетевые ошибки: панель убрана');
  }

  // 10. Индекс не совпадает с афишей: при частичном расхождении фильтры отключаются, при полном панель убирается
  {
    const idx = makeIndex();
    const bad = makeIndex();
    bad.ev = bad.ev.map((e, i) => (i % 3 ? Object.assign([], e, { 5: e[5] + '-x' }) : e));
    const p = makePage({ index: idx, search: '?kaf_when=today', fetch: () => ({ status: 200, body: bad }) });
    const before = p.styles();
    await tick(10);
    eq(p.flag(), 'degraded', 'совпала треть: фильтры отключены');
    eq(p.calls.length, 2, 'перед отключением индекс перезапрошен мимо кэша браузера');
    eq(p.calls[1].init.cache, 'reload', 'второй запрос мимо кэша');
    eq(p.visible().length, 290, 'видна вся афиша');
    eq(p.styles(), before, 'стили карточек как без плагина');
    ok(Array.from(p.d.querySelectorAll('.event_month, .events, .viewtabs')).every((m) => m.getAttribute('style') === null), 'месяцы и вкладки темы на месте');
    ok(!p.q('.kaf-note').hidden && p.q('.kaf-top').hidden, 'сообщение о расхождении вместо итога');
    ok(Array.from(p.root().querySelectorAll('.kaf-c, .kaf-q')).every((b) => b.disabled), 'кнопки и поиск отключены');
    const allBad = makeIndex();
    allBad.ev = allBad.ev.map((e) => Object.assign([], e, { 5: e[5] + '-x' }));
    const p1 = makePage({ fetch: () => ({ status: 200, body: allBad }) });
    await tick(10);
    ok(!p1.host() && p1.flag() === 'off', 'не совпало ничего: панель убрана');
    const bad2 = makeIndex({ v: 2 });
    const p2 = makePage({ fetch: () => ({ status: 200, body: bad2 }) });
    await tick(1600);
    ok(!p2.host(), 'чужая версия индекса: панель убрана');
  }

  // 11. Фоновая проверка: отказ сайта убирает панель и возвращает афишу; новая сборка применяется
  {
    const p = makePage();
    await tick(5);
    const before = p.styles();
    p.click(p.q('[data-act="when"][data-v="tomorrow"]'));
    ok(p.visible().length < 290, 'фильтр Завтра применён');
    eq(p.intervals.length, 1, 'фоновая проверка запущена');
    p.intervals[0]();
    eq(p.calls.length, 1, 'раньше 5 минут индекс не перезапрашивается');
    p.clock.now += 6 * 60 * 1000;
    p.ctl.handler = () => ({ status: 403, body: {} });
    p.intervals[0]();
    await tick(10);
    eq(p.calls[1].init.cache, 'reload', 'фоновый запрос мимо кэша браузера');
    ok(!p.host() && !p.d.getElementById('kaf-layer'), 'отказ в фоне: панель и слой убраны');
    eq(p.styles(), before, 'отказ в фоне: стили карточек как были');
  }
  {
    const p = makePage();
    await tick(5);
    p.click(p.q('[data-act="when"][data-v="today"]'));
    const n0 = p.visible().length;
    p.clock.now += 6 * 60 * 1000;
    const idx2 = makeIndex({ built: 2000 });
    idx2.ev = idx2.ev.filter((e) => e[1] !== '2026-10-17' || e[2] !== '22:00');
    p.ctl.handler = () => ({ status: 200, body: Object.assign({}, idx2, { now: Math.floor(p.clock.now / 1000) }) });
    p.intervals[0]();
    await tick(10);
    // концерт 22:00 ещё не начался, но из индекса пропал, а на странице остался: данные разошлись
    eq(p.flag(), 'degraded', 'снятый, но оставшийся на странице концерт: фильтры отключены');
    eq(p.visible().length, 290, 'при расхождении видна вся афиша, подходящие концерты не теряются');
    p.clock.now += 6 * 60 * 1000;
    p.ctl.handler = () => ({ status: 200, body: Object.assign(makeIndex({ built: 3000 }), { now: Math.floor(p.clock.now / 1000) }) });
    p.intervals[0]();
    await tick(10);
    eq(p.flag(), 'ready', 'данные снова совпали: фильтры вернулись');
    eq(p.visible().length, n0, 'фильтр «Сегодня» снова применён');
    ok(p.q('.kaf-note').hidden && !p.root().querySelector('.kaf-c').disabled, 'сообщение убрано, кнопки работают');
    // индекс собран после начала бранча 14:00: его отсутствие ожидаемо, фильтры работают
    p.clock.now += 6 * 60 * 1000;
    const idx3 = makeIndex({ built: Math.floor(Date.UTC(2026, 9, 17, 15, 0) / 1000) });
    idx3.ev = idx3.ev.filter((e) => !(e[1] === '2026-10-17' && e[2] === '14:00'));
    p.ctl.handler = () => ({ status: 200, body: Object.assign({}, idx3, { now: Math.floor(p.clock.now / 1000) }) });
    p.intervals[0]();
    await tick(10);
    eq(p.flag(), 'ready', 'начавшийся до сборки индекса концерт не считается расхождением');
    p.clock.now += 6 * 60 * 1000;
    p.ctl.handler = () => new Error('net');
    p.intervals[0]();
    await tick(10);
    ok(!!p.host(), 'временный сбой в фоне не убирает панель');
  }

  // 12. Ночной концерт: после полуночи он «идёт сейчас» и остаётся в «Сегодня»
  {
    const p = makePage({ now: clubTs(2026, 10, 18, 1, 0) });
    await tick(5);
    p.click(p.q('[data-act="when"][data-v="today"]'));
    const slugs = p.visibleSlugs();
    const night = p.index.ev.find((e) => e[1] === '2026-10-17' && e[2] === '23:59')[5];
    ok(slugs.indexOf(night) >= 0, 'в 01:00 ночной концерт 17-го ещё в «Сегодня»');
    ok(slugs.every((s) => { const e = p.index.ev.find((x) => x[5] === s); return e[1] === '2026-10-18' || e[5] === night; }), 'остальные из 18 октября');
    eq(headText(p), 'Концерты сегодня, 18 октября', 'заголовок нового дня');
  }

  // 13. Телефон: шторка, затемнение, блокировка прокрутки и точный возврат
  {
    const p = makePage({ mobile: true, before: (w) => { w.document.documentElement.style.overflow = 'scroll'; } });
    await tick(5);
    const btn = p.q('.kaf-dd[data-v="stage"]');
    p.click(btn);
    ok(!p.lq('.kaf-scrim').hidden, 'затемнение на телефоне');
    eq(p.d.documentElement.style.overflow, 'hidden', 'прокрутка страницы заблокирована');
    p.click(p.lq('.kaf-scrim'));
    ok(p.lq('.kaf-panel').hidden, 'тап по затемнению закрывает');
    eq(p.d.documentElement.style.overflow, 'scroll', 'прежний overflow возвращён');
    p.click(btn);
    p.click(p.lq('.kaf-opt[data-v="g"]'));
    p.click(p.lq('.kaf-opt[data-v="m"]'));
    p.click(p.lq('[data-act="close"].kaf-main'));
    eq(p.q('.kaf-vals').textContent, 'Главная сцена, Мансарда', 'строка значений для телефона');
    eq(p.q('.kaf-dd[data-v="stage"] .kaf-k').textContent, 'Сцена', 'на кнопке остаётся название фильтра');
  }

  // 14. Клавиатура: ловушка фокуса в окне
  {
    const p = makePage({ before: (w) => { w.Element.prototype.getClientRects = function () { return [1]; }; } });
    await tick(5);
    p.click(p.q('.kaf-dd[data-v="price"]'));
    await tick(5);
    const list = Array.from(p.lq('.kaf-panel').querySelectorAll('button:not([disabled])'));
    list[list.length - 1].focus();
    p.key(list[list.length - 1], 'Tab');
    ok(p.lroot().activeElement === list[0], 'Tab с последней кнопки переходит на первую');
    p.key(list[0], 'Tab', true);
    ok(p.lroot().activeElement === list[list.length - 1], 'Shift+Tab с первой переходит на последнюю');
    // клик вне окна на компьютере закрывает его
    p.d.body.dispatchEvent(new p.w.MouseEvent('click', { bubbles: true, composed: true }));
    ok(p.lq('.kaf-panel').hidden, 'клик вне окна закрывает');
  }

  // 15. Нет слайдера или конфигурации: скрипт ничего не делает; повторный запуск безвреден
  {
    const p = makePage({ extra: { noSlider: true } });
    await tick(5);
    ok(!p.host() && p.calls.length === 0, 'нет слайдера: ни панели, ни запроса');
    const p2 = makePage({ extra: { noConfig: true } });
    await tick(5);
    ok(!p2.host() && p2.calls.length === 0, 'нет конфигурации: ничего');
    const p3 = makePage({ twice: true });
    await tick(5);
    eq(p3.d.querySelectorAll('#kaf-filters').length, 1, 'двойной запуск: одна панель');
    eq(p3.calls.length, 1, 'двойной запуск: один запрос');
  }

  // 16. Карточка, удалённая темой, не считается; ошибка внутри панели убирает её
  {
    const p = makePage();
    await tick(5);
    const first = p.cards().find((c) => /2026-10-17/.test('') || c.querySelector('.cell_1').textContent === '17');
    first.remove();
    p.click(p.q('[data-act="when"][data-v="today"]'));
    eq(p.q('.kaf-h small').textContent, String(p.visible().length), 'удалённая карточка не входит в число');
    const before = p.styles();
    p.q('.kaf-head').remove(); // ломаем разметку панели: следующее применение бросит ошибку
    p.click(p.q('[data-act="when"][data-v="tomorrow"]'));
    ok(!p.host(), 'ошибка внутри панели: панель убрана');
    ok(p.visible().length === p.cards().length, 'ошибка внутри панели: вся афиша видна');
    ok(p.cards().every((c, i) => c.getAttribute('style') === null || /color: red|opacity/.test(c.getAttribute('style'))), 'ошибка внутри панели: следов в стилях нет');
  }

  // 17. Вошедший администратор: nonce уходит в заголовке
  {
    const html = pageHtml(makeIndex()).replace('"preview":0', '"preview":1,"nonce":"abc123"');
    const p = makePage({ html });
    await tick(5);
    eq(p.calls[0].init.headers['X-WP-Nonce'], 'abc123', 'nonce в заголовке');
    eq(p.calls[0].init.credentials, 'same-origin', 'cookies только своему сайту');
  }


  // 18. Доработки после проверки на сайте: порядок жанров, повтор мимо кэша, ленивые картинки, чужие правки стиля
  {
    const p = makePage();
    await tick(5);
    let docScroll = 0;
    p.d.addEventListener('scroll', () => { docScroll++; });
    p.click(p.q('.kaf-dd[data-v="stage"]'));
    p.click(p.lq('.kaf-opt[data-v="u"]'));
    p.key(p.lq('.kaf-panel'), 'Escape');
    await tick(100);
    ok(docScroll > 0, 'после фильтра прокрутка отправлена на document для a3 Lazy Load');
    p.click(p.q('.kaf-dd[data-v="genre"]'));
    const btns = Array.from(p.lq('.kaf-gch').children);
    const firstOff = btns.findIndex((b) => b.disabled);
    ok(firstOff > 0 && btns.slice(firstOff).every((b) => b.disabled), 'недоступные жанры стоят в конце');
  }
  {
    const p = makePage({ fetch: (u, i, n) => (n === 1 ? { status: 200, body: '<html>challenge</html>' } : { status: 200, body: Object.assign(makeIndex(), { now: Math.floor(clubTs(2026, 10, 17, 18, 0) / 1000) }) }) });
    await tick(1600);
    eq(p.flag(), 'ready', 'ответ защиты хостинга вместо индекса: повтор помогает');
    eq([p.calls[0].init.cache, p.calls[1].init.cache], ['default', 'reload'], 'повтор идёт мимо кэша браузера');
  }
  {
    const p = makePage();
    await tick(5);
    p.click(p.q('[data-act="when"][data-v="tomorrow"]'));
    const c0 = p.cards()[0];
    eq(c0.style.display, 'none', 'сегодняшняя карточка скрыта');
    c0.style.display = 'block'; // скрипт темы сам показал карточку
    p.click(p.q('.kaf-reset'));
    eq(c0.getAttribute('style'), null, 'после чужой правки стиля карточка вернулась без следов');
    p.click(p.q('[data-act="when"][data-v="today"]'));
    eq(headText(p), 'Концерты сегодня, 17 октября', 'заголовок с датой');
    eq(p.q('.kaf-h .kaf-nw').textContent, '17 октября' + p.q('.kaf-h small').textContent, 'дата и число без переноса');
    eq(p.q('.kaf-reset').textContent, 'Сбросить фильтры', 'текст кнопки сброса');
  }


  // 19. Воспроизведения из отчёта проверки 1.0.0
  // 19.1 Три завтрашних концерта, в индексе два: подходящий концерт не прячется
  {
    const base = makeIndex();
    const tmr = base.ev.filter((e) => e[1] === '2026-10-18');
    ok(tmr.length >= 3, 'в снимке есть несколько концертов 18 октября');
    const lost = tmr[tmr.length - 1];
    const part = makeIndex();
    part.ev = part.ev.filter((e) => !(e[5] === lost[5] && e[1] === lost[1] && e[2] === lost[2]));
    const p = makePage({ index: base, search: '?kaf_when=tomorrow', fetch: () => ({ status: 200, body: part }) });
    await tick(20);
    eq(p.flag(), 'degraded', 'одного завтрашнего концерта нет в индексе: фильтры отключены');
    ok(p.visibleSlugs().indexOf(lost[5]) >= 0, 'пропавший из индекса концерт виден');
    eq(p.visible().length, 290, 'видна вся афиша');
  }
  {
    const p = makePage();
    await tick(5);
    p.click(p.q('[data-act="when"][data-v="tomorrow"]'));
    const n0 = p.visible().length;
    p.clock.now += 6 * 60 * 1000;
    const one = makeIndex({ built: 5000 });
    let kept = false;
    one.ev = one.ev.filter((e) => { if (e[1] !== '2026-10-18') return true; if (!kept) { kept = true; return true; } return false; });
    p.ctl.handler = () => ({ status: 200, body: Object.assign({}, one, { now: Math.floor(p.clock.now / 1000) }) });
    p.intervals[0]();
    await tick(10);
    ok(n0 > 1 && p.flag() === 'degraded' && p.visible().length === 290, 'фоновый ответ с одним концертом из нескольких не прячет остальные');
  }

  // 19.2 Повторяющееся событие 17 октября и 17 ноября, год в карточке не пишется
  {
    const base = makeIndex();
    const mk = (d, t) => ['JAM REPEAT', d, t, 'g', '1000 руб.', 'jam-repeat', '', 1, ['jam'], 0, 0, 0, 180];
    const page = makeIndex();
    page.ev = base.ev.slice();
    page.ev.splice(page.ev.findIndex((e) => e[1] === '2026-10-18'), 0, mk('2026-10-17', '20:00'));
    page.ev.splice(page.ev.findIndex((e) => e[1] === '2026-11-18'), 0, mk('2026-11-17', '20:00'));
    page.ev.push(mk('2027-01-05', '20:00'));
    const fresh = makeIndex({ built: Math.floor(Date.UTC(2026, 9, 17, 20, 30) / 1000) });
    fresh.ev = page.ev.filter((e) => !(e[5] === 'jam-repeat' && e[1] === '2026-10-17'));
    const p = makePage({ index: page, now: Date.UTC(2026, 9, 17, 20, 30), search: '?kaf_from=2026-11-17', fetch: () => ({ status: 200, body: fresh }) });
    await tick(20);
    eq(p.flag(), 'ready', 'октябрьский повтор начался до сборки индекса: расхождения нет');
    const jam = p.visible().filter((c) => /jam-repeat/.test(c.querySelector('a.title').getAttribute('href')));
    eq(jam.length, 1, '17 ноября виден один повтор');
    ok(jam[0] && jam[0].parentNode.previousElementSibling.textContent === 'Концерты в Ноябре', 'это карточка из ноябрьского блока, а не октябрьская');
    p.click(p.q('.kaf-reset'));
    p.click(p.q('.kaf-dd[data-v="date"]'));
    for (let i = 0; i < 3; i++) p.click(p.lq('.kaf-nav[data-v="1"]'));
    eq(p.lq('.kaf-ch strong').textContent, 'Январь 2027', 'календарь листается до января 2027');
    p.click(p.lq('.kaf-d[data-v="2027-01-05"]'));
    eq(p.visibleSlugs(), ['jam-repeat'], 'карточка 5 января 2027 получила правильный год');
  }

  // 19.3 Выключение панели в настройках доходит до открытой вкладки
  {
    const p = makePage();
    await tick(5);
    const before = p.styles();
    p.click(p.q('[data-act="when"][data-v="today"]'));
    p.clock.now += 6 * 60 * 1000;
    p.ctl.handler = () => ({ status: 200, body: Object.assign(makeIndex(), { kaf: 'off', now: Math.floor(p.clock.now / 1000) }) });
    p.intervals[0]();
    await tick(10);
    ok(!p.host() && p.flag() === 'off', 'режим «Выключена»: открытая вкладка убирает панель');
    eq(p.styles(), before, 'афиша вернулась без следов');
  }
  {
    const noField = makeIndex(); delete noField.kaf;
    const p = makePage({ fetch: () => ({ status: 200, body: noField }) });
    await tick(10);
    ok(!p.host(), 'нет поля kaf (плагин выключен): панели нет');
    const p2 = makePage({ fetch: () => ({ status: 200, body: makeIndex({ kaf: 'preview' }) }) });
    await tick(10);
    ok(!p2.host(), 'режим «Проверка», страница гостя из кэша: панели нет');
    const html = pageHtml(makeIndex()).replace('"preview":0', '"preview":1,"nonce":"abc"');
    const p3 = makePage({ html, fetch: () => ({ status: 200, body: makeIndex({ kaf: 'preview' }) }) });
    await tick(10);
    eq(p3.flag(), 'ready', 'режим «Проверка» у администратора: панель работает');
  }

  // 19.4 Переход через полночь без новой сборки индекса
  {
    const p = makePage({ now: clubTs(2026, 10, 17, 23, 58) });
    await tick(5);
    p.click(p.q('[data-act="when"][data-v="today"]'));
    eq(headText(p), 'Концерты сегодня, 17 октября', 'в 23:58 сегодня 17 октября');
    p.clock.now = clubTs(2026, 10, 18, 0, 1);
    p.intervals[0]();
    eq(headText(p), 'Концерты сегодня, 18 октября', 'в 00:01 без нового индекса уже 18 октября');
    const ex = p.index.ev.filter((e) => e[1] === '2026-10-18').length;
    ok(p.visible().length >= ex, 'видны концерты 18 октября');
  }

  // 19.5 Параметры адреса со служебными именами объекта
  {
    const p = makePage({ search: '?kaf_entry=constructor,toString&kaf_stage=constructor&kaf_genre=constructor&kaf_when=__proto__' });
    await tick(10);
    eq(p.visible().length, 290, 'служебные имена в адресе не создают фильтр');
    ok(p.q('.kaf-top').hidden && !p.q('.kaf-dd[data-v="price"]').classList.contains('kaf-on'), 'панель без выбранных фильтров');
  }

  // 19.6 Вид «списком»: день недели строкой ниже
  {
    const p = makePage({ extra: { view: 'line' } });
    await tick(5);
    eq(p.flag(), 'ready', 'разметка вида «списком» распознана');
    p.click(p.q('[data-act="when"][data-v="weekend"]'));
    ok(p.visible().length > 0 && p.visible().length < 290, 'фильтр в виде «списком» работает');
  }

  // 19.7 Пересчёт раз в минуту ничего не трогает, если ничего не изменилось
  {
    const p = makePage();
    await tick(5);
    p.click(p.q('[data-act="when"][data-v="weekend"]'));
    await tick(150); // ждём отложенные запись адреса и сигнал прокрутки от клика
    let writes = 0;
    const orig = p.w.history.replaceState.bind(p.w.history);
    p.w.history.replaceState = (...a) => { writes++; return orig(...a); };
    let scrolls = 0;
    p.d.addEventListener('scroll', () => { scrolls++; });
    const sr = p.q('.kaf-sr');
    let srChanges = 0;
    new p.w.MutationObserver(() => { srChanges++; }).observe(sr, { childList: true, characterData: true, subtree: true });
    p.intervals[0]();
    await tick(100);
    eq([writes, scrolls, srChanges], [0, 0, 0], 'пересчёт без изменений: адрес, прокрутка и объявления не трогаются');
  }


  // 20. Поиск по текстам анонсов (музыканты): как в Kozlov Smart Search, без опечаток
  {
    const idx = makeIndex({ texts: 2 });
    const A = idx.ev[10], B = idx.ev[20];
    A[9] = 0; B[9] = 1;
    // словарь отсортирован, документы: разницы между номерами слов
    const textsBody = { v: 1, built: idx.built, vocab: 'ившин ившиным колтрейн контрабас', docs: [[0, 3], [1, 1]] };
    const html = pageHtml(idx).replace('"preview":0', '"preview":0,"texts":"/wp-json/kozlov-search/v1/texts"');
    let textsDelay = 0;
    const p = makePage({ index: idx, html, fetch: (u) => (/\/texts/.test(u) ? { status: 200, body: textsBody, delay: textsDelay } : { status: 200, body: Object.assign({}, idx, { now: Math.floor(clubTs(2026, 10, 17, 18, 0) / 1000) }) }) });
    await tick(5);
    eq(p.calls.filter((c) => /\/texts/.test(c.url)).length, 0, 'тексты не грузятся, пока поиском не пользуются');
    const input = p.q('.kaf-q');
    textsDelay = 300;
    input.dispatchEvent(new p.w.FocusEvent('focus'));
    const type = async (v, ms = 160) => { input.value = v; input.dispatchEvent(new p.w.Event('input', { bubbles: true })); await tick(ms); };
    await type('Ившин', 140);
    ok(p.calls.some((c) => /\/texts\?kss_t=/.test(c.url)), 'тексты запрошены при фокусе на поиске, тем же адресом, что у Kozlov Smart Search');
    ok(!p.q('.kaf-wait').hidden && p.q('.kaf-wait').textContent === 'Ищем в анонсах…' && p.q('.kaf-empty').hidden, 'пока тексты грузятся: «Ищем в анонсах…» вместо «Ничего не нашлось»');
    await tick(300);
    eq(p.visibleSlugs().sort(), [A[5], B[5]].sort(), '«Ившин» находит концерты, где он упомянут в анонсе');
    ok(p.q('.kaf-wait').hidden, 'подсказка ожидания скрыта');
    eq(p.flag(), 'ready', 'панель работает');
    eq(p.d.getElementById('kaf-config').getAttribute('data-kaf-texts'), '4', 'словарь текстов подключён');
    await type('Ившина');
    eq(p.visibleSlugs().sort(), [A[5], B[5]].sort(), '«Ившина» (другое окончание) тоже находит');
    await type('колтрейн');
    eq(p.visibleSlugs(), [B[5]], 'слово только из одного анонса');
    await type('контрабас ившин');
    eq(p.visibleSlugs(), [A[5]], 'два слова из анонса: нужны оба');
    await type('');
    eq(p.visible().length, 290, 'пустой запрос: вся афиша');
    // новая сборка индекса: прежние номера текстов не годятся, тексты перезапрашиваются
    await type('Ившин');
    const before = p.calls.filter((c) => /\/texts/.test(c.url)).length;
    p.clock.now += 6 * 60 * 1000;
    const idx2 = Object.assign(makeIndex({ texts: 2, built: 9000 }), { now: Math.floor(p.clock.now / 1000) });
    idx2.ev[10][9] = 1; idx2.ev[20][9] = 0; // номера текстов поменялись местами
    const texts2 = { v: 1, built: 9000, vocab: 'ившин колтрейн', docs: [[1], [0]] };
    textsDelay = 0;
    p.ctl.handler = (u) => (/\/texts/.test(u) ? { status: 200, body: texts2 } : { status: 200, body: idx2 });
    p.intervals[0]();
    await tick(30);
    ok(p.calls.filter((c) => /\/texts/.test(c.url)).length > before, 'после новой сборки индекса тексты перезапрошены');
    eq(p.visibleSlugs(), [A[5]], 'поиск идёт по новым текстам');
  }
  {
    // тексты из другой сборки: один повтор мимо кэша, потом поиск только по названиям
    const idx = makeIndex({ texts: 2 });
    idx.ev[10][9] = 0;
    const html = pageHtml(idx).replace('"preview":0', '"preview":0,"texts":"/wp-json/kozlov-search/v1/texts"');
    const p = makePage({ index: idx, html, fetch: (u) => (/\/texts/.test(u) ? { status: 200, body: { v: 1, built: 777, vocab: 'ившин', docs: [[0]] } } : { status: 200, body: Object.assign({}, idx, { now: Math.floor(clubTs(2026, 10, 17, 18, 0) / 1000) }) }) });
    await tick(5);
    const input = p.q('.kaf-q');
    input.value = 'Ившин'; input.dispatchEvent(new p.w.Event('input', { bubbles: true }));
    await tick(200);
    const tc = p.calls.filter((c) => /\/texts/.test(c.url));
    eq(tc.map((c) => c.init.cache), ['default', 'reload'], 'тексты другой сборки: один повтор мимо кэша');
    ok(p.visible().length === 0 && !p.q('.kaf-empty').hidden && p.q('.kaf-wait').hidden, 'тексты не подошли: по названиям ничего, показано «Ничего не нашлось»');
    eq(p.flag(), 'ready', 'панель при этом работает');
  }
  {
    // поиск по анонсам выключен в Kozlov Smart Search: адреса текстов в настройке нет
    const p = makePage();
    await tick(5);
    const input = p.q('.kaf-q');
    input.dispatchEvent(new p.w.FocusEvent('focus'));
    input.value = 'Ившин'; input.dispatchEvent(new p.w.Event('input', { bubbles: true }));
    await tick(200);
    eq(p.calls.filter((c) => /\/texts/.test(c.url)).length, 0, 'без поиска по анонсам тексты не запрашиваются');
    ok(p.q('.kaf-wait').hidden, 'и подсказка ожидания не показывается');
  }


  // 21. Телефон: компактная афиша по 2 в ряд и переключатель вида; компьютер не меняется
  {
    const mqObj = { matches: true, addEventListener(t, fn) { mqObj.fn = fn; } };
    const p = makePage({ before: (w) => { w.matchMedia = () => mqObj; } });
    const ew = () => p.d.querySelector('.events_wrap');
    await tick(5);
    ok(ew().classList.contains('kaf-compact'), 'телефон: по умолчанию 2 в ряд');
    ok(!!p.d.getElementById('kaf-compact-css') && /kaf-compact/.test(p.d.getElementById('kaf-compact-css').textContent), 'стили компактного вида подключены');
    eq(p.d.querySelector('.viewtabs').style.display, 'none', 'телефон: значки темы над афишей скрыты');
    eq(p.q('.kaf-lb[data-v="compact"]').getAttribute('aria-pressed'), 'true', 'кнопка «2 в ряд» нажата');
    let scrolls = 0;
    p.d.addEventListener('scroll', () => { scrolls++; });
    p.click(p.q('.kaf-lb[data-v="large"]'));
    await tick(100);
    ok(!ew().classList.contains('kaf-compact'), '«Крупно»: класс снят');
    eq(p.w.localStorage.getItem('kaf_layout'), 'large', 'выбор запомнен в браузере');
    eq([p.q('.kaf-lb[data-v="compact"]').getAttribute('aria-pressed'), p.q('.kaf-lb[data-v="large"]').getAttribute('aria-pressed')], ['false', 'true'], 'кнопки показывают выбор');
    ok(scrolls > 0, 'после смены вида картинкам дан сигнал прокрутки');
    eq(p.visible().length, 290, 'смена вида не прячет карточки');
    p.click(p.q('.kaf-lb[data-v="compact"]'));
    ok(ew().classList.contains('kaf-compact'), 'снова 2 в ряд');
    // поворот экрана: шире 719 px телефонный вид выключается
    mqObj.matches = false; mqObj.fn();
    ok(!ew().classList.contains('kaf-compact') && p.d.querySelector('.viewtabs').getAttribute('style') === null, 'стал широкий экран: обычный вид и значки темы');
    mqObj.matches = true; mqObj.fn();
    ok(ew().classList.contains('kaf-compact'), 'снова узкий: 2 в ряд');
    // отказ сайта: всё возвращается к теме
    p.clock.now += 6 * 60 * 1000;
    p.ctl.handler = () => ({ status: 403, body: {} });
    p.intervals[0]();
    await tick(10);
    ok(!ew().classList.contains('kaf-compact') && !p.d.getElementById('kaf-compact-css'), 'панель убрана: класс и стили компактного вида удалены');
    eq(p.d.querySelector('.viewtabs').getAttribute('style'), null, 'значки темы вернулись');
  }
  {
    const p = makePage({ before: (w) => { w.matchMedia = () => ({ matches: true, addEventListener() {} }); w.localStorage.setItem('kaf_layout', 'large'); } });
    await tick(5);
    ok(!p.d.querySelector('.events_wrap').classList.contains('kaf-compact'), 'запомненный выбор «крупно» действует при следующем заходе');
  }
  {
    const p = makePage({ before: (w) => { w.matchMedia = () => ({ matches: true, addEventListener() {} }); Object.defineProperty(w, 'localStorage', { get() { throw new Error('denied'); } }); } });
    await tick(5);
    ok(p.d.querySelector('.events_wrap').classList.contains('kaf-compact'), 'без доступа к памяти браузера: 2 в ряд');
    p.click(p.q('.kaf-lb[data-v="large"]'));
    ok(!p.d.querySelector('.events_wrap').classList.contains('kaf-compact') && p.flag() === 'ready', 'и переключение работает без ошибки');
  }
  {
    const p = makePage();
    await tick(5);
    ok(!p.d.querySelector('.events_wrap').classList.contains('kaf-compact'), 'компьютер: класс компактного вида не ставится');
    eq(p.d.querySelector('.viewtabs').getAttribute('style'), null, 'компьютер: значки темы на месте');
  }

  console.log(`\nkaf: ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
