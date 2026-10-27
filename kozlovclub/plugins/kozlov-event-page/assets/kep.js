/* Kozlov Event Page: поведение новой страницы концерта. Без jQuery и глобальных переменных. 1.0.14 */
(function () {
	'use strict';

	var root = document.getElementById('kep');
	if (!root) {
		return;
	}

	function each(sel, fn) {
		Array.prototype.forEach.call(root.querySelectorAll(sel), fn);
	}

	// «Сегодня» / «Завтра» считается в браузере, чтобы кэш страницы не врал.
	var iso = root.getAttribute('data-date');
	if (iso && /^\d{4}-\d{2}-\d{2}$/.test(iso)) {
		var p = iso.split('-');
		var ev = new Date(+p[0], +p[1] - 1, +p[2]);
		var now = new Date();
		var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
		var diff = Math.round((ev - today) / 864e5);
		var label = diff === 0 ? 'Сегодня' : (diff === 1 ? 'Завтра' : '');
		// Ночной концерт (23:59 в афише) начинается в полночь после указанного дня.
		if (root.getAttribute('data-time') === '23:59') {
			var mins = now.getHours() * 60 + now.getMinutes();
			label = diff === 0 ? 'Сегодня ночью' : (diff === 1 ? 'Завтра ночью' : (diff === -1 && mins < 150 ? 'Идёт сейчас' : ''));
		}
		if (label) {
			each('.kep-badge', function (b) { b.textContent = label; b.hidden = false; });
		}
	}

	// Видео YouTube: плеер загружается только по нажатию.
	each('[data-kep-embed]', function (btn) {
		btn.addEventListener('click', function () {
			var f = document.createElement('iframe');
			f.src = btn.getAttribute('data-kep-embed');
			f.title = btn.getAttribute('aria-label') || 'Видео';
			f.allow = 'autoplay; encrypted-media; fullscreen; picture-in-picture';
			f.setAttribute('allowfullscreen', '');
			var box = document.createElement('div');
			box.className = 'kep-video kep-video--frame';
			box.appendChild(f);
			btn.parentNode.replaceChild(box, btn);
		});
	});

	// «Смотреть видео» на фото: плавная прокрутка.
	each('.kep-watch', function (a) {
		a.addEventListener('click', function (e) {
			var target = document.getElementById('kep-video');
			if (target && target.scrollIntoView) {
				e.preventDefault();
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		});
	});

	// Длинный текст «О концерте» сворачивается. Текст весь в коде страницы (для поиска он виден целиком).
	each('.kep-clamp', function (box) {
		var btn = root.querySelector('[aria-controls="' + box.id + '"]');
		box.classList.add('is-clamped');
		if (box.scrollHeight <= box.clientHeight + 24) {
			box.classList.remove('is-clamped');
			return;
		}
		if (!btn) {
			box.classList.remove('is-clamped');
			return;
		}
		btn.hidden = false;
		btn.addEventListener('click', function () {
			var open = box.classList.toggle('is-clamped') === false;
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			btn.textContent = open ? 'Свернуть' : 'Читать полностью';
			// После «Свернуть» вернуть к началу блока, а не оставлять посреди следующих разделов.
			var sec = box.closest ? box.closest('section') : null;
			if (!open && sec && sec.getBoundingClientRect().top < 0) {
				sec.scrollIntoView({ block: 'start' });
			}
		});
	});

	// Нижняя панель покупки: появляется, когда основная кнопка ушла за верх экрана.
	var fixed = document.querySelector('.kep-fixed');
	var bar = fixed ? fixed.querySelector('.kep-bar') : null;
	var buy = document.getElementById('kep-buy');
	if (bar && buy && 'IntersectionObserver' in window) {
		var io = new IntersectionObserver(function (entries) {
			var e = entries[0];
			var show = !e.isIntersecting && e.boundingClientRect.top < 0;
			bar.classList.toggle('is-on', show);
			bar.setAttribute('aria-hidden', show ? 'false' : 'true');
		});
		io.observe(buy);
	}

	var card = root.querySelector('.kep-card');
	var desk = window.matchMedia ? window.matchMedia('(min-width: 1025px)') : null;
	var frame = 0;

	// Даты концерта на компьютере: виден первый ряд, остальные за плиткой «ещё N дат»,
	// чтобы карточка покупки влезала в окно и могла прилипать целиком.
	var dates = root.querySelector('.kep-dates');
	var datesList = dates ? dates.querySelector('.kep-dates-list') : null;
	var datesOpen = false;
	var moreLi = null;
	function plural(n, one, few, many) {
		var m10 = n % 10, m100 = n % 100;
		if (m10 === 1 && m100 !== 11) { return one; }
		if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) { return few; }
		return many;
	}
	function foldDates() {
		if (!datesList) {
			return;
		}
		var items = Array.prototype.filter.call(datesList.children, function (li) { return li !== moreLi; });
		if (moreLi && moreLi.parentNode) {
			moreLi.parentNode.removeChild(moreLi);
		}
		items.forEach(function (li) { li.hidden = false; });
		if (!desk || !desk.matches || datesOpen || items.length < 3) {
			return;
		}
		var firstTop = items[0].offsetTop;
		var rowEnd = items.length;
		for (var i = 1; i < items.length; i++) {
			if (items[i].offsetTop > firstTop) {
				rowEnd = i;
				break;
			}
		}
		if (rowEnd === items.length) {
			return; // всё в одном ряду
		}
		var cut = rowEnd - 1; // последняя плитка первого ряда уступает место кнопке
		var hidden = items.slice(cut);
		if (hidden.length < 2) {
			return;
		}
		// Текущая дата должна оставаться видимой: если она в хвосте, не сворачиваем.
		for (var h = 0; h < hidden.length; h++) {
			if (hidden[h].querySelector('.is-cur')) {
				return;
			}
		}
		hidden.forEach(function (li) { li.hidden = true; });
		if (!moreLi) {
			moreLi = document.createElement('li');
			moreLi.className = 'kep-dates-more';
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'kep-date kep-date--more';
			b.addEventListener('click', function () {
				var next = moreLi.nextElementSibling; // первая раскрываемая дата
				datesOpen = true;
				foldDates();
				placeSoon();
				var a = next ? next.querySelector('a') : null;
				if (a) {
					a.focus(); // клавиатура: фокус на первую раскрытую дату, а не в пустоту
				}
			});
			moreLi.appendChild(b);
		}
		var n = hidden.length;
		var btn = moreLi.firstChild;
		btn.innerHTML = '<span class="kep-date-w">ещё</span><span class="kep-date-d">+' + n + '</span><span class="kep-date-m">' + plural(n, 'дата', 'даты', 'дат') + '</span>';
		btn.setAttribute('aria-label', 'Показать ещё ' + n + ' ' + plural(n, 'дату', 'даты', 'дат'));
		btn.setAttribute('aria-expanded', 'false');
		datesList.insertBefore(moreLi, hidden[0]);
	}

	// Компьютер: карточка покупки прилипает сразу под шапкой темы. Шапка фиксированная
	// и меняет высоту при прокрутке, поэтому отступ меряется по факту, а не числом в CSS.
	function coveredTop(x) {
		var y = 0;
		for (var round = 0; round < 6 && document.elementsFromPoint; round++) {
			var list = document.elementsFromPoint(x, y + 1);
			var next = y;
			for (var i = 0; i < list.length; i++) {
				var el = list[i];
				if (root.contains(el) || (fixed && fixed.contains(el))) {
					continue;
				}
				var pinned = null;
				for (var a = el; a && a !== document.body && a !== document.documentElement; a = a.parentElement) {
					var pos = window.getComputedStyle(a).position;
					if (pos === 'fixed' || pos === 'sticky') {
						pinned = a;
						break;
					}
				}
				if (!pinned) {
					break; // дальше по списку только обычное содержимое страницы под шапкой
				}
				var b = pinned.getBoundingClientRect().bottom;
				if (b > next && b < window.innerHeight / 2) {
					next = b;
				}
			}
			if (next <= y) {
				break;
			}
			y = Math.ceil(next);
		}
		return y;
	}
	function placeCard() {
		frame = 0;
		if (!desk || !desk.matches) {
			card.style.top = '';
			card.classList.remove('is-free');
			return;
		}
		var r = card.getBoundingClientRect();
		var top = coveredTop(Math.round(r.left + r.width / 2)) + 16;
		card.style.top = top + 'px';
		// Карточка выше свободного места: едет вместе со страницей, а цену и кнопку даёт
		// нижняя панель (та же, что на телефоне): она появляется, когда кнопка в карточке
		// ушла за верх экрана. Иначе при прилипании часть карточки (кнопка или «Важно знать») не видна.
		card.classList.toggle('is-free', card.offsetHeight + top + 16 > window.innerHeight);
	}
	function placeSoon() {
		if (!frame) {
			frame = window.requestAnimationFrame(placeCard);
		}
	}
	var resizeTimer;
	function onResize() {
		placeSoon();
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(function () { foldDates(); placeSoon(); }, 120);
	}
	if (card) {
		foldDates();
		placeCard();
		window.addEventListener('scroll', placeSoon, { passive: true });
		window.addEventListener('resize', onResize);
		window.addEventListener('load', onResize);
		document.addEventListener('transitionend', placeSoon, true);
		if (desk && desk.addEventListener) {
			desk.addEventListener('change', onResize);
		} else if (desk && desk.addListener) {
			desk.addListener(onResize);
		}
	}

	// Поделиться: системное меню телефона или копирование ссылки.
	var toast = fixed ? fixed.querySelector('.kep-toast') : null;
	var timer;
	function say(text) {
		if (!toast) {
			return;
		}
		toast.textContent = text;
		toast.hidden = false;
		clearTimeout(timer);
		timer = setTimeout(function () { toast.hidden = true; }, 2600);
	}
	each('[data-kep-share]', function (btn) {
		btn.addEventListener('click', function () {
			var url = location.href.split('#')[0].replace(/([?&])kep=(new|old)&?/, '$1').replace(/[?&]$/, '');
			var title = document.title;
			if (navigator.share) {
				navigator.share({ title: title, url: url }).catch(function () {});
				return;
			}
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(function () {
					say('Ссылка скопирована');
				}, function () {
					say(url);
				});
				return;
			}
			say(url);
		});
	});
}());
