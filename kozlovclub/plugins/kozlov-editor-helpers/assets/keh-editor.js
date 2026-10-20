/* Kozlov Editor Helpers 1.1.0: помощь в редакторе анонса.
 * 1) Автоподстановка Ticketscloud ID. 2) Часовой пояс UTC для новых событий.
 * Без jQuery и глобальных переменных. Любая ошибка гасится: редактор работает как раньше. */
(function () {
	'use strict';

	var NAME_RE = /^magicfields\[([^\]]+)\]\[(\d+)\]\[(\d+)\]$/;
	var cfg = null;

	function config() {
		if (cfg !== null) { return cfg; }
		cfg = false;
		try {
			var el = document.getElementById('keh-config');
			var c = el ? JSON.parse(el.textContent || '{}') : null;
			if (c && typeof c === 'object') {
				var tc = c.tc;
				if (!(tc && tc.codeField && tc.idField && typeof tc.defaultId === 'string' && tc.defaultId !== '')) {
					c.tc = null;
				}
				if (!(c.tz && typeof c.tz === 'object')) { c.tz = null; }
				cfg = c;
			}
		} catch (e) { cfg = false; }
		return cfg;
	}

	/* ---------- Общая подсказка под полем ---------- */

	function clearNote(box) {
		var n = box ? box.querySelector('.keh-note') : null;
		if (n) { n.parentNode.removeChild(n); }
	}

	function note(box, text, opts) {
		if (!box) { return; }
		opts = opts || {};
		clearNote(box);
		var n = document.createElement('div');
		n.className = 'keh-note';
		n.style.cssText = 'margin:4px 0 0;font-size:12px;line-height:1.4;color:' + (opts.warn ? '#b32d2e' : '#2271b1') + ';';
		n.textContent = text;
		if (opts.button) {
			n.appendChild(document.createTextNode(' '));
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'button-link ' + (opts.button.cls || '');
			b.textContent = opts.button.text;
			b.addEventListener('click', opts.button.onClick);
			n.appendChild(b);
		}
		if (opts.link) {
			n.appendChild(document.createTextNode(' '));
			var a = document.createElement('a');
			a.href = opts.link;
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = 'Изменить стандартное';
			n.appendChild(a);
		}
		box.appendChild(n);
	}

	/* ---------- 1. Ticketscloud ID ---------- */

	function parse(el) {
		if (!el || !el.name || (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA')) { return null; }
		var m = NAME_RE.exec(el.name);
		return m ? { field: m[1], group: m[2], index: m[3] } : null;
	}

	// Поля одной группы Magic Fields. Перебор вместо селекторов с квадратными скобками в имени.
	function inGroup(field, group) {
		var all = document.querySelectorAll('input[name^="magicfields"], textarea[name^="magicfields"]');
		var out = [];
		for (var i = 0; i < all.length; i++) {
			var q = parse(all[i]);
			if (q && q.field === field && q.group === group) { out.push({ el: all[i], index: q.index }); }
		}
		return out;
	}

	// Только однозначная пара: тот же номер группы и поля,
	// либо в группе ровно одно поле кода и ровно одно поле ID. Иначе ничего не трогаем.
	function partnerId(tc, p) {
		var ids = inGroup(tc.idField, p.group);
		for (var i = 0; i < ids.length; i++) {
			if (ids[i].index === p.index) { return ids[i].el; }
		}
		var codes = inGroup(tc.codeField, p.group);
		return (ids.length === 1 && codes.length === 1) ? ids[0].el : null;
	}

	function onCodeChange(tc, codeEl, p) {
		var idEl = partnerId(tc, p);
		if (!idEl) { return; }
		var box = idEl.parentNode;
		var code = (codeEl.value || '').trim();
		var id = (idEl.value || '').trim();

		if (code !== '') {
			if (id === '') {
				idEl.value = tc.defaultId;
				idEl.setAttribute('data-keh-auto', '1');
				note(box, 'Ticketscloud ID подставлен автоматически, его можно изменить.', { link: tc.settingsUrl || '' });
			} else if (idEl.getAttribute('data-keh-auto') !== '1') {
				clearNote(box); // код вернули: предупреждение больше не нужно
			}
			return;
		}

		// Код стёрт.
		if (idEl.getAttribute('data-keh-auto') === '1' && id === tc.defaultId) {
			// Значение подставил сам плагин в этом сеансе: убираем его.
			idEl.value = '';
			idEl.removeAttribute('data-keh-auto');
			note(box, 'Код события удалён, подставленный ID убран.');
		} else if (id !== '') {
			// Значение было до нас: не трогаем, только предупреждаем.
			note(box, 'Код события пуст, а ID заполнен: у бесплатного или депозитного концерта может не показаться его блок.', {
				warn: true,
				button: { text: 'Очистить ID', cls: 'keh-clear', onClick: function () {
					idEl.value = '';
					idEl.removeAttribute('data-keh-auto');
					clearNote(box);
				} }
			});
		}
	}

	function onInput(ev) {
		try {
			var c = config();
			if (!c || !c.tc) { return; }
			var t = ev.target;
			var p = parse(t);
			if (!p) { return; }
			if (p.field === c.tc.codeField) {
				onCodeChange(c.tc, t, p);
			} else if (p.field === c.tc.idField) {
				// Редактор сам правит ID: значение уже не «наше», подсказка не нужна.
				t.removeAttribute('data-keh-auto');
				clearNote(t.parentNode);
			}
		} catch (e) { /* молча: штатная работа редактора важнее */ }
	}

	document.addEventListener('input', onInput, false);
	document.addEventListener('change', onInput, false);

	/* ---------- 2. Часовой пояс UTC ---------- */

	var TZ_NAME = 'ai1ec_timezone_name';
	var UTC = 'UTC';

	function tzSelect() {
		var sel = document.querySelector('select[name="' + TZ_NAME + '"]');
		if (!sel) { return null; }
		for (var i = 0; i < sel.options.length; i++) {
			if (sel.options[i].value === UTC) { return sel; }
		}
		return null; // нет пункта UTC: другая версия календаря, ничего не делаем
	}

	function setUtc(sel) {
		sel.value = UTC;
		sel.setAttribute('data-keh-tz', '1');
		// Событие нужно, чтобы выпадающий список календаря (select2) показал новое значение.
		sel.dispatchEvent(new Event('change', { bubbles: true }));
		sel.removeAttribute('data-keh-tz');
	}

	function tzInit() {
		try {
			var c = config();
			if (!c || !c.tz) { return; }
			var sel = tzSelect();
			if (!sel) { return; }
			var box = sel.parentNode;

			// Ручной выбор пояса редактором убирает подсказку.
			sel.addEventListener('change', function () {
				if (sel.getAttribute('data-keh-tz') !== '1') { clearNote(box); }
			});

			if (sel.value === UTC) { return; }

			if (c.tz.isNew) {
				setUtc(sel);
				note(box, 'Часовой пояс выставлен автоматически: UTC (пустая строка в списке). Так время на сайте покажется правильно.');
			} else {
				note(box, 'Пояс этого события не UTC, поэтому на сайте время может показываться со сдвигом.', {
					warn: true,
					button: { text: 'Поставить UTC', cls: 'keh-tz-fix', onClick: function () {
						setUtc(sel);
						note(box, 'Пояс UTC выставлен. Проверьте время начала и окончания и сохраните событие.');
					} }
				});
			}
		} catch (e) { /* молча */ }
	}

	// Скрипт подключён в подвале страницы: поле пояса уже есть в документе.
	tzInit();
}());
