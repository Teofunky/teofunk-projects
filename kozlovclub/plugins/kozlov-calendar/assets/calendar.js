/* Kozlov Calendar 1.0.0: компактный календарь концертов. Без jQuery и глобальных переменных, стили изолированы в Shadow DOM. */
(function () {
	'use strict';
	var C = window.KozlovCalendarConfig;
	if (!C || !document.body || !document.body.attachShadow || !window.URL) { return; }

	var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
	var GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
	var WDL = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
	var RX = /^\d{4}-\d\d-\d\d$/;

	var CSS = '' +
	':host{all:initial;font-family:inherit}' +
	'*{box-sizing:border-box}' +
	'.ov{--ink:#22201e;--muted:#746e6b;--faint:#cfcac6;--line:#ece8e4;--paper:#fff;--soft:#f6f4f2;--red:#d83d37;--red-dark:#b52d29;--band:#fbe9e7;--cell:44px;' +
		'position:fixed;inset:0;z-index:2147483000;display:flex;align-items:flex-end;justify-content:center;background:rgba(26,23,21,0);transition:background .2s;' +
		'font:15px/1.4 var(--kc-font,inherit),-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);-webkit-font-smoothing:antialiased;font-variant-numeric:tabular-nums}' +
	'.ov[hidden]{display:none}' +
	'.ov.on{background:rgba(26,23,21,.42)}' +
	'button{font:inherit;color:inherit;background:none;border:0;margin:0;padding:0;cursor:pointer;-webkit-tap-highlight-color:transparent}' +
	'button:focus-visible{outline:2px solid var(--red);outline-offset:2px}' +
	'button:disabled{cursor:default}' +
	'.sh{position:relative;width:100%;max-height:calc(100% - 12px);overflow:auto;background:var(--paper);border-radius:22px 22px 0 0;' +
		'padding:8px max(18px,env(safe-area-inset-right,0px)) calc(14px + env(safe-area-inset-bottom,0px)) max(18px,env(safe-area-inset-left,0px));' +
		'transform:translateY(100%);transition:transform .26s cubic-bezier(.2,.8,.2,1);box-shadow:0 -12px 50px rgba(0,0,0,.18);overscroll-behavior:contain}' +
	'.ov.on .sh{transform:none}' +
	'.grab{width:36px;height:4px;border-radius:2px;background:#e3dfdc;margin:0 auto 6px}' +
	'.hd{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:44px}' +
	'.hd h2{margin:0;font-size:17px;font-weight:700;letter-spacing:-.01em}' +
	'.x{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;background:var(--soft);flex:none}' +
	'.chips{display:flex;gap:6px;margin:8px 0 6px;overflow-x:auto;scrollbar-width:none}' +
	'.chips::-webkit-scrollbar{display:none}' +
	'.chip{flex:none;height:34px;padding:0 13px;border-radius:17px;background:var(--soft);font-size:13px;font-weight:600;white-space:nowrap;border:1px solid transparent}' +
	'.chip[aria-pressed="true"]{background:var(--band);color:var(--red-dark);border-color:#f1cecb}' +
	'.mh{display:flex;align-items:center;justify-content:space-between;height:44px;margin-top:4px}' +
	'.mt{font-size:16px;font-weight:700;letter-spacing:-.01em}' +
	'.mt span{font-weight:400;color:var(--muted)}' +
	'.nav{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;border:1px solid var(--line)}' +
	'.nav:disabled{opacity:.3}' +
	'.wd,.g{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}' +
	'.wd span{text-align:center;font-size:11px;font-weight:700;letter-spacing:.04em;color:var(--muted);height:26px;line-height:26px}' +
	'.wd span:nth-child(n+6){color:var(--red)}' +
	'.g{row-gap:2px;transition:opacity .12s;touch-action:pan-y}' +
	'.g.fade{opacity:0}' +
	'.c{position:relative;height:var(--cell);display:flex;align-items:center;justify-content:center}' +
	'.c::before{content:none;position:absolute;top:0;bottom:0;left:0;right:0;background:var(--band)}' +
	'.c.in::before,.c.rs::before,.c.re::before{content:""}' +
	'.c.rs::before{left:50%}' +
	'.c.re::before{right:50%}' +
	'.c.in.L::before{left:calc(50% - var(--cell)/2);border-radius:var(--cell) 0 0 var(--cell)}' +
	'.c.in.R::before{right:calc(50% - var(--cell)/2);border-radius:0 var(--cell) var(--cell) 0}' +
	'.c.rs.R::before,.c.re.L::before{content:none}' +
	'.d{position:relative;z-index:1;width:var(--cell);height:var(--cell);border-radius:50%;font-size:15px;font-weight:600;display:flex;align-items:center;justify-content:center}' +
	'.c.we .d{color:var(--red)}' +
	'.d:disabled{color:var(--faint)!important;font-weight:400}' +
	'.c.today .d{font-weight:800}' +
	'.c.today .d::after{content:"";position:absolute;left:50%;bottom:6px;width:4px;height:4px;margin-left:-2px;border-radius:50%;background:var(--red)}' +
	'.c.in .d{color:var(--ink)}' +
	'.c.sel .d{background:var(--red);color:#fff!important}' +
	'.c.sel .d::after{background:#fff}' +
	'@media (hover:hover){.d:not(:disabled):hover{background:var(--soft)}.c.in .d:not(:disabled):hover{background:#f4dad6}.c.sel .d:hover{background:var(--red-dark)}.chip:hover{background:#efebe8}.nav:not(:disabled):hover,.x:hover{background:#efebe8}.go:not(:disabled):hover{background:var(--red-dark)}}' +
	'.ft{display:flex;align-items:center;gap:12px;min-height:52px;margin-top:8px;padding-top:8px;border-top:1px solid var(--line)}' +
	'.sum{flex:1;min-width:0}' +
	'.sum b{display:block;font-size:15px;font-weight:700}' +
	'.sum span{display:block;font-size:12px;color:var(--muted)}' +
	'.rs-btn{font-size:13px;font-weight:600;color:var(--muted);height:40px;padding:0 4px}' +
	'.rs-btn[hidden]{display:none}' +
	'.go{width:100%;height:50px;margin-top:8px;border-radius:13px;background:var(--red);color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px}' +
	'.go:disabled{background:#ebe7e4;color:#a39d99}' +
	'@media (min-width:761px){' +
		'.ov{align-items:center;--cell:42px}' +
		'.sh{width:384px;max-height:calc(100% - 40px);border-radius:20px;padding:12px 20px 18px;transform:translateY(10px) scale(.98);opacity:0;transition:transform .16s,opacity .16s;box-shadow:0 24px 80px rgba(27,23,21,.24)}' +
		'.ov.on .sh{transform:none;opacity:1}' +
		'.grab{display:none}' +
	'}' +
	'@media (max-height:600px) and (max-width:760px){.ov{--cell:38px}.chips{margin:4px 0}}' +
	'@media (prefers-reduced-motion:reduce){.ov,.sh,.g{transition:none!important}}';

	var ARROW_L = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>';
	var ARROW_R = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';

	var host = document.createElement('div');
	host.id = 'kozlov-calendar';
	document.body.appendChild(host);
	var sr = host.attachShadow({ mode: 'open' });
	sr.innerHTML = '<style>' + CSS + '</style>' +
		'<div class="ov" hidden>' +
		'<section class="sh" role="dialog" aria-modal="true" aria-labelledby="kc-t">' +
			'<div class="grab" aria-hidden="true"></div>' +
			'<div class="hd"><h2 id="kc-t">Календарь концертов</h2><button type="button" class="x" data-a="close" aria-label="Закрыть"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>' +
			'<div class="chips" role="group" aria-label="Быстрый выбор">' +
				'<button type="button" class="chip" data-q="today">Сегодня</button>' +
				'<button type="button" class="chip" data-q="tomorrow">Завтра</button>' +
				(C.range ? '<button type="button" class="chip" data-q="weekend">Выходные</button><button type="button" class="chip" data-q="week">7 дней</button>' : '') +
			'</div>' +
			'<div class="mh"><button type="button" class="nav" data-a="prev" aria-label="Предыдущий месяц">' + ARROW_L + '</button><div class="mt" aria-live="polite"></div><button type="button" class="nav" data-a="next" aria-label="Следующий месяц">' + ARROW_R + '</button></div>' +
			'<div class="wd" aria-hidden="true"><span>ПН</span><span>ВТ</span><span>СР</span><span>ЧТ</span><span>ПТ</span><span>СБ</span><span>ВС</span></div>' +
			'<div class="g" role="grid" aria-label="Даты"></div>' +
			'<div class="ft" aria-live="polite"><div class="sum"><b></b><span></span></div><button type="button" class="rs-btn" data-a="reset">Сбросить</button></div>' +
			'<button type="button" class="go" data-a="go"><span></span>' + ARROW_R + '</button>' +
		'</section></div>';

	var $ = function (s) { return sr.querySelector(s); };
	var ov = $('.ov'), sheet = $('.sh'), grid = $('.g'), mt = $('.mt'), sumB = $('.sum b'), sumS = $('.sum span'), resetBtn = $('.rs-btn'), goBtn = $('.go'), goText = $('.go span');
	var prevBtn = sr.querySelector('[data-a="prev"]'), nextBtn = sr.querySelector('[data-a="next"]');

	var today = RX.test(C.today) ? C.today : iso(new Date());
	var maxDay = lastOfMonth(addMonths(today, C.months || 12));
	var data = null, loading = null;
	var view = { y: 0, m: 0 }, a = '', b = '', hover = '', quickKey = '', lastFocus = null, savedOverflow = '', fresh = true;

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function parse(s) { return new Date(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)); }
	function addDays(s, n) { var d = parse(s); d.setDate(d.getDate() + n); return iso(d); }
	function addMonths(s, n) { var d = parse(s); return iso(new Date(d.getFullYear(), d.getMonth() + n, 1)); }
	function lastOfMonth(s) { var d = parse(s); return iso(new Date(d.getFullYear(), d.getMonth() + 1, 0)); }
	function monthIndex(y, m) { var t = parse(today); return (y - t.getFullYear()) * 12 + m - t.getMonth(); }
	function plural(n) { var x = n % 10, h = n % 100; return x === 1 && h !== 11 ? 'концерт' : x >= 2 && x <= 4 && (h < 12 || h > 14) ? 'концерта' : 'концертов'; }
	function pluralDays(n) { var x = n % 10, h = n % 100; return x === 1 && h !== 11 ? 'день' : x >= 2 && x <= 4 && (h < 12 || h > 14) ? 'дня' : 'дней'; }
	function fmt(s) { var d = parse(s); return d.getDate() + ' ' + GEN[d.getMonth()] + (d.getFullYear() !== parse(today).getFullYear() ? ' ' + d.getFullYear() : ''); }
	function rangeLabel(x, y) {
		var dx = parse(x), dy = parse(y);
		if (dx.getMonth() === dy.getMonth() && dx.getFullYear() === dy.getFullYear()) { return dx.getDate() + '–' + fmt(y); }
		return fmt(x) + ' – ' + fmt(y);
	}
	function hasCount() { return !!data; }
	function count(x, y) { var n = 0, k; for (k in data) { if (k >= x && k <= y) { n += data[k]; } } return n; }
	function enabled(s) {
		if (s < today || s > maxDay) { return false; }
		if (data && !data[s]) { return false; }
		return true;
	}
	function daysBetween(x, y) { return Math.round((parse(y) - parse(x)) / 864e5) + 1; }

	function load() {
		if (data || loading) { return loading || Promise.resolve(); }
		loading = fetch(C.api, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
			if (j && j.days && typeof j.days === 'object' && Object.keys(j.days).length) {
				data = {};
				Object.keys(j.days).forEach(function (k) { if (RX.test(k)) { data[k] = +j.days[k] || 0; } });
			}
			if (j && RX.test(j.today)) { today = j.today; }
		}).catch(function () {}).then(function () { loading = null; });
		return loading;
	}

	function setView(s) { var d = parse(s); view.y = d.getFullYear(); view.m = d.getMonth(); }

	function render() {
		var idx = monthIndex(view.y, view.m), maxIdx = monthIndex(parse(maxDay).getFullYear(), parse(maxDay).getMonth());
		mt.innerHTML = MONTHS[view.m] + ' <span>' + view.y + '</span>';
		prevBtn.disabled = idx <= 0;
		nextBtn.disabled = idx >= maxIdx;

		var end = b || (a && hover && hover > a ? hover : '');
		var lead = (new Date(view.y, view.m, 1).getDay() + 6) % 7, len = new Date(view.y, view.m + 1, 0).getDate();
		var html = '';
		for (var i = 0; i < lead; i++) { html += '<div class="c" aria-hidden="true"></div>'; }
		for (var n = 1; n <= len; n++) {
			var s = view.y + '-' + pad(view.m + 1) + '-' + pad(n), col = (lead + n - 1) % 7, cls = ['c'];
			var on = enabled(s), d = new Date(view.y, view.m, n);
			if (col > 4) { cls.push('we'); }
			if (s === today) { cls.push('today'); }
			if (col === 0 || n === 1) { cls.push('L'); }
			if (col === 6 || n === len) { cls.push('R'); }
			var isSel = s === a || (end && s === end);
			if (isSel) { cls.push('sel'); }
			if (a && end) {
				if (s === a) { cls.push('rs'); }
				else if (s === end) { cls.push('re'); }
				else if (s > a && s < end) { cls.push('in'); }
			}
			var label = WDL[d.getDay()] + ', ' + n + ' ' + GEN[view.m] + (s === today ? ', сегодня' : '') +
				(data ? (data[s] ? ', ' + data[s] + ' ' + plural(data[s]) : ', концертов нет') : '') + (s < today ? ', прошло' : '');
			html += '<div class="' + cls.join(' ') + '" role="gridcell"><button type="button" class="d" data-day="' + s + '"' + (on ? '' : ' disabled') +
				' aria-label="' + label + '" aria-pressed="' + (isSel ? 'true' : 'false') + '"' + (s === today ? ' aria-current="date"' : '') + ' tabindex="-1">' + n + '</button></div>';
		}
		grid.innerHTML = html;
		var focusable = grid.querySelector('.sel .d:not(:disabled)') || grid.querySelector('.today .d:not(:disabled)') || grid.querySelector('.d:not(:disabled)');
		if (focusable) { focusable.tabIndex = 0; }

		Array.prototype.forEach.call(sr.querySelectorAll('.chip'), function (el) { el.setAttribute('aria-pressed', el.getAttribute('data-q') === quickKey ? 'true' : 'false'); });
		summary();
	}

	function summary() {
		resetBtn.hidden = !a;
		if (!a) {
			sumB.textContent = 'Дата не выбрана';
			sumS.textContent = C.range ? 'Нажмите на день или на две даты для периода' : 'Нажмите на день';
			goBtn.disabled = true; goText.textContent = 'Выберите дату';
			return;
		}
		var y = b || a, n = hasCount() ? count(a, y) : -1;
		if (b) {
			sumB.textContent = rangeLabel(a, b);
			var dd = daysBetween(a, b);
			sumS.textContent = dd + ' ' + pluralDays(dd);
		} else {
			var d = parse(a);
			sumB.textContent = fmt(a) + ', ' + WDL[d.getDay()];
			sumS.textContent = C.range ? 'Можно выбрать вторую дату для периода' : '';
		}
		goBtn.disabled = n === 0;
		goText.textContent = n < 0 ? 'Показать концерты' : n === 0 ? 'Концертов нет' : 'Показать ' + n + ' ' + plural(n);
	}

	function pick(s) {
		quickKey = ''; hover = '';
		if (!C.range || fresh || !a || b || s < a) { a = s; b = ''; }
		else if (s === a) { b = ''; }
		else { b = s; }
		fresh = false;
		render();
		var el = grid.querySelector('[data-day="' + s + '"]'); if (el) { el.focus({ preventScroll: true }); }
	}

	function firstEnabledFrom(s, max) { for (var i = 0; i < 40 && s <= max; i++, s = addDays(s, 1)) { if (enabled(s)) { return s; } } return ''; }

	function quick(k) {
		var t = parse(today), dow = t.getDay(), x = today, y = '';
		if (k === 'tomorrow') { x = addDays(today, 1); }
		else if (k === 'weekend') {
			if (dow === 0) { x = today; }
			else if (dow === 6) { x = today; y = addDays(today, 1); }
			else { x = addDays(today, 6 - dow); y = addDays(x, 1); }
		} else if (k === 'week') { y = addDays(today, 6); }
		if (!enabled(x)) { x = firstEnabledFrom(x, y || x) || x; }
		a = x; b = y && y > x ? y : ''; quickKey = k; hover = ''; fresh = true;
		setView(a); render();
	}

	function shift(dir) {
		var d = new Date(view.y, view.m + dir, 1), idx = monthIndex(d.getFullYear(), d.getMonth());
		if (idx < 0 || d > parse(maxDay)) { return; }
		grid.classList.add('fade');
		setTimeout(function () { view.y = d.getFullYear(); view.m = d.getMonth(); render(); grid.classList.remove('fade'); }, 110);
	}

	function go() {
		if (!a) { return; }
		var u = new URL(C.base, window.location.href);
		u.searchParams.delete(C.p1); u.searchParams.delete(C.p2); u.searchParams.delete('date');
		u.searchParams.set(C.p1, a);
		if (b && C.range) { u.searchParams.set(C.p2, b); }
		goText.textContent = 'Открываю…';
		window.location.href = u.toString();
	}

	function open() {
		lastFocus = document.activeElement;
		var q = new URLSearchParams(window.location.search), d1 = q.get(C.p1) || q.get('date'), d2 = q.get(C.p2);
		if (d1 && RX.test(d1) && d1 >= today) { a = d1; b = C.range && d2 && RX.test(d2) && d2 > d1 ? d2 : ''; }
		else if (!a) { a = today; b = ''; quickKey = 'today'; }
		hover = ''; fresh = true;
		setView(a);
		savedOverflow = document.documentElement.style.overflow;
		document.documentElement.style.overflow = 'hidden';
		ov.hidden = false;
		render();
		requestAnimationFrame(function () { ov.classList.add('on'); });
		var f = grid.querySelector('.d[tabindex="0"]'); if (f) { f.focus({ preventScroll: true }); }
		load().then(function () {
			if (a && !enabled(a)) { a = firstEnabledFrom(a, addDays(a, 40)); b = ''; quickKey = ''; if (a) { setView(a); } }
			render();
		});
	}

	function close() {
		ov.classList.remove('on');
		document.documentElement.style.overflow = savedOverflow;
		setTimeout(function () { ov.hidden = true; }, 220);
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
	}

	// кнопки темы: открываем этот календарь вместо старого
	window.addEventListener('click', function (e) {
		var t = e.target && e.target.closest ? e.target.closest(C.selector) : null;
		if (!t || host.contains(t)) { return; }
		e.preventDefault(); e.stopImmediatePropagation();
		if (ov.hidden) { open(); }
	}, true);

	sr.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('[data-a],[data-day],[data-q]') : null;
		if (e.target === ov) { close(); return; }
		if (!t) { return; }
		if (t.hasAttribute('data-day')) { if (!t.disabled) { pick(t.getAttribute('data-day')); } return; }
		if (t.hasAttribute('data-q')) { quick(t.getAttribute('data-q')); return; }
		var act = t.getAttribute('data-a');
		if (act === 'close') { close(); }
		else if (act === 'prev') { shift(-1); }
		else if (act === 'next') { shift(1); }
		else if (act === 'reset') { a = ''; b = ''; hover = ''; quickKey = ''; fresh = true; render(); }
		else if (act === 'go') { go(); }
	});

	if (window.matchMedia && matchMedia('(hover:hover)').matches) {
		grid.addEventListener('mouseover', function (e) {
			var t = e.target.closest ? e.target.closest('[data-day]') : null;
			if (!C.range || fresh || !t || t.disabled || !a || b) { return; }
			var s = t.getAttribute('data-day');
			if (s !== hover) { hover = s; render(); }
		});
		grid.addEventListener('mouseleave', function () { if (hover) { hover = ''; render(); } });
	}

	sr.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { e.preventDefault(); close(); return; }
		if (e.key === 'Tab') {
			var els = Array.prototype.filter.call(sheet.querySelectorAll('button'), function (el) { return !el.disabled && el.tabIndex !== -1 && !el.hidden && el.offsetParent !== null; });
			if (!els.length) { return; }
			var first = els[0], last = els[els.length - 1], act = sr.activeElement;
			if (e.shiftKey && act === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && act === last) { e.preventDefault(); first.focus(); }
			return;
		}
		var cur = sr.activeElement;
		if (!cur || !cur.hasAttribute || !cur.hasAttribute('data-day')) { return; }
		var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
		if (!step) { return; }
		e.preventDefault();
		var s = addDays(cur.getAttribute('data-day'), step);
		if (s < today || s > maxDay) { return; }
		var d = parse(s);
		if (d.getMonth() !== view.m || d.getFullYear() !== view.y) { setView(s); render(); }
		var el = grid.querySelector('[data-day="' + s + '"]');
		if (el) { Array.prototype.forEach.call(grid.querySelectorAll('.d'), function (x) { x.tabIndex = -1; }); el.tabIndex = 0; el.focus(); }
	});

	// жесты на телефоне: влево/вправо листают месяц, вниз за шапку закрывают
	var x0 = null, y0 = null, fromHead = false;
	sheet.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; fromHead = !!(e.target.closest && e.target.closest('.grab,.hd')); }, { passive: true });
	sheet.addEventListener('touchend', function (e) {
		if (x0 === null) { return; }
		var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
		if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5 && e.target.closest && e.target.closest('.g')) { shift(dx < 0 ? 1 : -1); }
		else if (fromHead && dy > 70 && dy > Math.abs(dx)) { close(); }
		x0 = y0 = null;
	});
})();
