// Сборка Kozlov Afisha Filters: src/kaf.css + src/kaf-app.js -> assets/kaf-app.js
// node build.js <папка плагина> <версия>
const fs = require('fs');
const path = require('path');
const { minify } = require(path.join('/home/claude/jsbuild/node_modules', 'terser'));

function minCss(css) {
  return css
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/\s+/g, ' ')
    .replace(/\s*([{};,])\s*/g, '$1') // двоеточия не трогаем: «.kaf :focus-visible» и «.kaf:focus-visible» значат разное
    .replace(/;}/g, '}')
    .trim();
}

(async () => {
  const [dir, version] = process.argv.slice(2);
  const css = minCss(fs.readFileSync(path.join(dir, 'src', 'kaf.css'), 'utf8'));
  const js = fs.readFileSync(path.join(dir, 'src', 'kaf-app.js'), 'utf8');
  if (js.split('__KAF_CSS__').length !== 2) throw new Error('метка __KAF_CSS__ должна встречаться один раз');
  const full = js.replace('__KAF_CSS__', () => JSON.stringify(css));
  const out = await minify(full, {
    ecma: 5,
    compress: { passes: 2 },
    mangle: true,
    format: { comments: false, preamble: `/*! Kozlov Afisha Filters ${version}. Исходный код: папка src внутри плагина. */` },
  });
  fs.writeFileSync(path.join(dir, 'assets', 'kaf-app.js'), out.code + '\n');
  console.log('css', css.length, 'full', full.length, 'min', out.code.length);
})().catch((e) => { console.error(e); process.exit(1); });
