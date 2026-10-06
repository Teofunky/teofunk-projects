(function () {
	'use strict';
	var root = document.documentElement;

	/* Шапка: меню в одну строку рядом с логотипом; не помещается, уходит на вторую строку;
	   не помещается и там, сворачивается в кнопку «Меню». Проверяется при загрузке,
	   изменении ширины окна и размера текста. */
	var header = document.querySelector('.site-header');
	var navBox = document.querySelector('.site-nav');
	var menuList = document.querySelector('.site-nav .menu');
	function fitHeader() {
		if (!header || !navBox || !menuList) { return; }
		header.classList.remove('is-stacked', 'is-collapsed');
		if (menuList.scrollWidth > navBox.clientWidth + 1) {
			header.classList.add('is-stacked');
			if (menuList.scrollWidth > navBox.clientWidth + 1) { header.classList.add('is-collapsed'); }
		}
	}
	fitHeader();
	var fitTimer;
	window.addEventListener('resize', function () { clearTimeout(fitTimer); fitTimer = setTimeout(fitHeader, 80); });
	if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitHeader); }
	window.addEventListener('load', fitHeader);

	/* Размер текста: запоминаем выбор читателя. */
	var sizeButtons = document.querySelectorAll('.text-size button');
	function applySize(size) {
		if (size === 'l' || size === 'xl') {
			root.setAttribute('data-size', size);
		} else {
			root.removeAttribute('data-size');
			size = 'm';
		}
		sizeButtons.forEach(function (b) {
			b.setAttribute('aria-pressed', b.getAttribute('data-size') === size ? 'true' : 'false');
		});
		fitHeader();
	}
	applySize(root.getAttribute('data-size'));
	sizeButtons.forEach(function (b) {
		b.addEventListener('click', function () {
			var size = b.getAttribute('data-size');
			applySize(size);
			try { localStorage.setItem('jac-size', size); } catch (e) {}
		});
	});

	/* Подменю открывается по нажатию на стрелку, а не по наведению:
	   оно не исчезает, если мышь случайно ушла в сторону. */
	var parents = document.querySelectorAll('.menu > .menu-item-has-children');
	function closeAll(except) {
		parents.forEach(function (li) {
			if (li !== except) {
				li.classList.remove('is-open');
				var t = li.querySelector('.submenu-toggle');
				if (t) { t.setAttribute('aria-expanded', 'false'); }
			}
		});
	}
	parents.forEach(function (li, i) {
		var link = li.querySelector('a');
		var sub = li.querySelector('.sub-menu');
		if (!link || !sub) { return; }
		sub.id = sub.id || 'submenu-' + i;
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'submenu-toggle';
		btn.setAttribute('aria-expanded', 'false');
		btn.setAttribute('aria-controls', sub.id);
		btn.innerHTML = '<span class="visually-hidden">Показать подразделы: ' + link.textContent.trim() + '</span>';
		link.after(btn);
		btn.addEventListener('click', function () {
			var open = !li.classList.contains('is-open');
			closeAll(li);
			li.classList.toggle('is-open', open);
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	});
	document.addEventListener('click', function (e) {
		if (!e.target.closest('.menu > .menu-item-has-children')) { closeAll(null); }
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		var open = document.querySelector('.menu > .is-open');
		if (open) {
			closeAll(null);
			var t = open.querySelector('.submenu-toggle');
			if (t) { t.focus(); }
		}
	});

	/* Поиск: кнопка в шапке раскрывает строку поиска. Без скрипта она ведёт на страницу поиска. */
	var searchToggle = document.querySelector('[data-search-toggle]');
	var searchPanel = document.getElementById('search-panel');
	if (searchToggle && searchPanel) {
		searchToggle.addEventListener('click', function (e) {
			e.preventDefault();
			var open = searchPanel.hasAttribute('hidden');
			if (open) { searchPanel.removeAttribute('hidden'); } else { searchPanel.setAttribute('hidden', ''); }
			searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) { var field = searchPanel.querySelector('input[type="search"]'); if (field) { field.focus(); } }
		});
	}

	/* Меню на телефоне. */
	var nav = document.querySelector('.site-nav');
	var navToggle = document.querySelector('.nav-toggle');
	if (nav && navToggle) {
		navToggle.addEventListener('click', function () {
			var open = nav.classList.toggle('is-open');
			navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
	}

	/* Картинка, которая не загрузилась (например, со стороннего сайта, которого уже нет),
	   не оставляет на странице значок «сломанного» изображения. */
	document.querySelectorAll('main img').forEach(function (img) {
		function hide() { img.style.display = 'none'; }
		if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) { hide(); }
		img.addEventListener('error', hide);
	});

	/* Печать статьи. */
	document.querySelectorAll('[data-print]').forEach(function (b) {
		b.addEventListener('click', function () { window.print(); });
	});
})();
