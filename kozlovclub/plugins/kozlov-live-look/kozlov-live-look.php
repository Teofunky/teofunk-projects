<?php
/**
 * Plugin Name: Kozlov Live Look
 * Description: Для действующего сайта на старой теме: чёрный векторный логотип с анимацией клапанов (при открытии и при наведении) и чёрный шрифт заголовков в слайдере. Тему не меняет; отключение плагина возвращает всё как было.
 * Version: 1.0.0
 * Author: Kozlov Club
 * Requires PHP: 7.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', function () {
	if ( is_admin() ) { return; }
	?>
<script>(function(r){r.classList.add('kzl-js');setTimeout(function(){r.classList.remove('kzl-js')},3000)})(document.documentElement);</script>
<style id="kozlov-live-look">
html.kzl-js .header .logo img{visibility:hidden}
html body .header .logo a{display:block;line-height:0}
html body .header .logo svg.kz-logo-svg{display:block;width:222px;height:auto;transition:width 200ms linear}
html body .header.scrolled .logo svg.kz-logo-svg{width:50%}
html body .header .logo .kzk{will-change:transform}
html body .header .logo a:focus-visible{outline:2px solid #bf1818;outline-offset:4px}
@media screen and (max-width:1024px){html body .header .logo svg.kz-logo-svg,html body .header.scrolled .logo svg.kz-logo-svg{width:100%}}
html body .slider .slides .slide .title a,
html body .slider .slides .slide .info .title a{color:#100F0D}
html body .slider .slides .slide .title a:hover{color:#000}
html body .slider .slides .slide .title a .kz-sub,
html body .slider .slides .slide .info .title a .kz-sub{color:#424242}
</style>
	<?php
}, 99 );

add_action( 'wp_footer', function () {
	if ( is_admin() ) { return; }
	$svg = file_get_contents( __DIR__ . '/logo.svg' ); // свой файл плагина
	if ( ! $svg ) { return; }
	?>
<template id="kzl-logo-tpl"><?php echo $svg; // phpcs:ignore ?></template>
<script>
(function () {
	'use strict';
	var root = document.documentElement;
	function unhide() { root.classList.remove('kzl-js'); }
	var img = document.querySelector('.header .logo img');
	var tpl = document.getElementById('kzl-logo-tpl');
	if (!img || !tpl || !tpl.content) { unhide(); return; }
	var svg = tpl.content.querySelector('svg');
	if (!svg) { unhide(); return; }
	svg = document.importNode(svg, true);
	var a = img.closest('a');
	if (a && !a.getAttribute('aria-label')) { a.setAttribute('aria-label', img.getAttribute('alt') || 'Клуб Алексея Козлова, на главную'); }
	img.parentNode.replaceChild(svg, img);
	unhide();

	if (!window.requestAnimationFrame) { return; }
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
	if (a) { a.addEventListener('focus', play); }
	var played = false;
	try { played = sessionStorage.getItem('kzLogoPlayed') === '1'; sessionStorage.setItem('kzLogoPlayed', '1'); } catch (e) {}
	if (!played) {
		if (document.readyState === 'complete') { setTimeout(play, 600); }
		else { window.addEventListener('load', function () { setTimeout(play, 600); }); }
	}
})();
</script>
	<?php
}, 99 );
