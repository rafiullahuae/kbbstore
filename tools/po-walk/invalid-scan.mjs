/* Lane PO hotfix: every :invalid control in the checkout form, rendered or not,
   before and after a shopper fills every box they can see.
     node tools/po-walk/invalid-scan.mjs PORT [method] */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
const BASE = `http://127.0.0.1:${process.argv[2]}`; const METHOD = process.argv[3] || 'stripe';
const STUB = (await import('fs')).readFileSync(new URL('./stripe-stub.js', import.meta.url), 'utf8');
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0' });
await ctx.route('**js.stripe.com/**', (r) => r.fulfill({ contentType: 'application/javascript', body: STUB }));
const page = await ctx.newPage();
await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await page.goto(`${BASE}/product/co-glow-serum`);
await page.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) }); });
await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
const scan = () => page.evaluate(() => [...document.querySelectorAll('#kbbCheckoutForm :invalid')].filter((e) => e.tagName !== 'FORM' && e.tagName !== 'FIELDSET').map((e) => {
  const cs = getComputedStyle(e);
  return { id: e.id, name: e.name, type: e.type, required: e.required, hiddenAncestor: !!e.closest('[hidden]'), display: cs.display, visible: e.getClientRects().length > 0, msg: e.validationMessage };
}));
console.log('ON LOAD', JSON.stringify(await scan(), null, 0));
for (const [sel, v] of [['#billing_first_name', 'Aisha Khan'], ['#billing_last_name', 'Khan'], ['#billing_phone', '0501234567'], ['#billing_email', 'a@example.com'], ['#billing_address_1', 'Villa 12'], ['#billing_address_2', 'Marina'], ['#billing_city', 'Dubai']]) {
  if (await page.$(`${sel}:visible`)) await page.fill(sel, v);
}
const st = await page.$('#billing_state:visible');
if (st) { if ((await st.evaluate((e) => e.tagName)) === 'SELECT') await page.selectOption('#billing_state', { index: 1 }); else await page.fill('#billing_state', 'Dubai'); }
await page.click(`label[for="payment_method_${METHOD}"]`);
console.log('FILLED ', JSON.stringify(await scan(), null, 0));
await browser.close();
