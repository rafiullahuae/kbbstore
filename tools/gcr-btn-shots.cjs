/* The criteria button as "All gifts" + arrow (owner, 15 Nov), cart page and panel, 390 and 1280, en and /ar.
   Same preview as tools/gcr-shots.cjs:  BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> node tools/gcr-btn-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID), OUT = path.resolve(__dirname, '../docs/lane-gcr-shots');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch();
  const out = [];
  for (const lang of ['', '/ar']) {
    for (const w of [390, 1280]) {
      const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      await page.goto(BASE + lang + '/', { waitUntil: 'networkidle' });
      await page.evaluate(async (id) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) }), PID);
      await page.goto(BASE + lang + '/cart', { waitUntil: 'networkidle' });
      const f = page.locator('.fgb').filter({ visible: true }).first();
      await f.evaluate((x) => x.scrollIntoView({ block: 'center' }));
      const cr = f.locator('.gbx-cr').filter({ visible: true }).first();
      const info = await cr.evaluate((b) => ({ text: b.textContent, arrow: !!b.querySelector('svg.gbx-ca'), fs: getComputedStyle(b).fontSize, h: b.getBoundingClientRect().height }));
      const hit = await cr.evaluate((b) => { const r = b.getBoundingClientRect(); const e = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return b.contains(e); });
      await f.screenshot({ path: `${OUT}/btn${lang ? '-ar' : ''}-cart-${w}.png` });
      await cr.click(); await page.waitForTimeout(300);
      const opened = await page.evaluate(() => !!document.querySelector('.gbx-cp:popover-open'));
      out.push({ lang: lang || 'en', w, ...info, hit, opened, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors });
      await ctx.close();
    }
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
