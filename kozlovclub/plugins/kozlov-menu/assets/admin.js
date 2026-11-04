/* Редактор блюд раздела меню. Данные в скрытом поле kzm_items (JSON). */
jQuery(function ($) {
	var $json = $('[data-kzm-json]'), $list = $('[data-kzm-list]');
	if (!$json.length) { return; }
	var items = [];
	try { items = JSON.parse($json.val() || '[]') || []; } catch (e) { items = []; }

	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	function sync() { $json.val(JSON.stringify(items)); }
	function row(it, i) {
		var vars = (it.variants && it.variants.length ? it.variants : [{ size: '', price: '' }]).map(function (v, j) {
			return '<div class="kzm-var"><input type="text" placeholder="Объём или вес: 300 мл" value="' + esc(v.size) + '" data-v="size" data-j="' + j + '"><input type="text" class="kzm-price" placeholder="Цена, ₽" inputmode="numeric" value="' + esc(v.price) + '" data-v="price" data-j="' + j + '"><button type="button" class="button-link" data-act="delvar" data-j="' + j + '" title="Убрать строку">×</button></div>';
		}).join('');
		return '<div class="kzm-r' + (it.hidden ? ' is-hidden' : '') + '" data-i="' + i + '">' +
			'<div class="kzm-r-ph' + (it.thumb ? ' has' : '') + '" data-act="photo" style="' + (it.thumb ? 'background-image:url(' + esc(it.thumb) + ')' : '') + '">' + (it.thumb ? '' : 'Фото<br>(необязательно)') + '</div>' +
			'<div class="kzm-r-main"><input type="text" class="kzm-r-title" placeholder="Название" value="' + esc(it.title) + '" data-f="title">' +
			'<textarea rows="2" placeholder="Состав или описание" data-f="desc">' + esc(it.desc) + '</textarea>' +
			'<div class="kzm-vars">' + vars + '</div><div><button type="button" class="button-link" data-act="addvar">+ объём и цена</button> · <input type="text" placeholder="Калорийность" value="' + esc(it.kcal) + '" data-f="kcal" style="width:160px"></div></div>' +
			'<div class="kzm-r-side"><label class="kzm-r-sm"><input type="checkbox" data-f="hidden"' + (it.hidden ? ' checked' : '') + '> скрыть</label>' +
			'<span><button type="button" class="button" data-act="up" title="Выше">↑</button> <button type="button" class="button" data-act="down" title="Ниже">↓</button></span>' +
			'<button type="button" class="button-link" data-act="copy">копия</button>' +
			(it.image ? '<button type="button" class="button-link" data-act="nophoto">убрать фото</button>' : '') +
			'<button type="button" class="button-link" style="color:#b32d2e" data-act="del">удалить</button></div></div>';
	}
	function render() { $list.html(items.map(row).join('') || '<p class="description">В разделе пока нет позиций.</p>'); sync(); }

	$list.on('input change', '[data-f]', function () {
		var i = +$(this).closest('.kzm-r').data('i'), f = $(this).data('f');
		items[i][f] = this.type === 'checkbox' ? this.checked : this.value;
		if (f === 'hidden') { $(this).closest('.kzm-r').toggleClass('is-hidden', this.checked); }
		sync();
	});
	$list.on('input', '[data-v]', function () {
		var i = +$(this).closest('.kzm-r').data('i'), j = +$(this).data('j');
		items[i].variants = items[i].variants && items[i].variants.length ? items[i].variants : [{ size: '', price: '' }];
		items[i].variants[j][$(this).data('v')] = this.value;
		sync();
	});
	$list.on('click', '[data-act]', function () {
		var $r = $(this).closest('.kzm-r'), i = +$r.data('i'), act = $(this).data('act');
		if (act === 'del') { if (window.confirm('Удалить «' + (items[i].title || 'позицию') + '»?')) { items.splice(i, 1); render(); } return; }
		if (act === 'copy') { items.splice(i + 1, 0, JSON.parse(JSON.stringify(items[i]))); render(); return; }
		if (act === 'up' && i > 0) { items.splice(i - 1, 0, items.splice(i, 1)[0]); render(); return; }
		if (act === 'down' && i < items.length - 1) { items.splice(i + 1, 0, items.splice(i, 1)[0]); render(); return; }
		if (act === 'addvar') { items[i].variants = (items[i].variants || []).concat([{ size: '', price: '' }]); render(); return; }
		if (act === 'delvar') { items[i].variants.splice(+$(this).data('j'), 1); render(); return; }
		if (act === 'nophoto') { items[i].image = 0; items[i].thumb = ''; render(); return; }
		if (act === 'photo') {
			var frame = wp.media({ title: 'Фото позиции', button: { text: 'Выбрать' }, library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				items[i].image = a.id; items[i].thumb = (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url); render();
			});
			frame.open();
		}
	});
	$('[data-kzm-add]').on('click', function () { items.push({ title: '', desc: '', image: 0, variants: [{ size: '', price: '' }], kcal: '', hidden: false }); render(); $list.find('.kzm-r-title').last().trigger('focus'); });
	render();
});
