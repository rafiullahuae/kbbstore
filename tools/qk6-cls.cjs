// Lane QK6: layout shift on /checkout/ load (no input), at 390 and 1280.
//   node tools/qk6-cls.cjs BASE
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const BASE = process.argv[2];
const ids = JSON.parse(fs.readFileSync(process.argv[3] || path.join(__dirname, '..', 'storage/framework/testing/qk6-preview/webroot/qk6-ids.json')));
(async () => {
  const b = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 844 }, isMobile: w === 390, hasTouch: w === 390 });
    const p = await ctx.newPage();
    await p.addInitScript(() => { window.__cls = 0; new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); });
    await p.goto(BASE + '/shop/', { waitUntil: 'domcontentloaded' });
    await p.evaluate(async (id) => { await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: id, quantity: 1 }) }); }, ids['qk6-serum']);
    await p.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    await p.waitForTimeout(1500);
    console.log(JSON.stringify({ width: w, cls: Math.round((await p.evaluate(() => window.__cls)) * 10000) / 10000, scrollWidth: await p.evaluate(() => document.documentElement.scrollWidth) }));
    await ctx.close();
  }
  await b.close();
})();
