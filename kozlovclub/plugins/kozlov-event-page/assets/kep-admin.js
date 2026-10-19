/* Kozlov Event Page: блок «Новая страница концерта» в анонсе. Всё сохраняется сразу. */
(function () {
	'use strict';

	var box = document.getElementById('kep-box');
	var texts = document.getElementById('kep-texts');
	if (!box && !texts) {
		return;
	}
	var host = box || texts;
	var postId = host.getAttribute('data-post');
	var nonce = host.getAttribute('data-nonce');
	var q = function (s) { return box ? box.querySelector(s) : null; };
	var status = q('[data-kep-status]');
	var frameEl = q('[data-kep-frame]');
	var check = q('[data-kep-check]');
	var pick = q('[data-kep-pick]');
	var removeBtn = q('[data-kep-remove]');
	var heroInput = q('[data-kep-hero]');
	var program = q('#kep-program');
	var suggest = q('[data-kep-suggest]');
	var saved = q('[data-kep-saved]');
	var mediaFrame = null;
	var timer = null;
	var queue = Promise.resolve();
	var seqs = {};
	var textSaved = texts ? texts.querySelector('[data-kep-saved]') : null;
	var DONE = {
		program: 'Название программы сохранено.',
		lead: 'Текст сверху сохранён.',
		notes: 'Сноски сохранены.'
	};

	// Те же правила, что в PHP (KEP_Data::check_size).
	function localCheck(w, h) {
		w = +w || 0;
		h = +h || 0;
		if (!w || !h) {
			return { level: 'warn', text: 'Не удалось узнать размер фото.' };
		}
		if (h > w) {
			return { level: 'bad', text: 'Вертикальное фото ' + w + '×' + h + ': сверху и снизу обрежется много. Нужно горизонтальное.' };
		}
		if (w < 1600 || h < 1000) {
			return { level: 'warn', text: 'Маловато: ' + w + '×' + h + '. На компьютере будет нечётким, нужно от 1600×1000.' };
		}
		if (Math.abs(w / h - 1.6) / 1.6 > 0.04) {
			return { level: 'warn', text: w + '×' + h + ', пропорции не 16:10: края обрежутся. Что останется, видно в рамке.' };
		}
		return { level: 'ok', text: w + '×' + h + ', подходит.' };
	}

	function setFrame(url) {
		frameEl.textContent = '';
		if (url) {
			var img = document.createElement('img');
			img.src = url;
			img.alt = '';
			frameEl.appendChild(img);
			frameEl.classList.remove('is-empty');
		} else {
			frameEl.textContent = 'Фото нет';
			frameEl.classList.add('is-empty');
		}
	}

	function showCheck(c) {
		if (!c) {
			check.hidden = true;
			return;
		}
		check.hidden = false;
		check.className = 'kep-a-check kep-a-check--' + c.level;
		check.textContent = c.text;
	}

	function apply(data) {
		if (!box) {
			return;
		}
		status.className = 'kep-a-status kep-a-status--' + data.status.level;
		status.textContent = data.status.text;
		setFrame(data.img);
		showCheck(data.check);
		heroInput.value = data.hero ? String(data.hero) : '';
		pick.textContent = data.hero ? 'Заменить фото' : 'Загрузить фото';
		removeBtn.hidden = !data.hero;
	}

	// Сохранения идут строго по очереди: старый ответ не перезапишет новое значение.
	function send(field, value, seq) {
		queue = queue.then(function () {
			if (seq && seq !== seqs[field]) {
				return null; // пока ждали, введено новое значение: сохранится оно
			}
			return post(field, value);
		});
		return queue;
	}

	function post(field, value) {
		var out = (field === 'lead' || field === 'notes') ? textSaved : saved;
		var fd = new FormData();
		fd.append('action', 'kep_save');
		fd.append('post_id', postId);
		fd.append('nonce', nonce);
		fd.append('field', field);
		fd.append('value', value);
		out.className = 'kep-a-saved';
		out.textContent = 'Сохраняю…';
		return fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) {
					throw new Error(res && res.data && res.data.message ? res.data.message : 'ошибка сервера');
				}
				apply(res.data);
				out.className = 'kep-a-saved is-ok';
				out.textContent = field === 'hero'
					? (res.data.hero ? 'Фото сохранено.' : 'Фото убрано, страница снова старая.')
					: DONE[field];
			})
			.catch(function (e) {
				out.className = 'kep-a-saved is-bad';
				out.textContent = 'Не сохранилось (' + e.message + '). Обновите страницу и попробуйте ещё раз.';
			});
	}

	// Поля «Коротко о концерте» и «Важно знать»: сохраняются через секунду после последней правки.
	if (texts) {
		Array.prototype.forEach.call(texts.querySelectorAll('[data-kep-field]'), function (el) {
			var field = el.getAttribute('data-kep-field');
			var counter = texts.querySelector('[data-kep-count="' + field + '"]');
			var t = null;
			el.addEventListener('input', function () {
				if (counter) {
					counter.textContent = String(el.value.length);
				}
				clearTimeout(t);
				textSaved.className = 'kep-a-saved';
				textSaved.textContent = '';
				var seq = seqs[field] = (seqs[field] || 0) + 1;
				t = setTimeout(function () { send(field, el.value, seq); }, 900);
			});
		});
	}

	if (!box) {
		return;
	}

	pick.addEventListener('click', function (e) {
		e.preventDefault();
		if (!window.wp || !window.wp.media) {
			saved.className = 'kep-a-saved is-bad';
			saved.textContent = 'Медиатека не загрузилась. Обновите страницу.';
			return;
		}
		if (!mediaFrame) {
			mediaFrame = window.wp.media({
				title: 'Заглавное фото для новой страницы',
				button: { text: 'Поставить наверх страницы' },
				library: { type: 'image' },
				multiple: false
			});
			mediaFrame.on('open', function () {
				if (mediaFrame.content && mediaFrame.content.mode) {
					mediaFrame.content.mode('browse');
				}
			});
			mediaFrame.on('select', function () {
				var a = mediaFrame.state().get('selection').first().toJSON();
				setFrame(a.sizes && a.sizes.large ? a.sizes.large.url : a.url);
				showCheck(localCheck(a.width, a.height));
				send('hero', a.id);
			});
		}
		mediaFrame.open();
	});

	removeBtn.addEventListener('click', function (e) {
		e.preventDefault();
		if (window.confirm('Убрать заглавное фото? Концерт снова откроется в старом виде.')) {
			send('hero', 0);
		}
	});

	program.addEventListener('input', function () {
		clearTimeout(timer);
		saved.className = 'kep-a-saved';
		saved.textContent = '';
		var seq = seqs.program = (seqs.program || 0) + 1;
		timer = setTimeout(function () { send('program', program.value, seq); }, 700);
	});

	if (suggest) {
		suggest.addEventListener('click', function (e) {
			e.preventDefault();
			program.value = suggest.getAttribute('data-kep-suggest');
			clearTimeout(timer);
			seqs.program = (seqs.program || 0) + 1;
			send('program', program.value, seqs.program);
		});
	}
}());
