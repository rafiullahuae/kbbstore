/* Lane SPD: bytes a first view downloads, per page type, before and after.
 *
 *   node tools/spd-measure.cjs <label> <baseUrl> <outJson>
 *
 * Each page is opened cold (new context, empty cache) at 390 (device-pixel-
 * ratio 3, a phone) and at 1280 (ratio 1), left to load and settle for 1.5 s
 * with no scrolling, and the bytes of every response are summed from the
 * protocol's own `encodedDataLength` (what crossed the wire). Also recorded:
 * the HTML document's bytes, console errors and failed requests, and the
 * server's own numbers from the preview front controller (tools/spd-index.php:
 * X-Spd-Ms, X-Spd-Q). Nothing is clicked or scrolled. */
const fs = require('fs');
const { chromium } = require('playwright');

const [label, base, out] = process.argv.slice(2);
const urls = JSON.parse(process.env.SPD_URLS || '{}');
const pages = Object.assign({ home: '/' }, urls);
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
  const rows = [];
  for (const [name, path] of Object.entries(pages)) {
    for (const [width, dpr] of [[390, 3], [1280, 1]]) {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 800 }, deviceScaleFactor: dpr,
        isMobile: width === 390, hasTouch: width === 390 });
      const page = await ctx.newPage();
      const cdp = await ctx.newCDPSession(page);
      await cdp.send('Network.enable');
      const req = new Map();
      let doc = 0; let img = 0; let imgN = 0; let total = 0; const imgs = []; const failed = []; const errors = [];
      cdp.on('Network.requestWillBeSent', (e) => req.set(e.requestId, { url: e.request.url, type: e.type }));
      cdp.on('Network.responseReceived', (e) => { const r = req.get(e.requestId); if (r) { r.type = e.type; r.status = e.response.status; r.headers = e.response.headers; } });
      cdp.on('Network.loadingFinished', (e) => {
        const r = req.get(e.requestId); if (!r) return;
        total += e.encodedDataLength;
        if (r.type === 'Document' && doc === 0) { doc = e.encodedDataLength; r.doc = true; }
        if (r.type === 'Image') { img += e.encodedDataLength; imgN++; imgs.push([r.url.replace(base, ''), e.encodedDataLength]); }
        if (r.status >= 400) failed.push(r.status + ' ' + r.url.replace(base, ''));
      });
      cdp.on('Network.loadingFailed', (e) => { const r = req.get(e.requestId); if (r && !/facebook|googletagmanager|tiktok/.test(r.url)) failed.push('failed ' + r.url.replace(base, '')); });
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });
      page.on('pageerror', (e) => errors.push('pageerror ' + String(e).slice(0, 160)));
      const resp = await page.goto(base + path, { waitUntil: 'load' });
      await page.waitForTimeout(1500);
      const h = resp.headers();
      const html = (await resp.body()).length;
      const sw = await page.evaluate(() => document.documentElement.scrollWidth);
      rows.push({ label, page: name, width, serverMs: +h['x-spd-ms'], queries: +h['x-spd-q'], htmlRaw: html, htmlWire: doc,
        imgKB: +(img / 1024).toFixed(1), imgN, totalKB: +(total / 1024).toFixed(1), scrollWidth: sw, errors, failed,
        reviewKB: +(imgs.filter(([u]) => /reviews\//.test(u)).reduce((s, [, b]) => s + b, 0) / 1024).toFixed(1), reviewN: imgs.filter(([u]) => /reviews\//.test(u)).length,
        imgs: imgs.sort((a, b) => b[1] - a[1]).slice(0, 6).map(([u, b]) => `${(b / 1024).toFixed(1)}K ${u}`) });
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(out, JSON.stringify(rows, null, 1));
  for (const r of rows) console.log(`${r.label} ${r.page.padEnd(8)} ${String(r.width).padEnd(4)} img ${String(r.imgKB).padStart(7)}K (${r.imgN})  html ${r.htmlRaw}/${r.htmlWire}  sw ${r.scrollWidth}  err ${r.errors.length} fail ${r.failed.length}`);
})();
