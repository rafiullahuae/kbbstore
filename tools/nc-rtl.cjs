/* Lane MN (2.60.441): the Arabic (RTL) bar with the current item marked, at
   rest and with its own panel open. node tools/nc-rtl.cjs BASE */
const path = require('path');
const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const BASE = process.argv[2];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const [n, u] of [['category_ar', '/ar/collections/skincare/serums/'], ['brand_ar', '/ar/brands/cosrx/']]) {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
    const p = await ctx.newPage(); const errs = [];
    p.on('pageerror', (e) => errs.push(String(e))); p.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
    await p.goto(BASE + u, { waitUntil: 'load' }); await p.mouse.move(2, 790); await p.waitForTimeout(600);
    out[n] = await p.evaluate(() => ({ dir: document.documentElement.dir, marked: [...document.querySelectorAll('.mbar [aria-current]')].map((a) => { const r = a.getBoundingClientRect(); const af = getComputedStyle(a, '::after'); return { t: a.textContent.trim().replace(/\s+/g, ' '), c: a.getAttribute('aria-current'), x: Math.round(r.x), after: af.transform, afterL: af.left, afterR: af.right }; }) }));
    out[n].errors = errs;
    await p.screenshot({ path: path.join(APP, `docs/lane-mn-shots/after-rtl-${n}-1280.png`), clip: { x: 0, y: 0, width: 1280, height: 120 } });
    const cur = p.locator('.mbar .navlink[aria-current]').first(); const bb = await cur.boundingBox();
    await p.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2, { steps: 4 }); await p.waitForTimeout(450);
    await p.screenshot({ path: path.join(APP, `docs/lane-mn-shots/after-rtl-${n}-own-panel-1280.png`), clip: { x: 0, y: 0, width: 1280, height: 380 } });
    await ctx.close();
  }
  await b.close();
  console.log(JSON.stringify(out, null, 1));
})();
