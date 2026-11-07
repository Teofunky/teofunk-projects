/* Kozlov TV: экран, мини-плеер, программа, записи, поиск и жанры, проверка эфира. Без jQuery и глобальных переменных. */
(function () {
	'use strict';

	var root = document.querySelector('.kzv-tv[data-kzv]');
	if (!root || !window.fetch) {
		return;
	}
	var cfg;
	try {
		cfg = JSON.parse(root.getAttribute('data-kzv'));
	} catch (e) {
		return;
	}
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var $ = function (sel, ctx) { return (ctx || root).querySelector(sel); };

	function api(path, params) {
		var u = new URL(cfg.rest + path, window.location.href);
		Object.keys(params || {}).forEach(function (k) {
			if (params[k] !== '' && params[k] !== null && params[k] !== undefined) {
				u.searchParams.set(k, params[k]);
			}
		});
		var opts = { credentials: 'same-origin', headers: {} };
		if (cfg.nonce) {
			opts.headers['X-WP-Nonce'] = cfg.nonce;
		}
		return fetch(u.toString(), opts).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		});
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text) { n.textContent = text; }
		return n;
	}

	// обложка не загрузилась: остаётся аккуратный тёмный фон
	root.addEventListener('error', function (e) {
		if (e.target && e.target.tagName === 'IMG') {
			e.target.style.visibility = 'hidden';
		}
	}, true);

	/* ---------- уведомление ---------- */

	var toastBox = $('[data-kzv-toast]');
	var toastTimer = null;
	function toast(text, action) {
		if (!toastBox) { return; }
		toastBox.innerHTML = '';
		toastBox.appendChild(el('span', '', text));
		if (action) {
			var b = el('button', 'kzv-btn', action.label);
			b.type = 'button';
			b.addEventListener('click', function () { hideToast(); action.run(); });
			toastBox.appendChild(b);
		}
		toastBox.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(hideToast, action ? 12000 : 3000);
	}
	function hideToast() {
		if (toastBox) { toastBox.hidden = true; }
	}

	/* ---------- экран ---------- */

	var box = $('.kzv-screen-box');
	var screen = $('[data-kzv-screen]');
	var dockTitle = $('[data-kzv-dock-title]');
	var items = {};   // id → данные карточки (для «Назад/Вперёд»)
	var initial = null;

	function iframe(src, title) {
		var f = document.createElement('iframe');
		f.src = src;
		f.title = title || 'Видео';
		f.setAttribute('allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture; screen-wake-lock');
		f.setAttribute('allowfullscreen', '');
		return f;
	}

	function playing() {
		return !!(screen && screen.querySelector('iframe'));
	}

	function setScreen(src, title, poster, autoplay) {
		if (!screen) { return; }
		var bar = screen.querySelector('.kzv-dock-bar');
		Array.prototype.slice.call(screen.children).forEach(function (c) {
			if (c !== bar) { screen.removeChild(c); }
		});
		screen.setAttribute('data-src', src || '');
		var node;
		if (autoplay && src) {
			node = iframe(src, title);
		} else {
			node = el('button', 'kzv-poster');
			node.type = 'button';
			node.setAttribute('data-kzv-play', '');
			node.setAttribute('aria-label', 'Смотреть: ' + title);
			if (poster) {
				var img = el('img');
				img.src = poster;
				img.alt = '';
				node.appendChild(img);
			}
			node.appendChild(el('span', 'kzv-poster-shade'));
			var p = el('span', 'kzv-play');
			p.innerHTML = '<svg viewBox="0 0 24 24" width="28" height="28"><path d="M8 5.5v13l11-6.5z" fill="currentColor"/></svg>';
			node.appendChild(p);
		}
		screen.insertBefore(node, bar);
		root.classList.toggle('is-playing', !!(autoplay && src));
		if (dockTitle) { dockTitle.textContent = title || ''; }
		if (!autoplay) { undock(); }
	}

	function setNow(state, meta, title, item) {
		var chip = $('[data-kzv-state]');
		if (chip) {
			chip.textContent = state === 'live' ? 'В эфире' : 'Запись эфира';
			chip.classList.toggle('is-live', state === 'live');
		}
		var m = $('[data-kzv-meta]');
		if (m) { m.textContent = meta || ''; }
		var t = $('[data-kzv-title]');
		if (t) { t.textContent = title || ''; }
		var act = $('[data-kzv-actions]');
		if (act) {
			act.innerHTML = '';
			act.appendChild(actions(item || {}));
		}
	}

	function actions(item) {
		var f = document.createDocumentFragment();
		var a;
		if (item.up && item.up.url) {
			a = el('a', 'kzv-btn kzv-btn--accent', item.up.label);
			a.href = item.up.url;
			f.appendChild(a);
		}
		if (item.concert) {
			a = el('a', 'kzv-btn', 'О концерте');
			a.href = item.concert;
			f.appendChild(a);
		}
		var s = el('button', 'kzv-btn kzv-btn--icon');
		s.type = 'button';
		s.setAttribute('data-kzv-share', '');
		s.setAttribute('data-url', item.href || window.location.href);
		s.setAttribute('aria-label', 'Поделиться');
		s.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18"><path d="M12 3v12M7 8l5-5 5 5M5 13v6h14v-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Поделиться</span>';
		f.appendChild(s);
		var links = [];
		if (item.vkWatch) { links.push(['VK Видео', item.vkWatch]); }
		if (item.yt) { links.push(['YouTube', item.yt]); }
		if (links.length) {
			var also = el('span', 'kzv-also', 'Смотреть в ');
			links.forEach(function (l, i) {
				if (i) { also.appendChild(document.createTextNode(' · ')); }
				var x = el('a', '', l[0]);
				x.href = l[1];
				x.target = '_blank';
				x.rel = 'noopener';
				also.appendChild(x);
			});
			f.appendChild(also);
		}
		return f;
	}

	function markActive(id) {
		root.querySelectorAll('.kzv-card[aria-current]').forEach(function (c) { c.removeAttribute('aria-current'); });
		if (!id) { return; }
		var c = root.querySelector('.kzv-card[data-kzv-id="' + id + '"]');
		if (c) { c.setAttribute('aria-current', 'true'); }
	}

	function showItem(item, autoplay) {
		items[item.id] = item;
		setScreen(item.vk, item.title, item.img, autoplay);
		setNow('rec', item.meta, item.title, item);
		screen.setAttribute('data-id', String(item.id));
		markActive(item.id);
	}

	/* ---------- фиксированная шапка темы ---------- */
	// шапка сайта position: fixed: липкая панель поиска, прокрутка к экрану и мини-плеер считаются от её низа
	var headTop = 0;
	function measureHead() {
		var t = 0;
		var el = document.elementFromPoint(Math.round(window.innerWidth / 2), 1);
		while (el && el !== document.body && el !== document.documentElement) {
			if (root.contains(el)) { break; }
			var cs = window.getComputedStyle(el);
			if (cs.position === 'fixed' || cs.position === 'sticky') {
				var r = el.getBoundingClientRect();
				if (r.top <= 1) { t = Math.max(0, Math.round(r.bottom)); }
				break;
			}
			el = el.parentElement;
		}
		if (t > window.innerHeight * 0.4) { t = 0; }
		if (t !== headTop) {
			headTop = t;
			root.style.setProperty('--kzv-top', t + 'px');
		}
	}

	function toScreen() {
		if (!box) { return; }
		var top = box.getBoundingClientRect().top;
		if (top < headTop || top > window.innerHeight * 0.5) {
			box.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
		}
	}

	// первичное состояние экрана для «Назад» на исходный адрес
	(function () {
		var t = $('[data-kzv-title]');
		var card = screen && screen.getAttribute('data-id') ? root.querySelector('.kzv-card[data-kzv-id="' + screen.getAttribute('data-id') + '"]') : null;
		if (card) {
			try { initial = JSON.parse(card.getAttribute('data-kzv-card')); } catch (e) { initial = null; }
		}
		if (!initial && t && screen && screen.getAttribute('data-id') === 'live') {
			initial = { live: true };
		}
		if (initial && initial.id) {
			items[initial.id] = initial;
			markActive(initial.id);
		}
		try { history.replaceState({ kzv: initial && initial.id ? initial.id : null }, ''); } catch (e) { /* без истории */ }
	})();

	root.addEventListener('click', function (e) {
		var play = e.target.closest('[data-kzv-play]');
		if (play && screen) {
			var src = screen.getAttribute('data-src');
			if (src) {
				var t = $('[data-kzv-title]');
				setScreen(src, t ? t.textContent : '', '', true);
			}
			return;
		}
		var card = e.target.closest('[data-kzv-card]');
		if (card && screen && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) {
			var item;
			try { item = JSON.parse(card.getAttribute('data-kzv-card')); } catch (err) { return; }
			if (!item.vk) { return; }
			e.preventDefault();
			showItem(item, true);
			toScreen();
			try { history.pushState({ kzv: item.id }, '', item.href); } catch (err) { /* адрес не меняем */ }
			return;
		}
		var share = e.target.closest('[data-kzv-share]');
		if (share) {
			var url = share.getAttribute('data-url') || window.location.href;
			var title = ($('[data-kzv-title]') || {}).textContent || document.title;
			if (navigator.share) {
				navigator.share({ title: title + ' · Kozlov TV', url: url }).catch(function () {});
			} else if (navigator.clipboard) {
				navigator.clipboard.writeText(url).then(function () { toast('Ссылка скопирована'); }, function () { toast(url); });
			} else {
				toast(url);
			}
			return;
		}
		if (e.target.closest('[data-kzv-undock]')) {
			undock(true);
			toScreen();
			return;
		}
		if (e.target.closest('[data-kzv-close]')) {
			var src2 = screen.getAttribute('data-src');
			var tt = ($('[data-kzv-title]') || {}).textContent || '';
			var id = screen.getAttribute('data-id');
			setScreen(src2, tt, id && items[id] ? items[id].img : '', false);
			return;
		}
		if (e.target.closest('[data-kzv-go-live]')) {
			e.preventDefault();
			goLive();
		}
	});

	window.addEventListener('popstate', function (e) {
		if (!screen) { return; }
		var id = e.state && e.state.kzv;
		var it = id ? items[id] : initial;
		if (it && it.id) {
			showItem(it, false); // без автозапуска: старое видео останавливается, на экране обложка нужной записи
		} else if (it && it.live) {
			goLive();
		}
	});

	/* ---------- мини-плеер ---------- */

	var docked = false;
	var undockedByUser = false;
	function dock() {
		if (docked || !playing()) { return; }
		docked = true;
		screen.classList.add('is-docked');
		box.classList.add('has-docked');
		var bar = screen.querySelector('.kzv-dock-bar');
		if (bar) { bar.removeAttribute('aria-hidden'); }
	}
	function undock(byUser) {
		if (!docked) { return; }
		docked = false;
		if (byUser) { undockedByUser = true; }
		screen.classList.remove('is-docked');
		box.classList.remove('has-docked');
		var bar = screen.querySelector('.kzv-dock-bar');
		if (bar) { bar.setAttribute('aria-hidden', 'true'); }
	}
	// экран почти ушёл под шапку: видео переезжает в мини-плеер; вернулся в поле зрения: обратно
	function checkDock() {
		if (!box || !screen) { return; }
		var r = box.getBoundingClientRect();
		if (r.bottom < headTop + 48) {
			if (playing() && !undockedByUser) { dock(); }
		} else if (r.bottom > headTop + 120 && r.top < window.innerHeight) {
			undock();
			undockedByUser = false;
		}
	}
	var frame = 0;
	function onScroll() {
		if (frame) { return; }
		frame = window.requestAnimationFrame(function () {
			frame = 0;
			measureHead();
			checkDock();
		});
	}
	measureHead();
	window.addEventListener('scroll', onScroll, { passive: true });
	window.addEventListener('resize', onScroll);

	/* ---------- записи: поиск, жанры, «Показать ещё» ---------- */

	var grid = $('[data-kzv-grid]');
	var moreWrap = $('[data-kzv-more-wrap]');
	var count = $('[data-kzv-count]');
	var st = { q: cfg.q || '', genre: cfg.genre || '', next: grid ? grid.querySelectorAll('.kzv-cell').length : 0, total: cfg.total || 0, seq: 0, busy: false };

	function setMore() {
		if (moreWrap) { moreWrap.hidden = st.next >= st.total; }
	}

	function lastMonth() {
		var m = grid.querySelectorAll('.kzv-month');
		return m.length ? m[m.length - 1].getAttribute('data-month') : '';
	}

	function load(append) {
		var my = ++st.seq;
		st.busy = true;
		root.classList.add('kzv-busy');
		var retry = grid.querySelector('.kzv-retry');
		if (retry) { retry.parentNode.removeChild(retry); }
		return api('videos', { q: st.q, genre: st.genre, offset: append ? st.next : 0 })
			.then(function (d) {
				if (my !== st.seq) { return; }
				if (append) {
					var prev = lastMonth();
					var tmp = document.createElement('ul');
					tmp.innerHTML = d.html;
					var first = tmp.firstElementChild;
					if (first && first.classList.contains('kzv-month') && first.getAttribute('data-month') === prev) {
						tmp.removeChild(first);
					}
					while (tmp.firstChild) { grid.appendChild(tmp.firstChild); }
				} else {
					grid.innerHTML = d.html || '<li class="kzv-none">Ничего не нашлось. Попробуйте фамилию артиста или название коллектива.</li>';
				}
				st.next = d.next;
				st.total = d.total;
				if (count) { count.textContent = d.label; }
				markActive(screen ? screen.getAttribute('data-id') : '');
				setMore();
			})
			.catch(function () {
				if (my !== st.seq) { return; }
				var li = el('li', 'kzv-retry', 'Не удалось загрузить записи. ');
				var b = el('button', 'kzv-btn', 'Повторить');
				b.type = 'button';
				b.addEventListener('click', function () { load(append); });
				li.appendChild(b);
				grid.appendChild(li);
			})
			.then(function () {
				if (my === st.seq) {
					st.busy = false;
					root.classList.remove('kzv-busy');
				}
			});
	}

	function syncUrl() {
		try {
			var u = new URL(window.location.href);
			['q', 'genre'].forEach(function (k) {
				if (st[k]) { u.searchParams.set(k, st[k]); } else { u.searchParams.delete(k); }
			});
			history.replaceState(history.state, '', u.toString());
		} catch (e) { /* без адреса */ }
	}

	if (grid) {
		setMore();
		var more = $('[data-kzv-more]');
		if (more) {
			more.addEventListener('click', function () { if (!st.busy) { load(true); } });
		}
		var form = $('[data-kzv-search]');
		var input = form ? form.querySelector('input[type="search"]') : null;
		var timer = null;
		if (form && input) {
			form.addEventListener('submit', function (e) {
				e.preventDefault();
				clearTimeout(timer);
				st.q = input.value.trim();
				load(false);
				syncUrl();
				input.blur();
			});
			input.addEventListener('input', function () {
				clearTimeout(timer);
				timer = setTimeout(function () {
					var q = input.value.trim();
					if (q === st.q || (q.length > 0 && q.length < 2)) { return; }
					st.q = q;
					load(false);
					syncUrl();
				}, 350);
			});
		}
		var genres = $('[data-kzv-genres]');
		if (genres) {
			genres.addEventListener('click', function (e) {
				var b = e.target.closest('[data-genre]');
				if (!b) { return; }
				genres.querySelectorAll('[data-genre]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
				st.genre = b.getAttribute('data-genre');
				load(false);
				syncUrl();
			});
		}
	}

	/* ---------- эфир: проверка сразу (страница могла быть из кэша) и раз в минуту ---------- */

	var mast = $('[data-kzv-mast]');
	var guide = $('[data-kzv-guide]');
	var live = !!cfg.live;
	var liveState = null;

	function goLive() {
		if (!liveState || !liveState.live || !liveState.embed) {
			if (liveState && liveState.watch) { window.open(liveState.watch, '_blank', 'noopener'); }
			return;
		}
		setScreen(liveState.embed, liveState.title, '', true);
		setNow('live', 'Главная сцена', liveState.title, { concert: liveState.url, vkWatch: liveState.watch, yt: liveState.yt, href: cfg.base });
		screen.setAttribute('data-id', 'live');
		markActive('');
		toScreen();
	}

	function poll() {
		if (document.hidden) { return; }
		api('live').then(function (d) {
			if (!d || typeof d.live !== 'boolean') { return; }
			liveState = d;
			if (mast && typeof d.mast === 'string') {
				var tmp = document.createElement('div');
				tmp.innerHTML = d.mast;
				if (tmp.firstElementChild) { mast.replaceWith(tmp.firstElementChild); mast = $('[data-kzv-mast]'); }
			}
			if (guide && typeof d.guide === 'string') {
				var g = document.createElement('div');
				g.innerHTML = d.guide;
				if (g.firstElementChild) { guide.replaceWith(g.firstElementChild); guide = $('[data-kzv-guide]'); }
			}
			root.classList.toggle('is-live', d.live);
			if (d.live && !live) {
				if (!playing() || screen.getAttribute('data-id') === 'live') {
					goLive();
				} else {
					toast('Начался эфир: ' + d.title, { label: 'Смотреть', run: goLive });
				}
			}
			if (!d.live && live && screen && screen.getAttribute('data-id') === 'live') {
				setNow('rec', 'Эфир завершён. Запись появится в архиве', d.title || '', {});
			}
			live = d.live;
		}).catch(function () { /* следующая попытка через минуту */ });
	}
	poll();
	setInterval(poll, 60000);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });

	if (cfg.focus === 'archive') {
		var lib = document.getElementById('kzv-archive');
		if (lib) { lib.scrollIntoView({ block: 'start' }); }
	}
})();
