/* Lane PO: console errors and horizontal overflow on every page type, 390 and 1280.
     node tools/po-walk/console.mjs PORT */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
const BASE = `http://127.0.0.1:${process.argv[2]}`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
for (const w of [390, 1280]) {
  const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: 900 } });
  await ctx.route('**stripe.com/**', (r) => r.abort());
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !/stripe/i.test(m.text())) errs.push(m.text()); });
  await page.goto(`${BASE}/product/co-glow-serum`);
  await page.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) }); });
  for (const p of ['/', '/shop/', '/product-category/po-serums/', '/brands/po-anua/', '/product/co-glow-serum', '/skincare-guide/', '/cart/', '/checkout/']) {
    const before = errs.length;
    const r = await page.goto(BASE + p, { waitUntil: 'load' });
    await page.waitForTimeout(300);
    const sw = await page.evaluate(() => document.documentElement.scrollWidth);
    console.log(`${w} ${p.padEnd(28)} ${r.status()} scrollWidth ${sw} errors ${errs.length - before}${errs.length > before ? ' ' + errs.slice(before).join(' | ').slice(0, 200) : ''}`);
  }
  await ctx.close();
}
await browser.close();
