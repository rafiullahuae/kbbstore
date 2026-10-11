/* Lane CPB: the cart panel opened on a freshly loaded page shows the
   free-delivery bar. BASE=http://127.0.0.1:PORT SLUG=... node tools/cpb-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, SLUG = process.env.SLUG, OUT = path.resolve(__dirname, '../docs/lane-cpb-shots');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
require('fs').mkdirSync(OUT, { recursive: true });
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 } });
    const page = await ctx.newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() === 404) errors.push('404 ' + r.url()); });
    await page.goto(BASE + '/product/' + SLUG + '/', { waitUntil: 'networkidle' });
    const id = process.env.PID;
    const add = await page.evaluate(async (pid) => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const r = await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: +pid, qty: 1, quantity: 1 }) });
      return r.status;
    }, id);
    // A NEW page load: the panel below is the one drawn with the page.
    await page.goto(BASE + '/product/' + SLUG + '/', { waitUntil: 'networkidle' });
    const inHtml = await page.evaluate(() => !!document.querySelector('#kcCart .kc-ship'));
    await page.locator('[data-kbb-open="cart"]').first().click();
    await page.waitForTimeout(900);
    await page.screenshot({ path: `${OUT}/cart-panel-bar-${w}.png` });
    const m = await page.evaluate(() => {
      const s = document.querySelector('#kcCart .kc-ship'), f = s && s.querySelector('.kc-fill');
      return { text: s ? s.innerText.trim() : null, fill: f ? f.style.width : null, bandHeight: s ? s.getBoundingClientRect().height : 0, scrollWidth: document.documentElement.scrollWidth };
    });
    console.log(w, JSON.stringify({ productId: id, addStatus: add, barInPageHtml: inHtml, ...m, errors }));
    await ctx.close();
  }
  await browser.close();
})();
