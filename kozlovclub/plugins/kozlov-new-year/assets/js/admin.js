/* Kozlov New Year: редактор в админке. */
(function () {
'use strict';
var doc = document, cfg = window.KNY_ADMIN || {};
var form = doc.getElementById('kny-form');
if (!form) return;
var dirty = false, dirtyEl = doc.querySelector('.kny-dirty'), bar = doc.querySelector('.kny-bar');

function setDirty(v) { dirty = v; if (dirtyEl) dirtyEl.hidden = !v; }
function imgUrl(v) { return v.indexOf('plugin:') === 0 ? cfg.img + encodeURIComponent(v.slice(7)) : v; }

/* ---------- Вкладки ---------- */
var tabs = [].slice.call(doc.querySelectorAll('.kny-tab'));
var panels = [].slice.call(doc.querySelectorAll('.kny-panel'));
function openTab(name) {
	if (!panels.some(function (p) { return p.getAttribute('data-panel') === name; })) name = tabs[0].getAttribute('data-tab');
	tabs.forEach(function (t) { var on = t.getAttribute('data-tab') === name; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', String(on)); });
	panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
	var aux = name === 'history' || name === 'settings';
	if (bar) bar.classList.toggle('is-aux', aux);
	doc.getElementById('kny-tab-field').value = name;
	if (history.replaceState) history.replaceState(null, '', '#' + name);
	autosizeAll();
}
tabs.forEach(function (t) { t.addEventListener('click', function () { openTab(t.getAttribute('data-tab')); }); });
openTab((location.hash || '').slice(1));

/* ---------- Сбор данных в JSON ---------- */
function ownFields(scope) {
	return [].slice.call(scope.querySelectorAll('[data-key]')).filter(function (el) { return el.parentElement.closest('[data-scope]') === scope; });
}
function collect(scope) {
	var out = {};
	ownFields(scope).forEach(function (el) {
		var key = el.getAttribute('data-key'), type = el.getAttribute('data-type');
		if (type === 'repeater') {
			var list = el.querySelector('.kny-items');
			out[key] = [].slice.call(list.children).filter(function (c) { return c.classList.contains('kny-item'); }).map(collect);
			return;
		}
		if (type === 'radio') {
			var on = el.querySelector('.kny-control:checked');
			out[key] = on ? on.value : '';
			return;
		}
		var ctl = el.querySelector('.kny-control');
		if (!ctl) return;
		out[key] = type === 'checkbox' ? ctl.checked : ctl.value;
	});
	return out;
}
function serialize() {
	var data = {};
	doc.querySelectorAll('.kny-panel[data-section]').forEach(function (p) { data[p.getAttribute('data-section')] = collect(p); });
	return JSON.stringify(data);
}
form.addEventListener('submit', function () {
	doc.getElementById('kny-json').value = serialize();
	setDirty(false);
	var btn = doc.querySelector('.kny-save-btn');
	if (btn) { btn.disabled = true; btn.textContent = 'Сохраняю…'; }
});
var previewBtn = doc.querySelector('.kny-preview-btn');
if (previewBtn) previewBtn.addEventListener('click', function () {
	var pf = doc.getElementById('kny-preview-form');
	pf.querySelector('[name="kny_json"]').value = serialize();
	pf.submit();
});
window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
doc.addEventListener('keydown', function (e) {
	if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'ы')) { e.preventDefault(); if (form.requestSubmit) form.requestSubmit(); else form.submit(); }
});

/* ---------- Повторители ---------- */
function itemTitle(item) {
	var list = item.parentElement, keys = (list.getAttribute('data-title') || '').split(',').filter(Boolean), parts = [];
	ownFields(item).forEach(function (el) {
		if (keys.indexOf(el.getAttribute('data-key')) < 0) return;
		var ctl = el.querySelector('.kny-control');
		if (ctl && ctl.value.trim()) parts[keys.indexOf(el.getAttribute('data-key'))] = ctl.value.trim();
	});
	var hidden = item.querySelector('[data-key="hidden"] .kny-control');
	var t = parts.filter(Boolean).join(' · ') || 'Без названия';
	var target = itemTarget(item);
	item.classList.toggle('is-hidden-item', !!(hidden && hidden.checked));
	return t + (target ? '   →  ' + target : '') + (hidden && hidden.checked ? '  (скрыт)' : '');
}
/* Куда на самом деле поведёт кнопка: та же логика, что на сайте. */
function itemTarget(item) {
	var act = item.querySelector('[data-key="action"] .kny-control:checked');
	if (!act) return '';
	var url = item.querySelector('[data-key="url"] .kny-control');
	var hasUrl = !!(url && url.value.trim());
	return act.value === 'book_phone' || !hasUrl ? 'кнопка звонит' : 'кнопка ведёт на страницу концерта';
}
function updateHint(item) {
	var hint = item.querySelector('[data-key="action"] .kny-hint');
	if (!hint) return;
	var act = item.querySelector('[data-key="action"] .kny-control:checked');
	var url = item.querySelector('[data-key="url"] .kny-control');
	var hasUrl = !!(url && url.value.trim()), msg = '';
	if (act && act.value !== 'book_phone' && !hasUrl) msg = 'Ссылка на концерт не заполнена, поэтому кнопка будет звонить в клуб.';
	else if (act && act.value === 'book_phone' && hasUrl) msg = 'Сейчас кнопка звонит в клуб. Чтобы она вела на страницу концерта, выберите «Забронировать → страница концерта».';
	hint.textContent = msg;
	hint.hidden = !msg;
}
/* У каждой карточки свои радиокнопки: при копировании и добавлении даём группе новое имя. */
var radioSeq = 0;
function uniqueRadios(node) {
	var map = {};
	[].forEach.call(node.querySelectorAll('input[type=radio]'), function (r) {
		if (!map[r.name]) map[r.name] = 'kny-rn' + Date.now().toString(36) + (++radioSeq);
		r.name = map[r.name];
	});
}
function refreshList(list) {
	var items = [].slice.call(list.children).filter(function (c) { return c.classList.contains('kny-item'); });
	items.forEach(function (it) { var t = it.querySelector('.kny-item-title'); if (t) t.textContent = itemTitle(it); updateHint(it); });
	var rep = list.closest('.kny-t-repeater'), cnt = rep && rep.querySelector('.kny-count');
	if (cnt) cnt.textContent = '(' + items.length + ')';
	var add = rep && rep.querySelector(':scope > .kny-add'), max = parseInt(list.getAttribute('data-max') || '50', 10);
	if (add) add.disabled = items.length >= max;
}
doc.querySelectorAll('.kny-items').forEach(refreshList);

doc.addEventListener('click', function (e) {
	var t = e.target;
	var item = t.closest('.kny-item');
	if (t.closest('.kny-toggle') && item) {
		var closed = item.classList.toggle('is-closed');
		t.closest('.kny-toggle').setAttribute('aria-expanded', String(!closed));
		if (!closed) autosize(item);
		return;
	}
	if (t.closest('.kny-expand-all')) {
		var rep0 = t.closest('.kny-t-repeater'), its = rep0.querySelectorAll('.kny-items > .kny-item'), anyClosed = [].some.call(its, function (i) { return i.classList.contains('is-closed'); });
		[].forEach.call(its, function (i) { i.classList.toggle('is-closed', !anyClosed); });
		t.textContent = anyClosed ? 'Свернуть все' : 'Развернуть все';
		autosize(rep0);
		return;
	}
	if (t.closest('.kny-add')) {
		var rep = t.closest('.kny-t-repeater'), list = rep.querySelector('.kny-items'), tpl = rep.querySelector(':scope > .kny-tpl');
		var node = tpl.content.firstElementChild.cloneNode(true);
		uniqueRadios(node);
		list.appendChild(node); refreshList(list); setDirty(true);
		var first = node.querySelector('.kny-control'); if (first) first.focus();
		node.scrollIntoView({ block: 'center', behavior: 'smooth' });
		return;
	}
	if (!item) return;
	var lst = item.parentElement;
	if (t.closest('.kny-up') && item.previousElementSibling) { lst.insertBefore(item, item.previousElementSibling); refreshList(lst); setDirty(true); }
	else if (t.closest('.kny-down') && item.nextElementSibling) { lst.insertBefore(item.nextElementSibling, item); refreshList(lst); setDirty(true); }
	else if (t.closest('.kny-copy')) {
		var copy = item.cloneNode(true);
		uniqueRadios(copy);
		// cloneNode не копирует текущее значение полей, переносим вручную.
		var src = item.querySelectorAll('.kny-control'), dst = copy.querySelectorAll('.kny-control');
		[].forEach.call(src, function (s, i) { if (s.type === 'checkbox' || s.type === 'radio') dst[i].checked = s.checked; else dst[i].value = s.value; });
		lst.insertBefore(copy, item.nextSibling); refreshList(lst); setDirty(true);
	}
	else if (t.closest('.kny-del')) {
		if (confirm('Удалить «' + itemTitle(item) + '»?')) { item.remove(); refreshList(lst); setDirty(true); }
	}
});

/* ---------- Картинки ---------- */
function setImage(box, value) {
	var input = box.querySelector('.kny-image-url'), thumb = box.querySelector('.kny-thumb');
	input.value = value;
	var name = box.querySelector('.kny-image-name');
	if (name) name.textContent = !value ? 'Картинка не выбрана' : value.indexOf('plugin:') === 0 ? 'Картинка из макета' : 'Из медиатеки: ' + decodeURIComponent(value.split('?')[0].split('/').pop());
	thumb.innerHTML = '';
	if (value) { var img = doc.createElement('img'); img.src = imgUrl(value); img.alt = ''; thumb.appendChild(img); }
	else { var s = doc.createElement('span'); s.textContent = 'Нет картинки'; thumb.appendChild(s); }
	box.classList.toggle('is-empty', !value);
	setDirty(true);
}
doc.addEventListener('click', function (e) {
	var t = e.target, box = t.closest('.kny-image');
	if (!box) return;
	if (t.closest('.kny-pick')) {
		if (!window.wp || !wp.media) { alert('Медиатека недоступна. Вставьте адрес картинки в поле.'); return; }
		var frame = wp.media({ title: 'Выберите картинку', library: { type: 'image' }, button: { text: 'Поставить' }, multiple: false });
		frame.on('select', function () { var a = frame.state().get('selection').first().toJSON(); setImage(box, a.url); });
		frame.open();
	} else if (t.closest('.kny-restore-img')) {
		setImage(box, t.closest('.kny-restore-img').getAttribute('data-default'));
	} else if (t.closest('.kny-clear-img')) {
		setImage(box, '');
	}
});
doc.addEventListener('change', function (e) {
	if (e.target.classList && e.target.classList.contains('kny-image-url')) setImage(e.target.closest('.kny-image'), e.target.value.trim());
});

/* ---------- Мелочи: счётчики символов, высота полей, заголовки карточек ---------- */
function counter(ctl) {
	var max = parseInt(ctl.getAttribute('data-counter'), 10), el = ctl.parentElement.querySelector('.kny-counter');
	if (!el) { el = doc.createElement('span'); el.className = 'kny-counter'; ctl.parentElement.querySelector('label').appendChild(el); }
	var n = ctl.value.length; el.textContent = n + ' / ' + max; el.classList.toggle('is-over', n > max);
}
doc.querySelectorAll('[data-counter]').forEach(counter);
function autosize(root) {
	[].forEach.call((root || doc).querySelectorAll('textarea.kny-control'), function (ta) {
		if (!ta.offsetParent) return;
		ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight + 2, 520) + 'px';
	});
}
function autosizeAll() { requestAnimationFrame(function () { autosize(doc.querySelector('.kny-panel:not([hidden])')); }); }
doc.addEventListener('input', function (e) {
	var t = e.target;
	if (!t.classList || !t.classList.contains('kny-control')) return;
	setDirty(true);
	if (t.hasAttribute('data-counter')) counter(t);
	if (t.tagName === 'TEXTAREA') { t.style.height = 'auto'; t.style.height = Math.min(t.scrollHeight + 2, 520) + 'px'; }
	var item = t.closest('.kny-item');
	if (item) { var tt = item.querySelector('.kny-item-title'); if (tt) tt.textContent = itemTitle(item); updateHint(item); }
});
doc.addEventListener('change', function (e) {
	var t = e.target;
	if (!t.classList || !t.classList.contains('kny-control')) return;
	setDirty(true);
	var item = t.closest('.kny-item');
	if (item) { var tt = item.querySelector('.kny-item-title'); if (tt) tt.textContent = itemTitle(item); updateHint(item); }
});
})();
