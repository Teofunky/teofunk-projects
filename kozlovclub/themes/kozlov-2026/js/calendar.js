/* Календарь концертов: окно с выбором дня или диапазона. Без jQuery. */
(function () {
	'use strict';
	var root = document.getElementById('kz-cal');
	if (!root || !window.fetch) { return; }

	var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
	var GEN = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
	var WD = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

	var data = null, loading = null;
	var today = iso(new Date());
	var view = { y: 0, m: 0 }; // первый показанный месяц
	var sel = { a: '', b: '' }; // выбранный диапазон (строки ГГГГ-ММ-ДД)
	var hover = '';
	var monthsEl = root.querySelector('.kz-cal__months');
	var selText = root.querySelector('.kz-cal__sel-text');
	var resetBtn = root.querySelector('.kz-cal__reset');
	var goBtn = root.querySelector('.kz-cal__go');
	var lastFocus = null;

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function parse(s) { return new Date(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10)); }
	function addDays(s, n) { var d = parse(s); d.setDate(d.getDate() + n); return iso(d); }
	function two() { return window.innerWidth >= 768; }
	function count(a, b) {
		if (!data) { return 0; }
		var n = 0, k;
		for (k in data.days) { if (k >= a && k <= b) { n += data.days[k]; } }
		return n;
	}
	function plural(n, one, few, many) {
		var n10 = n % 10, n100 = n % 100;
		if (n10 === 1 && n100 !== 11) { return one; }
		if (n10 >= 2 && n10 <= 4 && (n100 < 12 || n100 > 14)) { return few; }
		return many;
	}
	function fmt(s, withYear) {
		var d = parse(s);
		return d.getDate() + ' ' + GEN[d.getMonth()] + (withYear ? ' ' + d.getFullYear() : '');
	}
	function rangeLabel(a, b) {
		var da = parse(a), db = parse(b), y = da.getFullYear() !== new Date().getFullYear() || db.getFullYear() !== da.getFullYear();
		if (a === b) { return fmt(a, y); }
		if (da.getMonth() === db.getMonth() && da.getFullYear() === db.getFullYear()) { return da.getDate() + ' – ' + fmt(b, y); }
		return fmt(a, y) + ' – ' + fmt(b, y);
	}

	function load() {
		if (data) { return Promise.resolve(data); }
		if (loading) { return loading; }
		var url = (window.KZ && KZ.ajax ? KZ.ajax : '/wp-admin/admin-ajax.php') + '?action=kz_calendar_days';
		loading = fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
			data = d && d.days ? d : { days: {}, today: today, home: '/' };
			if (data.today) { today = data.today; }
			return data;
		}).catch(function () { data = { days: {}, today: today, home: '/' }; return data; });
		return loading;
	}

	function monthHtml(y, m) {
		var first = new Date(y, m, 1), dow = (first.getDay() + 6) % 7, dim = new Date(y, m + 1, 0).getDate();
		var h = '<div class="kz-cal__month"><div class="kz-cal__mname">' + MONTHS[m] + ' <span>' + y + '</span></div><div class="kz-cal__grid">';
		var i;
		for (i = 0; i < 7; i++) { h += '<div class="kz-cal__wd' + (i >= 5 ? ' is-we' : '') + '">' + WD[i] + '</div>'; }
		for (i = 0; i < dow; i++) { h += '<div class="kz-cal__day is-empty"></div>'; }
		var a = sel.a, b = sel.b || (sel.a && hover ? hover : '');
		if (a && b && b < a) { var t = a; a = b; b = t; }
		for (var d = 1; d <= dim; d++) {
			var s = y + '-' + pad(m + 1) + '-' + pad(d), n = data.days[s] || 0, cls = 'kz-cal__day';
			if (n) { cls += ' has'; }
			if (s < today) { cls += ' is-past'; }
			if (s === today) { cls += ' is-today'; }
			if (((d + dow - 1) % 7) >= 5) { cls += ' is-we'; }
			if (a && b && s >= a && s <= b) { cls += ' in-range'; }
			if (s === sel.a || s === sel.b) { cls += ' is-edge'; }
			if (s === a) { cls += ' is-start'; }
			if (s === b && b !== a) { cls += ' is-end'; }
			h += '<button type="button" class="' + cls + '" data-day="' + s + '"' + (n ? ' title="' + n + ' ' + plural(n, 'концерт', 'концерта', 'концертов') + '"' : '') + '><span>' + d + '</span>' + (n ? '<i></i>' : '') + '</button>';
		}
		return h + '</div></div>';
	}

	function render() {
		if (!data) { return; }
		var h = monthHtml(view.y, view.m);
		if (two()) { var n = view.m + 1, y = view.y; if (n > 11) { n = 0; y++; } h += monthHtml(y, n); }
		monthsEl.innerHTML = h;
		// подвал
		var chips = root.querySelectorAll('.kz-cal__chip');
		Array.prototype.forEach.call(chips, function (c) { c.classList.toggle('is-on', c.getAttribute('data-quick') === sel.q); });
		if (!sel.a) {
			selText.textContent = 'Дата не выбрана';
			resetBtn.hidden = true;
			goBtn.classList.add('is-disabled'); goBtn.textContent = 'Выберите дату'; goBtn.setAttribute('href', '#');
			return;
		}
		var a = sel.a, b = sel.b || sel.a;
		if (b < a) { var t = a; a = b; b = t; }
		selText.textContent = rangeLabel(a, b) + (sel.b ? '' : ' · выберите вторую дату или нажмите «Показать»');
		resetBtn.hidden = false;
		var cnt = count(a, b);
		var url = data.home + '?date=' + a + (b !== a ? '&date2=' + b : '');
		goBtn.setAttribute('href', url);
		if (cnt) {
			goBtn.classList.remove('is-disabled');
			goBtn.textContent = 'Показать ' + cnt + ' ' + plural(cnt, 'концерт', 'концерта', 'концертов');
		} else {
			goBtn.classList.add('is-disabled');
			goBtn.textContent = 'Нет концертов';
		}
	}

	function setView(s) { var d = parse(s); view.y = d.getFullYear(); view.m = d.getMonth(); }
	function shift(n) { var d = new Date(view.y, view.m + n, 1); view.y = d.getFullYear(); view.m = d.getMonth(); render(); }

	function pick(s) {
		sel.q = '';
		if (!sel.a || sel.b) { sel.a = s; sel.b = ''; }
		else if (s === sel.a) { sel.b = s; }
		else { sel.b = s; if (sel.b < sel.a) { var t = sel.a; sel.a = sel.b; sel.b = t; } }
		hover = '';
		render();
	}

	function quick(kind) {
		var d = parse(today), a = today, b = today;
		if (kind === 'tomorrow') { a = b = addDays(today, 1); }
		else if (kind === 'weekend') {
			var dow = (d.getDay() + 6) % 7; // 0 = Пн … 6 = Вс
			if (dow === 6) { a = today; b = today; }
			else { a = dow === 5 ? today : addDays(today, 5 - dow); b = addDays(a, 1); }
		}
		else if (kind === 'week') { b = addDays(today, 6); }
		else if (kind === 'month') { b = addDays(today, 29); }
		sel.a = a; sel.b = b; sel.q = kind; hover = '';
		setView(a);
		render();
	}

	function open() {
		lastFocus = document.activeElement;
		root.hidden = false; root.setAttribute('aria-hidden', 'false');
		document.documentElement.classList.add('kz-cal-open');
		load().then(function () {
			if (!view.y) { setView(sel.a || today); }
			render();
			var x = root.querySelector('.kz-cal__x'); if (x) { x.focus(); }
		});
	}
	function close() {
		root.hidden = true; root.setAttribute('aria-hidden', 'true');
		document.documentElement.classList.remove('kz-cal-open');
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	// открытие по кнопкам темы
	document.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('.calendar-toggler') : null;
		if (t) { e.preventDefault(); open(); }
	});
	root.addEventListener('click', function (e) {
		var t = e.target.closest ? e.target.closest('[data-cal],[data-day],[data-quick]') : null;
		if (!t) { return; }
		if (t.hasAttribute('data-day')) { pick(t.getAttribute('data-day')); return; }
		if (t.hasAttribute('data-quick')) { quick(t.getAttribute('data-quick')); return; }
		var act = t.getAttribute('data-cal');
		if (act === 'close') { close(); }
		else if (act === 'prev') { shift(-1); }
		else if (act === 'next') { shift(1); }
		else if (act === 'reset') { sel = { a: '', b: '' }; hover = ''; render(); }
		else if (act === 'go' && t.classList.contains('is-disabled')) { e.preventDefault(); }
	});
	root.addEventListener('mouseover', function (e) {
		var t = e.target.closest ? e.target.closest('[data-day]') : null;
		if (!t || !sel.a || sel.b) { return; }
		var s = t.getAttribute('data-day');
		if (s !== hover) { hover = s; render(); }
	});
	document.addEventListener('keydown', function (e) {
		if (!root.hidden && e.key === 'Escape') { close(); }
	});
	var rt;
	window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { if (!root.hidden) { render(); } }, 150); });

	// текущий выбор из адреса страницы
	var q = new URLSearchParams(window.location.search);
	var d1 = q.get('date') || q.get('date1'), d2 = q.get('date2');
	if (d1 && /^\d{4}-\d\d-\d\d$/.test(d1)) { sel.a = d1; sel.b = (d2 && /^\d{4}-\d\d-\d\d$/.test(d2) && d2 > d1) ? d2 : ''; if (!sel.b) { sel.b = d1; } }
})();
