/* Логотип в шапке: клапаны саксофона на букве «l» нажимаются короткой фразой.
   Один раз после загрузки страницы и потом при наведении. При «Уменьшить движение» логотип неподвижен. */
(function () {
	'use strict';
	var svg = document.querySelector('.header .logo .kz-logo-svg');
	if (!svg || !window.requestAnimationFrame) { return; }
	if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
	var keys = svg.querySelectorAll('.kzk');
	if (keys.length !== 3) { return; }
	var STEM = 345.1;
	var RIFF = [[[0], 150], [[1], 150], [[2], 210], [[1, 2], 150], [[0, 1], 150], [[0, 1, 2], 330], [[], 0]];
	var busy = false;
	function setKey(g, p) {
		var sx = 1 - 0.3 * p, dx = -0.9 * p;
		g.setAttribute('transform', 'translate(' + (STEM + dx) + ' 0) scale(' + sx + ' 1) translate(' + (-STEM) + ' 0)');
	}
	function play() {
		if (busy) { return; }
		busy = true;
		var state = [0, 0, 0], target = [0, 0, 0], step = 0, tStep = performance.now(), last = tStep;
		function setTarget() { target = [0, 0, 0]; RIFF[step][0].forEach(function (k) { target[k] = 1; }); }
		setTarget();
		function frame(now) {
			var dt = Math.min(40, now - last), i, d, done = true;
			last = now;
			for (i = 0; i < 3; i++) {
				d = target[i] - state[i];
				state[i] += (d > 0 ? 1 : -1) * Math.min(Math.abs(d), (target[i] ? 0.022 : 0.012) * dt);
				setKey(keys[i], state[i]);
				if (state[i] > 0.001) { done = false; }
			}
			if (now - tStep >= RIFF[step][1]) {
				step++; tStep = now;
				if (step >= RIFF.length) {
					if (done) { for (i = 0; i < 3; i++) { keys[i].removeAttribute('transform'); } busy = false; return; }
					step = RIFF.length - 1;
				}
				setTarget();
			}
			requestAnimationFrame(frame);
		}
		requestAnimationFrame(frame);
	}
	svg.addEventListener('mouseenter', play);
	var link = svg.closest('a');
	if (link) { link.addEventListener('focus', play); }
	// один раз за визит: на следующих страницах того же визита при загрузке не играем
	var played = false;
	try { played = sessionStorage.getItem('kzLogoPlayed') === '1'; sessionStorage.setItem('kzLogoPlayed', '1'); } catch (e) { /* без хранилища играем каждый раз */ }
	if (!played) {
		if (document.readyState === 'complete') { setTimeout(play, 600); }
		else { window.addEventListener('load', function () { setTimeout(play, 600); }); }
	}
})();
