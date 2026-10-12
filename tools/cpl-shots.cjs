/* Lane CPL: the cart panel's picture and name open the product. BASE=... PID=... node tools/cpl-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, PID = process.env.PID, OUT = path.resolve(__dirname, '../docs/lane-cpl-shots');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 } });
    const page = await ctx.newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/404/.test(m.text())) errors.push(m.text()); });
    await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    await page.evaluate(async (pid) => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: +pid, qty: 1 }) }); }, PID);
    const res = {};
    for (const [what, sel] of [['picture', '#kcCart .kc-item a.kc-th'], ['name', '#kcCart .kc-item .kc-nm a']]) {
      await page.goto(BASE + '/', { waitUntil: 'networkidle' });
      await page.locator('[data-kbb-open="cart"]').first().click();
      await page.waitForTimeout(800);
      if (what === 'picture') await page.screenshot({ path: `${OUT}/cart-panel-${w}.png` });
      const el = page.locator(sel).first();
      const box = await el.boundingBox();
      const hit = await page.evaluate(([s, x, y]) => { const a = document.querySelector(s); const h = document.elementFromPoint(x, y); return !!a && !!h && (h === a || a.contains(h)); }, [sel, box.x + box.width / 2, box.y + box.height / 2]);
      await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), el.click()]);
      res[what] = { hitTest: hit, landedOn: new URL(page.url()).pathname };
    }
    console.log(w, JSON.stringify({ ...res, errors }));
    await ctx.close();
  }
  await browser.close();
})();
