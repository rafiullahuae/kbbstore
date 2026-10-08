/* Lane CC: Lighthouse filmstrips side by side.  node tools/cc-film.cjs out.png title before=a.json after=b.json */
// Lane CC: compose Lighthouse filmstrips (screenshot-thumbnails) of head vs new into one PNG.
// node cc-film.cjs <out.png> <title> <label:lhr.json> ...
const { chromium } = require(require('path').join(__dirname, '..', 'node_modules', 'playwright')); const fs = require('fs');
(async () => {
  const [out, title, ...pairs] = process.argv.slice(2);
  let rows = '';
  for (const p of pairs) { const [label, file] = p.split('='); const l = JSON.parse(fs.readFileSync(file, 'utf8'));
    const th = l.audits['screenshot-thumbnails'].details.items; const a = (id) => Math.round(l.audits[id].numericValue);
    rows += `<div class="r"><div class="l"><b>${label}</b><br>FCP ${a('first-contentful-paint')} ms<br>LCP ${a('largest-contentful-paint')} ms<br>SI ${a('speed-index')} ms<br>CLS ${(+l.audits['cumulative-layout-shift'].numericValue).toFixed(3)}</div>` +
      th.map((t) => `<figure><img src="${t.data}"><figcaption>${Math.round(t.timing)} ms</figcaption></figure>`).join('') + '</div>'; }
  const html = `<html><body style="font:13px system-ui;margin:12px;background:#fff"><h3 style="margin:0 0 8px">${title}</h3>${rows}<style>.r{display:flex;gap:6px;margin-bottom:10px;align-items:flex-start}.l{width:120px;flex:none}figure{margin:0;text-align:center}img{width:96px;border:1px solid #ddd;display:block}figcaption{font-size:11px;color:#555}</style></body></html>`;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' }); const pg = await b.newPage({ viewport: { width: 1280, height: 400 } });
  await pg.setContent(html); await pg.screenshot({ path: out, fullPage: true }); await b.close();
})();
