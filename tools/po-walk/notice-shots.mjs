/* Lane PO hotfix: a Place order press with an empty phone, the browser's
   bubble suppressed (Firefox for Android draws none) -- what the page says.
   And the ?kbbdiag=1 panel after a press.
     node tools/po-walk/notice-shots.mjs PORT OUTDIR */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
const [PORT, OUT] = process.argv.slice(2);
const BASE = `http://127.0.0.1:${PORT}`;
const STUB = fs.readFileSync(new URL('./stripe-stub.js', import.meta.url), 'utf8');
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
async function shoot(width, locale, diag) {
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, userAgent: 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0' });
  await ctx.route('**js.stripe.com/**', (r) => r.fulfill({ contentType: 'application/javascript', body: STUB }));
  const page = await ctx.newPage();
  await page.addInitScript(() => { document.addEventListener('invalid', (e) => e.preventDefault(), true); });
  await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
  await page.goto(`${BASE}/product/co-glow-serum`);
  await page.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) }); });
  const pre = locale === 'ar' ? '/ar' : '';
  await page.goto(`${BASE}${pre}/checkout/${diag ? '?kbbdiag=1' : ''}`, { waitUntil: 'networkidle' });
  for (const [s, v] of [['#billing_first_name', 'Aisha Khan'], ['#billing_email', 'a@example.com'], ['#billing_address_1', 'Villa 12'], ['#billing_address_2', 'Marina']]) if (await page.$(`${s}:visible`)) await page.fill(s, v);
  await page.selectOption('#billing_state', { index: 1 });
  await page.click('label[for="payment_method_stripe"]');
  const btn = page.locator(width < 600 ? '.kbb-mobile-order [data-place]' : '.summary [data-place]').first();
  await btn.scrollIntoViewIfNeeded();
  await btn.click();
  await page.waitForTimeout(300);
  const tag = `${width}-${locale}${diag ? '-diag' : ''}`;
  await page.screenshot({ path: `${OUT}/hotfix-notice-field-${tag}.png` });
  if (!diag) {
    await btn.scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, 120));
    await page.waitForTimeout(150);
    await page.screenshot({ path: `${OUT}/hotfix-notice-button-${tag}.png` });
  }
  const r = await page.evaluate(() => ({ note: document.getElementById('kbbPlaceNote')?.textContent, err: document.getElementById('billing_phone-kbberr')?.textContent, sw: document.documentElement.scrollWidth }));
  console.log(tag, JSON.stringify(r));
  await ctx.close();
}
for (const w of [390, 1280]) for (const l of ['en', 'ar']) await shoot(w, l, false);
await shoot(390, 'en', true);
await browser.close();
