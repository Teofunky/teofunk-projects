/* Kozlov Video: плеер VK, поиск, «Показать ещё», проверка эфира раз в минуту. Без jQuery и глобальных переменных. */
(function () {
	'use strict';

	var root = document.querySelector('.kzv[data-kzv]');
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

	function api(path, params) {
		var u = new URL(cfg.rest + path, window.location.href);
		Object.keys(params || {}).forEach(function (k) {
			u.searchParams.set(k, params[k]);
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
		if (cls) {
			n.className = cls;
		}
		if (text) {
			n.textContent = text;
		}
		return n;
	}

	function iframe(src, title) {
		var f = document.createElement('iframe');
		f.src = src;
		f.title = title || 'Видео';
		f.setAttribute('allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture; screen-wake-lock');
		f.setAttribute('allowfullscreen', '');
		return f;
	}

	// обложка не загрузилась (например, YouTube недоступен): остаётся тёмный фон вместо значка битой картинки
	root.addEventListener('error', function (e) {
		if (e.target && e.target.tagName === 'IMG') {
			e.target.style.visibility = 'hidden';
		}
	}, true);

	/* ---------- плеер ---------- */

	var stage = root.querySelector('#kzv-player');
	var frame = stage ? stage.querySelector('[data-kzv-frame]') : null;

	function startFrame(fr, title) {
		var src = fr.getAttribute('data-src');
		if (!src) {
			return;
		}
		fr.innerHTML = '';
		fr.appendChild(iframe(src, title));
	}

	root.addEventListener('click', function (e) {
		var play = e.target.closest('[data-kzv-play]');
		if (play) {
			var fr = play.closest('[data-kzv-frame]');
			var t = fr && fr.parentNode.querySelector('[data-kzv-title]');
			startFrame(fr, t ? t.textContent : '');
			return;
		}
		var livePlay = e.target.closest('[data-kzv-live-play]');
		if (livePlay && frame) {
			showItem({
				title: livePlay.getAttribute('data-kzv-live-title'),
				meta: 'Прямой эфир · Главная сцена',
				vk: livePlay.getAttribute('data-kzv-live-play'),
				img: '', vkWatch: '', yt: '', concert: '', up: null
			}, true);
			return;
		}
		var card = e.target.closest('[data-kzv-card]');
		if (card && frame && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) {
			var item;
			try {
				item = JSON.parse(card.getAttribute('data-kzv-card'));
			} catch (err) {
				return;
			}
			if (!item.vk) {
				return;
			}
			e.preventDefault();
			showItem(item, true);
			markActive(item.id);
			try {
				history.pushState({ kzv: item.id }, '', item.href);
			} catch (err) { /* адрес не меняем, плеер уже переключён */ }
		}
	});

	function actionsFor(item) {
		var box = document.createDocumentFragment();
		function add(node) {
			if (box.childNodes.length) {
				box.appendChild(document.createTextNode(' '));
			}
			box.appendChild(node);
		}
		var a;
		if (item.up && item.up.url) {
			a = el('a', 'kzv-btn', item.up.label);
			a.href = item.up.url;
			add(a);
		}
		if (item.concert) {
			a = el('a', 'kzv-btn kzv-btn--ghost', 'О концерте');
			a.href = item.concert;
			add(a);
		}
		[['vkWatch', 'VK Видео'], ['yt', 'YouTube']].forEach(function (p) {
			if (item[p[0]]) {
				var l = el('a', 'kzv-link', p[1]);
				l.href = item[p[0]];
				l.target = '_blank';
				l.rel = 'noopener';
				add(l);
			}
		});
		return box;
	}

	function showItem(item, autoplay) {
		frame.setAttribute('data-src', item.vk);
		if (autoplay) {
			frame.innerHTML = '';
			frame.appendChild(iframe(item.vk, item.title));
		}
		var info = stage.querySelector('.kzv-info');
		if (!info) {
			info = el('div', 'kzv-info');
			info.innerHTML = '<p class="kzv-kicker" data-kzv-meta></p><h2 class="kzv-title" data-kzv-title></h2><p class="kzv-actions" data-kzv-actions></p>';
			stage.appendChild(info);
		}
		info.querySelector('[data-kzv-meta]').textContent = item.meta || '';
		info.querySelector('[data-kzv-title]').textContent = item.title || '';
		var act = info.querySelector('[data-kzv-actions]');
		act.innerHTML = '';
		act.appendChild(actionsFor(item));
		var top = stage.getBoundingClientRect().top;
		if (top < 0 || top > window.innerHeight * 0.4) {
			stage.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
		}
	}

	function markActive(id) {
		root.querySelectorAll('.kzv-card[aria-current]').forEach(function (c) {
			c.removeAttribute('aria-current');
		});
		var c = root.querySelector('.kzv-card[data-kzv-id="' + id + '"]');
		if (c) {
			c.setAttribute('aria-current', 'true');
		}
	}

	window.addEventListener('popstate', function (e) {
		if (!frame || !e.state || !e.state.kzv) {
			return;
		}
		var c = root.querySelector('.kzv-card[data-kzv-id="' + e.state.kzv + '"]');
		if (c) {
			try {
				showItem(JSON.parse(c.getAttribute('data-kzv-card')), false);
				markActive(e.state.kzv);
			} catch (err) { /* ничего */ }
		}
	});

	/* ---------- архив: поиск и «Показать ещё» ---------- */

	var grid = root.querySelector('[data-kzv-grid]');
	var moreWrap = root.querySelector('[data-kzv-more-wrap]');
	var count = root.querySelector('[data-kzv-count]');
	var form = root.querySelector('[data-kzv-search]');
	var state = { q: cfg.q || '', next: grid ? grid.children.length : 0, total: cfg.total || 0, busy: false, seq: 0 };

	function setMore() {
		if (moreWrap) {
			moreWrap.hidden = state.next >= state.total;
		}
	}

	function load(append) {
		var my = ++state.seq;
		state.busy = true;
		root.classList.add('kzv-busy');
		return api('videos', { q: state.q, offset: append ? state.next : 0 })
			.then(function (d) {
				if (my !== state.seq) {
					return;
				}
				if (append) {
					grid.insertAdjacentHTML('beforeend', d.html);
				} else {
					grid.innerHTML = d.html || '<li class="kzv-none">Ничего не нашлось. Попробуйте фамилию артиста или название коллектива.</li>';
				}
				state.next = d.next;
				state.total = d.total;
				if (count) {
					count.textContent = d.label;
				}
				setMore();
			})
			.catch(function () {
				if (count) {
					count.textContent = 'Не удалось загрузить, попробуйте ещё раз';
				}
			})
			.then(function () {
				if (my === state.seq) {
					state.busy = false;
					root.classList.remove('kzv-busy');
				}
			});
	}

	if (grid) {
		setMore();
		var more = root.querySelector('[data-kzv-more]');
		if (more) {
			more.addEventListener('click', function () {
				if (!state.busy) {
					load(true);
				}
			});
		}
	}

	if (form && grid) {
		var input = form.querySelector('input[type="search"]');
		var timer = null;
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			clearTimeout(timer);
			state.q = input.value.trim();
			load(false);
		});
		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(function () {
				var q = input.value.trim();
				if (q === state.q || (q.length > 0 && q.length < 2)) {
					return;
				}
				state.q = q;
				load(false);
				try {
					var u = new URL(window.location.href);
					if (q) {
						u.searchParams.set('q', q);
					} else {
						u.searchParams.delete('q');
					}
					history.replaceState(history.state, '', u.toString());
				} catch (err) { /* без адреса в строке */ }
			}, 350);
		});
	}

	/* ---------- эфир: проверка сразу (страница могла быть из кэша) и раз в минуту ---------- */

	var liveBox = root.querySelector('[data-kzv-live]');
	var last = root.querySelector('[data-kzv-last]');
	var isLive = !!cfg.live;
	var view = cfg.view === 'live' ? 'live' : 'bar';

	function poll() {
		if (!liveBox || document.hidden) {
			return;
		}
		api('live', { view: view }).then(function (d) {
			if (!d || typeof d.html !== 'string') {
				return;
			}
			// идущий эфир не перерисовываем, чтобы не сбить плеер
			if (d.live && isLive && liveBox.querySelector('iframe')) {
				return;
			}
			if (d.live !== isLive || !d.live) {
				liveBox.innerHTML = d.html;
			}
			isLive = d.live;
			if (last) {
				last.hidden = isLive;
			}
		}).catch(function () { /* следующая попытка через минуту */ });
	}

	if (liveBox) {
		poll();
		setInterval(poll, 60000);
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) {
				poll();
			}
		});
	}
})();
