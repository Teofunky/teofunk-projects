/* Kozlov Event Page: поведение новой страницы концерта. Без jQuery и глобальных переменных. */
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

	// Длинный текст «О концерте» на телефоне сворачивается. Текст весь в коде страницы.
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
