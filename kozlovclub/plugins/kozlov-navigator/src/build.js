// Сборка из папки плагина: node src/build.js → assets/navigator.js (движок + интерфейс + стили, без глобальных переменных)
const fs = require('fs');
const path = require('path');
const P = (...a) => path.join(__dirname, ...a);
const pkg = '1.0.13';
const engine = fs.readFileSync(P('engine.js'), 'utf8');
const ui = fs.readFileSync(P('ui.js'), 'utf8');
const css = fs.readFileSync(P('..', 'assets', 'navigator.css'), 'utf8');
const out = `/*! Kozlov Navigator ${pkg}: подбор концертов клуба по ответам посетителя. Исходники: src/engine.js, src/ui.js. */
(function () {
'use strict';
var module = { exports: {} };
${engine}
var E = module.exports;
var CSS = ${JSON.stringify(css)};
${ui}
function boot() { try { knavStart(E, CSS); } catch (e) { try { console.warn('[Kozlov Navigator]', e); } catch (x) { } } }
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
`;
fs.writeFileSync(P('..', 'assets', 'navigator.js'), out);
console.log('navigator.js', out.length, 'bytes');
