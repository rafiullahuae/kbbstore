// Photographs out/*.html at their exact size -> out/<name>.png + .jpg, then a contact sheet.
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = path.join(__dirname, 'out');
const SIZE = { post: [1080, 1350], square: [1080, 1080], story: [1080, 1920] };
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const files = fs.readdirSync(OUT).filter((f) => f.endsWith('.html')).filter((f) => !process.argv[2] || f.startsWith(process.argv[2])).sort();
  for (const f of files) {
    const fmt = f.replace('.html', '').split('-').find((t) => SIZE[t]); const [w, h] = SIZE[fmt];
    const p = await b.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    await p.goto('file://' + path.join(OUT, f), { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(150);
    const base = path.join(OUT, f.replace('.html', ''));
    await p.screenshot({ path: base + '.png' }); await p.screenshot({ path: base + '.jpg', type: 'jpeg', quality: 92 });
    await p.close();
  }
  if (process.argv[3] === 'nosheet') { await b.close(); return; }
  const names = [...new Set(files.map((f) => f.replace(/-(post|square|story)\.html$/, '')))];
  const img = (f, w) => `<img style="width:${w}px;border-radius:8px;display:block" src="data:image/jpeg;base64,${fs.readFileSync(path.join(OUT, f)).toString('base64')}">`;
  let rows = names.map((n) => `<section><h2>${n.replace(/^(\d+)-/, '$1 · ').replace(/-/g, ' ')}</h2><div style="display:flex;gap:14px;align-items:flex-start">${img(n + '-post.jpg', 300)}${img(n + '-square.jpg', 300)}${img(n + '-story.jpg', 236)}</div></section>`).join('');
  const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:18px;background:#e9e6e8;font:600 16px system-ui;width:1700px}section{display:inline-block;vertical-align:top;margin:0 26px 22px 0}h2{font-size:15px;margin:0 0 6px;text-transform:capitalize}</style><h1 style="font-size:20px">Black Friday ads · 10 colour options · post 1080×1350 · square 1080×1080 · story 1080×1920</h1>${rows}`;
  const s = await (await b.newContext({ viewport: { width: 1736, height: 900 } })).newPage();
  await s.setContent(html); await s.screenshot({ path: path.join(__dirname, 'black-friday-ads-all.jpg'), type: 'jpeg', quality: 78, fullPage: true });
  await b.close(); console.log('rendered', files.length);
})();
