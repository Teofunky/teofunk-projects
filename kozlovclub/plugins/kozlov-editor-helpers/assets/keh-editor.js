/* Kozlov Editor Helpers 1.0.2: автоподстановка Ticketscloud ID в редакторе анонса.
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
			if (c && c.codeField && c.idField && typeof c.defaultId === 'string' && c.defaultId !== '') {
				cfg = c;
			}
		} catch (e) { cfg = false; }
		return cfg;
	}

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
	function partnerId(c, p) {
		var ids = inGroup(c.idField, p.group);
		for (var i = 0; i < ids.length; i++) {
			if (ids[i].index === p.index) { return ids[i].el; }
		}
		var codes = inGroup(c.codeField, p.group);
		return (ids.length === 1 && codes.length === 1) ? ids[0].el : null;
	}

	function clearNote(idEl) {
		var box = idEl.parentNode;
		var n = box ? box.querySelector('.keh-note') : null;
		if (n) { n.parentNode.removeChild(n); }
	}

	function note(idEl, text, c, opts) {
		var box = idEl.parentNode;
		if (!box) { return; }
		clearNote(idEl);
		var n = document.createElement('div');
		n.className = 'keh-note';
		n.style.cssText = 'margin:4px 0 0;font-size:12px;line-height:1.4;color:' + (opts && opts.warn ? '#b32d2e' : '#2271b1') + ';';
		n.textContent = text;
		if (opts && opts.clearButton) {
			n.appendChild(document.createTextNode(' '));
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'button-link keh-clear';
			b.textContent = 'Очистить ID';
			b.addEventListener('click', function () {
				idEl.value = '';
				idEl.removeAttribute('data-keh-auto');
				clearNote(idEl);
			});
			n.appendChild(b);
		}
		if (opts && opts.settingsLink && c.settingsUrl) {
			n.appendChild(document.createTextNode(' '));
			var a = document.createElement('a');
			a.href = c.settingsUrl;
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = 'Изменить стандартное';
			n.appendChild(a);
		}
		box.appendChild(n);
	}

	function onCodeChange(c, codeEl, p) {
		var idEl = partnerId(c, p);
		if (!idEl) { return; }
		var code = (codeEl.value || '').trim();
		var id = (idEl.value || '').trim();

		if (code !== '') {
			if (id === '') {
				idEl.value = c.defaultId;
				idEl.setAttribute('data-keh-auto', '1');
				note(idEl, 'Ticketscloud ID подставлен автоматически, его можно изменить.', c, { settingsLink: true });
			} else if (idEl.getAttribute('data-keh-auto') !== '1') {
				clearNote(idEl); // код вернули: предупреждение больше не нужно
			}
			return;
		}

		// Код стёрт.
		if (idEl.getAttribute('data-keh-auto') === '1' && id === c.defaultId) {
			// Значение подставил сам плагин в этом сеансе: убираем его.
			idEl.value = '';
			idEl.removeAttribute('data-keh-auto');
			note(idEl, 'Код события удалён, подставленный ID убран.', c);
		} else if (id !== '') {
			// Значение было до нас: не трогаем, только предупреждаем.
			note(idEl, 'Код события пуст, а ID заполнен: у бесплатного или депозитного концерта может не показаться его блок.', c, { warn: true, clearButton: true });
		}
	}

	function handler(ev) {
		try {
			var c = config();
			if (!c) { return; }
			var t = ev.target;
			var p = parse(t);
			if (!p) { return; }
			if (p.field === c.codeField) {
				onCodeChange(c, t, p);
			} else if (p.field === c.idField) {
				// Редактор сам правит ID: значение уже не «наше», подсказка не нужна.
				t.removeAttribute('data-keh-auto');
				clearNote(t);
			}
		} catch (e) { /* молча: штатная работа редактора важнее */ }
	}

	document.addEventListener('input', handler, false);
	document.addEventListener('change', handler, false);
}());
