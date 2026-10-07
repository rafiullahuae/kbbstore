/* Lane PG2: side-by-side PNGs with captions.  node tools/pg2g-pair.cjs OUT.png WIDTH 'caption=file.png' ... */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const [out, w, ...cells] = process.argv.slice(2);
(async () => {
  const html = '<body style="margin:8px;font:13px system-ui,sans-serif;white-space:nowrap">' + cells.map((c) => { const [cap, f] = c.split('='); return `<figure style="display:inline-block;vertical-align:top;margin:0 8px 0 0;width:${w}px"><figcaption style="font-weight:700;margin-bottom:4px;white-space:normal">${cap}</figcaption><img style="width:${w}px;border:1px solid #bbb" src="data:image/png;base64,${fs.readFileSync(f).toString('base64')}"></figure>`; }).join('') + '</body>';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 300, height: 300 } });
  await p.setContent(html);
  const s = await p.evaluate(() => [document.body.scrollWidth + 16, document.body.scrollHeight + 16]);
  await p.setViewportSize({ width: s[0], height: s[1] });
  await p.screenshot({ path: out, fullPage: true }); await b.close(); console.log(out, s.join('x'));
})();
