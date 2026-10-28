/* Lane GBX: builds docs/gbx-previews/live/index.html (one self-contained page)
   and its images/ folder from tools/gbx-live.html, tools/gbx-kit.{js,css} and
   the box markup tools/gbx-live-seed.php rendered into the preview webroot.
     FG_SEED=gbx-live-seed.php sh tools/fg-preview.sh 12360
     WEB=<that webroot> node tools/gbx-live-build.cjs */
const fs = require('fs'), path = require('path');
const OUT = path.resolve(__dirname, '../docs/gbx-previews/live');
const data = fs.readFileSync(path.join(process.env.WEB, 'gbx-live.json'), 'utf8');
const kitJs = fs.readFileSync(path.join(__dirname, 'gbx-kit.js'), 'utf8');
const kitCss = fs.readFileSync(path.join(__dirname, 'gbx-kit.css'), 'utf8');
let html = fs.readFileSync(path.join(__dirname, 'gbx-live.html'), 'utf8');
// Inline JSON inside a <script>: a "</" would end the script early.
html = html.replace('/*DATA*/', () => data.replace(/<\//g, '<\\/'))
  .replace('/*KIT_JS*/', () => kitJs)
  .replace('/*KIT_CSS*/', () => kitCss);
fs.mkdirSync(path.join(OUT, 'images'), { recursive: true });
fs.writeFileSync(path.join(OUT, 'index.html'), html);
const used = [...new Set([...data.matchAll(/images\/([a-z0-9-]+\.webp)/g)].map((m) => m[1]))];
for (const f of used) fs.copyFileSync(path.join(process.env.WEB, 'uploads/gifts', f), path.join(OUT, 'images', f));
console.log('live preview:', path.join(OUT, 'index.html'), (html.length / 1024).toFixed(1) + ' KB,', used.length, 'images:', used.join(' '));
