/* Kozlov Editor Helpers 1.0.0: автоподстановка Ticketscloud ID в редакторе анонса.
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

	function byName(field, group, index) {
		return document.querySelector('[name="magicfields[' + field + '][' + group + '][' + index + ']"]');
	}

	// Пара для кода: ID с тем же номером группы и поля, затем первый ID в этой группе, затем первый ID вообще.
	function partnerId(c, p) {
		var el = byName(c.idField, p.group, p.index) || byName(c.idField, p.group, '1');
		if (el) { return el; }
		var all = document.querySelectorAll('[name^="magicfields[' + c.idField + ']["]');
		for (var i = 0; i < all.length; i++) {
			var q = parse(all[i]);
			if (q && q.group === p.group) { return all[i]; }
		}
		return all.length ? all[0] : null;
	}

	function note(idEl, text, c) {
		var box = idEl.parentNode;
		if (!box) { return; }
		var n = box.querySelector('.keh-note');
		if (!text) {
			if (n) { n.parentNode.removeChild(n); }
			return;
		}
		if (!n) {
			n = document.createElement('div');
			n.className = 'keh-note';
			n.style.cssText = 'margin:4px 0 0;font-size:12px;line-height:1.4;color:#2271b1;';
			box.appendChild(n);
		}
		n.textContent = text;
		if (c.settingsUrl) {
			n.appendChild(document.createTextNode(' '));
			var a = document.createElement('a');
			a.href = c.settingsUrl;
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = 'Изменить стандартное';
			n.appendChild(a);
		}
	}

	function onCodeChange(c, codeEl, p) {
		var idEl = partnerId(c, p);
		if (!idEl) { return; }
		var code = (codeEl.value || '').trim();
		var id = (idEl.value || '').trim();

		if (code !== '' && id === '') {
			idEl.value = c.defaultId;
			idEl.setAttribute('data-keh-auto', '1');
			note(idEl, 'Ticketscloud ID подставлен автоматически, его можно изменить.', c);
		} else if (code === '' && id === c.defaultId) {
			idEl.value = '';
			idEl.removeAttribute('data-keh-auto');
			note(idEl, 'Код события удалён, поэтому ID тоже очищен.', c);
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
				// Редактор сам правит ID: подсказка больше не нужна.
				t.removeAttribute('data-keh-auto');
				note(t, '', c);
			}
		} catch (e) { /* молча: штатная работа редактора важнее */ }
	}

	document.addEventListener('input', handler, false);
	document.addEventListener('change', handler, false);
}());
