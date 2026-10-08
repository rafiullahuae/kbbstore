/*
 * Lane TY — the owner's card order (#56181) in a real browser, end to end:
 * a guest bag of several lines (a set, a "Buy these together" pair, names
 * with "&"), the checkout, Place order on the card, the stubbed bank says
 * succeeded, and the order-received page that opens while /checkout/card/paid
 * is still running under the tick (Lane PO's timing).
 *
 *   node tools/ty-shots/walk.mjs PORT LABEL [SHOTS_DIR]
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.argv[2] || '8875';
const LABEL = process.argv[3] || 'run';
const BASE = `http://127.0.0.1:${PORT}`;
const SHOTS = process.argv[4] || path.resolve(HERE, '../../docs/lane-ty-shots');
const STATE = path.resolve(HERE, `../../storage/ty-logs/preview-${PORT}/state`);
const IDS = JSON.parse(fs.readFileSync(`${STATE}/ids.json`, 'utf8'));
const STUB = fs.readFileSync(path.resolve(HERE, '../co-card-walk/stripe-stub.js'), 'utf8');
fs.mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const out = {};

for (const width of [390, 1280]) {
  const context = await browser.newContext({
    viewport: { width, height: width < 600 ? 844 : 900 },
    userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
  });
  const page = await context.newPage();
  const errors = [];
  const nav = [];
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
  page.on('request', r => { if (r.url().includes('/checkout/')) nav.push(r.method() + ' ' + r.url().replace(BASE, '')); });
  await page.route('**stripe.com/**', r => r.abort());
  await page.route('**js.stripe.com/**', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
  await page.addInitScript(() => {
    window.__onConfirm = (secret) => fetch('/__co/confirmed/' + String(secret).split('_secret')[0]);
  });

  await page.goto(`${BASE}/product/ty-nida-cream`, { waitUntil: 'domcontentloaded' });
  const add = (body, url = '/api/cart/add') => page.evaluate(async ([u, b]) => {
    const r = await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify(b) });
    return r.status;
  }, [url, body]);
  const st = [];
  for (const k of ['nida', 'arencia1', 'arencia2', 'rohto', 'shiseido', 'jumiso', 'set']) st.push(await add({ product_id: IDS[k], quantity: k === 'shiseido' ? 2 : 1 }));
  st.push(await add({ items: [{ product_id: IDS.medicube }, { product_id: IDS.tocobo }] }, '/api/cart/add-together'));

  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  await page.fill('#billing_email', `owner${width}@example.com`);
  await page.fill('#billing_phone', '0501234567');
  await page.fill('#billing_first_name', 'Rafi');
  if (await page.$('#billing_last_name:visible')) await page.fill('#billing_last_name', 'Ullah');
  await page.fill('#billing_address_1', 'Villa 12');
  if (await page.$('#billing_address_2:visible')) await page.fill('#billing_address_2', 'Marina Walk');
  if (await page.$('#billing_city:visible')) await page.fill('#billing_city', 'Dubai');
  const state = await page.$('#billing_state');
  if (state) {
    const tag = await state.evaluate(e => e.tagName);
    if (tag === 'SELECT') await page.selectOption('#billing_state', { index: 1 }); else await page.fill('#billing_state', 'Dubai');
  }
  await page.click('label[for="payment_method_stripe"]');
  await page.waitForTimeout(300);
  const btn = page.locator('[data-place]:visible').first();
  await btn.scrollIntoViewIfNeeded();
  await btn.click();
  try { await page.waitForURL(/checkout\/success/, { timeout: 20000 }); }
  catch (e) {
    await page.screenshot({ path: `${SHOTS}/${LABEL}-stuck-${width}.png`, fullPage: true });
    console.log('STUCK', width, JSON.stringify({ errors, nav, text: (await page.innerText('body')).slice(0, 1500) }));
    throw e;
  }
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(400);

  const m = await page.evaluate(() => {
    const secs = [...document.querySelectorAll('.co-received .sec')];
    const sum = secs.find(s => (s.querySelector('h2')?.textContent || '').includes('Your order'));
    const box = (el) => el ? Math.round(el.getBoundingClientRect().height) : null;
    const vis = (el) => !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
    const h2 = sum?.querySelector('h2');
    return {
      url: location.pathname + location.search,
      summarySecHeight: box(sum),
      summaryBelowHeading: sum && h2 ? Math.round(sum.getBoundingClientRect().bottom - h2.getBoundingClientRect().bottom) : null,
      lines: sum ? sum.querySelectorAll('.ci').length : null,
      visibleLines: sum ? [...sum.querySelectorAll('.ci')].filter(vis).length : null,
      totalRow: !!sum?.querySelector('.sumrow.tot'),
      totalVisible: vis(sum?.querySelector('.sumrow.tot')),
      deliveryRow: !!sum && [...sum.querySelectorAll('.sumrow')].some(r => r.textContent.includes('Delivery')),
      summaryText: (sum?.innerText || '').slice(0, 400),
      account: !!document.querySelector('.co-acct'),
      payment: [...document.querySelectorAll('.co-fact')].map(f => f.innerText.replace(/\s+/g, ' ')).join(' | '),
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
  m.adds = st; m.errors = errors; m.nav = nav;
  out[width] = m;
  console.log(width, JSON.stringify(m, null, 1));
  const sum = page.locator('.co-received .sec', { hasText: 'Your order' }).first();
  await sum.scrollIntoViewIfNeeded().catch(() => {});
  await page.screenshot({ path: `${SHOTS}/${LABEL}-${width}.png`, fullPage: true });
  await context.close();
}
fs.writeFileSync(`${SHOTS}/${LABEL}-report.json`, JSON.stringify(out, null, 1));
await browser.close();
