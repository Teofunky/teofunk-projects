/* Kozlov Menu: поиск, подсветка раздела в навигации, фото крупно. Без зависимостей. */
(function () {
	'use strict';
	var root = document.getElementById('kzm');
	if (!root) { return; }
	var input = root.querySelector('[data-kzm-q]');
	var chips = root.querySelector('[data-kzm-chips]');
	var empty = root.querySelector('[data-kzm-empty]');
	var sections = [].slice.call(root.querySelectorAll('[data-kzm-section]'));
	var groups = [].slice.call(root.querySelectorAll('.kzm-g'));
	var gLinks = [].slice.call(root.querySelectorAll('[data-kzm-group]'));
	var chipEls = [].slice.call(root.querySelectorAll('[data-kzm-chip]'));

	function norm(s) { return (s || '').toLowerCase().replace(/ё/g, 'е').trim(); }

	// Поиск: прячем блюда, разделы и группы без совпадений.
	var t;
	if (input) {
		input.addEventListener('input', function () {
			clearTimeout(t);
			t = setTimeout(function () {
				var q = norm(input.value), any = false;
				sections.forEach(function (s) {
					var n = 0;
					[].forEach.call(s.querySelectorAll('.kzm-i'), function (li) {
						var ok = !q || norm(li.getAttribute('data-kzm-text')).indexOf(q) !== -1;
						li.hidden = !ok; if (ok) { n++; }
					});
					s.hidden = n === 0;
					var chip = root.querySelector('[data-kzm-chip="' + s.id + '"]');
					if (chip) { chip.hidden = n === 0; }
					if (n) { any = true; }
				});
				groups.forEach(function (g) { g.hidden = !g.querySelector('[data-kzm-section]:not([hidden])'); });
				if (empty) { empty.hidden = any; }
			}, 120);
		});
	}

	// Подсветка текущего раздела и группы при прокрутке.
	var current = '';
	function setOn(id) {
		if (id === current) { return; }
		current = id;
		var g = '';
		chipEls.forEach(function (c) {
			var on = c.getAttribute('data-kzm-chip') === id;
			c.classList.toggle('is-on', on);
			if (on) {
				g = c.getAttribute('data-group');
				var l = c.offsetLeft - chips.clientWidth / 2 + c.clientWidth / 2;
				chips.scrollTo ? chips.scrollTo({ left: l, behavior: 'smooth' }) : (chips.scrollLeft = l);
			}
		});
		gLinks.forEach(function (a) { a.classList.toggle('is-on', a.getAttribute('data-kzm-group') === g); });
	}
	if ('IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) { if (e.isIntersecting) { setOn(e.target.id); } });
		}, { rootMargin: '-30% 0px -60% 0px' });
		sections.forEach(function (s) { io.observe(s); });
	}

	// Фото крупно.
	var dlg = root.querySelector('[data-kzm-dlg]');
	root.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-kzm-zoom]') : null;
		if (b && dlg && dlg.showModal) {
			var li = b.closest('.kzm-i');
			dlg.querySelector('img').src = b.getAttribute('data-kzm-zoom');
			dlg.querySelector('img').alt = b.getAttribute('aria-label') || '';
			dlg.querySelector('p').textContent = li ? li.querySelector('.kzm-n').textContent : '';
			dlg.showModal();
			return;
		}
		if (e.target.closest && (e.target.closest('[data-kzm-close]') || e.target === dlg)) { dlg.close(); }
	});
})();
