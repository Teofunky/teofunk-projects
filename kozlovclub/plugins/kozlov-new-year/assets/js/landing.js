/* Kozlov New Year: поведение новогодней страницы. Без jQuery и без глобальных переменных. */
(function () {
'use strict';
var doc = document;
function byId(id) { return doc.getElementById(id); }
function clamp(v, a, b) { a = a === undefined ? 0 : a; b = b === undefined ? 1 : b; return Math.max(a, Math.min(b, v)); }
function plural(n, one, few, many) { return n % 10 === 1 && n % 100 !== 11 ? one : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? few : many; }
var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

/* ---------- Фильтр программы ---------- */
var tabs = [].slice.call(doc.querySelectorAll('.filter-tabs [data-period]'));
var stageSelect = byId('stage'), countEl = byId('result-count'), emptyEl = byId('empty'), resetBtn = byId('reset');
var selectedPeriod = 'all';
var suffix = countEl ? (countEl.getAttribute('data-suffix') || '') : '';

function filterEvents() {
	var stage = stageSelect ? stageSelect.value : 'all', count = 0;
	[].forEach.call(doc.querySelectorAll('.event-card'), function (card) {
		var visible = (selectedPeriod === 'all' || card.dataset.period === selectedPeriod) && (stage === 'all' || card.dataset.stage === stage);
		card.hidden = !visible;
		if (visible) count++;
	});
	if (countEl) countEl.textContent = count + ' ' + plural(count, 'событие', 'события', 'событий') + (suffix ? ' · ' + suffix : '');
	if (emptyEl) emptyEl.hidden = count > 0;
	requestAnimationFrame(alignCards);
}
function setPeriod(period) {
	selectedPeriod = period;
	tabs.forEach(function (b) { var on = b.dataset.period === period; b.classList.toggle('active', on); b.setAttribute('aria-pressed', String(on)); });
}
tabs.forEach(function (tab) { tab.addEventListener('click', function () { setPeriod(tab.dataset.period); filterEvents(); }); });
if (stageSelect) stageSelect.addEventListener('change', filterEvents);
if (resetBtn) resetBtn.addEventListener('click', function () { if (stageSelect) stageSelect.value = 'all'; setPeriod('all'); filterEvents(); });

/* ---------- Обратный отсчёт (момент задан явно по Москве, не зависит от часового пояса гостя) ---------- */
var countdown = doc.querySelector('.hero-countdown');
var newYearAt = countdown ? Date.parse(countdown.getAttribute('data-target')) : NaN;
function countdownParts(now) {
	var total = Math.max(0, Math.floor((newYearAt - now) / 60000));
	return { days: Math.floor(total / 1440), hours: Math.floor(total / 60) % 24, minutes: total % 60, finished: now >= newYearAt };
}
function setText(id, v) { var el = byId(id); if (el) el.textContent = v; }
function pad(n) { return (n < 10 ? '0' : '') + n; }
function updateCountdown() {
	if (!countdown || isNaN(newYearAt)) return;
	var t = countdownParts(Date.now());
	setText('count-days', pad(t.days)); setText('count-hours', pad(t.hours)); setText('count-minutes', pad(t.minutes));
	setText('label-days', plural(t.days, 'день', 'дня', 'дней'));
	setText('label-hours', plural(t.hours, 'час', 'часа', 'часов'));
	setText('label-minutes', plural(t.minutes, 'минута', 'минуты', 'минут'));
	setText('countdown-title', t.finished ? countdown.getAttribute('data-done') : countdown.getAttribute('data-title'));
}
updateCountdown(); setInterval(updateCountdown, 1000);
doc.addEventListener('visibilitychange', function () { if (!doc.hidden) updateCountdown(); });

/* ---------- Первый экран: сдвиг, масштаб и поворот без искажений ---------- */
var track = doc.querySelector('.hero-track'), scene = doc.querySelector('.hero-sticky'), motionButton = byId('motion');
var paused = reduceMotion.matches;
function updateButton() {
	if (!motionButton || !scene) return;
	motionButton.setAttribute('aria-pressed', String(paused));
	motionButton.setAttribute('aria-label', paused ? 'Включить анимацию' : 'Остановить анимацию');
	motionButton.innerHTML = paused ? '▶ <span>Анимация</span>' : 'Ⅱ <span>Анимация</span>';
	scene.classList.toggle('motion-paused', paused);
}
function onScroll() {
	if (!track || !scene) return;
	var r = track.getBoundingClientRect(), range = track.offsetHeight - scene.offsetHeight;
	if (paused && !reduceMotion.matches) return;
	var raw = reduceMotion.matches ? 0 : clamp(-r.top / Math.max(1, range));
	var p = raw * raw * (3 - 2 * raw), s = clamp((p - 0.25) / 0.6), mobile = innerWidth <= 600, st = scene.style;
	st.setProperty('--intro-opacity', String(1 - clamp(p * 2.5)));
	st.setProperty('--intro-visibility', p > 0.4 ? 'hidden' : 'visible');
	st.setProperty('--intro-y', (-p * 45) + 'px');
	st.setProperty('--tree-opacity', String(1 - clamp(p * 1.8)));
	st.setProperty('--tree-y', (-p * (mobile ? 85 : 120)) + 'px');
	st.setProperty('--toy-x', (p * innerWidth * (mobile ? 0.09 : 0.025)) + 'px');
	st.setProperty('--toy-y', (p * (mobile ? 165 : 10)) + 'px');
	st.setProperty('--toy-scale', String(1 + p * (mobile ? 0.16 : 0.24)));
	st.setProperty('--toy-tilt', (-3 + p * 8 + Math.sin(p * Math.PI) * 1.2) + 'deg');
	st.setProperty('--second-opacity', String(s));
	st.setProperty('--second-visibility', s > 0.02 ? 'visible' : 'hidden');
	st.setProperty('--second-y', ((1 - s) * 35) + 'px');
}
if (motionButton) motionButton.addEventListener('click', function () { paused = !paused; updateButton(); onScroll(); ambientScroll(); });
function onMotionPref() { paused = reduceMotion.matches; updateButton(); onScroll(); ambientScroll(); }
if (reduceMotion.addEventListener) reduceMotion.addEventListener('change', onMotionPref); else if (reduceMotion.addListener) reduceMotion.addListener(onMotionPref);

/* ---------- Выравнивание строк в парах карточек ---------- */
var alignSelectors = ['.event-meta', 'h3', '.event-subtitle', '.event-desc', '.event-detail summary'];
function alignCards() {
	var cards = [].slice.call(doc.querySelectorAll('.event-card:not([hidden])'));
	cards.forEach(function (c) { alignSelectors.forEach(function (s) { var el = c.querySelector(s); if (el) el.style.minHeight = ''; }); });
	for (var i = 0; i < cards.length; i += 2) {
		var pair = cards.slice(i, i + 2);
		alignSelectors.forEach(function (s) {
			var els = pair.map(function (c) { return c.querySelector(s); }).filter(Boolean);
			var h = Math.max.apply(null, els.map(function (el) { return el.getBoundingClientRect().height; }).concat(0));
			els.forEach(function (el) { el.style.minHeight = h + 'px'; });
		});
	}
}

/* ---------- Мягкое движение фото и тарелок ---------- */
var photoEls = [].slice.call(doc.querySelectorAll('.event-image,.set-photo')), table = doc.querySelector('.dinner-table');
function ambientScroll() {
	var still = reduceMotion.matches || paused;
	photoEls.forEach(function (el) {
		var r = el.getBoundingClientRect();
		if (r.bottom < 0 || r.top > innerHeight) return;
		var p = clamp((innerHeight - r.top) / (innerHeight + r.height));
		el.style.setProperty('--photo-scale', still ? '1' : String(1 + 0.12 * p));
		el.style.setProperty('--photo-y', still ? '0px' : ((p - 0.5) * 12) + 'px');
	});
	if (table) {
		var r = table.getBoundingClientRect(), p = still ? 1 : clamp((innerHeight - r.top) / (innerHeight * 0.7));
		table.style.setProperty('--dish-progress', p);
	}
}

var queued = false;
addEventListener('scroll', function () {
	if (queued) return;
	queued = true;
	requestAnimationFrame(function () { onScroll(); ambientScroll(); queued = false; });
}, { passive: true });
addEventListener('resize', function () { onScroll(); ambientScroll(); alignCards(); });
updateButton(); onScroll(); ambientScroll(); alignCards();
if (doc.fonts && doc.fonts.ready) doc.fonts.ready.then(alignCards);
addEventListener('load', alignCards);

/* ---------- Цели Яндекс Метрики: ny_ticket, ny_booking, ny_call ---------- */
var ymId = doc.body.getAttribute('data-kny-ym');
doc.addEventListener('click', function (ev) {
	var a = ev.target.closest ? ev.target.closest('a') : null;
	if (!a || !ymId || typeof window.ym !== 'function') return;
	var goal = a.getAttribute('data-kny-goal') || ((a.getAttribute('href') || '').indexOf('tel:') === 0 ? 'ny_call' : '');
	if (goal) { try { window.ym(Number(ymId), 'reachGoal', goal); } catch (e) {} }
}, true);
})();
