/*
 * Lane CAP: the checkout email capture, walked in Chromium on a preview
 * started by tools/cap-walk/preview.sh (owner.php, switch-on.php and
 * arm-reminder.php applied).
 *   node tools/cap-walk/cap-walk.mjs PORT WIDTH OUTDIR [typo|place]
 * Counts every POST /api/checkout-contact: none while typing, one on leaving.
 * `typo`: yahoo.co, leave, tap the "Did you mean" fix -> a second request.
 * `place`: then places a cash-on-delivery order.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';

const [PORT, WIDTH = '390', OUT = '.', MODE = ''] = process.argv.slice(2);
const BASE = `http://127.0.0.1:${PORT}`;
const W = Number(WIDTH);
const H = W < 600 ? 844 : 900;
const tag = `${W}${MODE ? '-' + MODE : ''}`;
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({
  viewport: { width: W, height: H }, deviceScaleFactor: W < 600 ? 2 : 1, hasTouch: W < 600, isMobile: W < 600,
  userAgent: W < 600
    ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36'
    : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
});
const STUB = fs.readFileSync(new URL('./stripe-stub.js', import.meta.url), 'utf8');
await ctx.route('**/js.stripe.com/**', (r) => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
await ctx.route('**/*.stripe.com/**', (r) => r.fulfill({ status: 204, body: '' }));
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
const captures = [];
page.on('request', (r) => { if (r.url().includes('/api/checkout-contact')) captures.push(r.postDataBuffer() ? String(r.postDataBuffer()).match(/name="email"\r\n\r\n([^\r]*)/)?.[1] : '?'); });
const statuses = [];
page.on('response', (r) => { if (r.url().includes('/api/checkout-contact')) statuses.push(r.status()); });

const results = [];
const check = (name, ok, detail = '') => results.push({ name, ok: !!ok, detail });

await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
await page.evaluate(async () => {
  await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json', 'X-KBB-Hm': '5000' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) });
});
await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
await page.waitForTimeout(300);

const field = page.locator('#billing_email');
const shot = async (name) => {
  const box = await field.boundingBox();
  const top = Math.max(0, Math.floor((box ? box.y : 0) - 90));
  await page.screenshot({ path: `${OUT}/cap-${tag}-${name}.png`, clip: { x: 0, y: top, width: W, height: Math.min(300, H - top) } });
};
const leave = async () => {
  const next = page.locator('#billing_first_name:visible, #billing_phone:visible').first();
  if (await next.count()) await next.click(); else await page.keyboard.press('Tab');
  await page.waitForTimeout(400);
};

const sw0 = await page.evaluate(() => document.documentElement.scrollWidth);
check('the page publishes the endpoint', await page.evaluate(() => !!(window.KBB.routes && window.KBB.routes.checkoutEmail)));
check('scrollWidth equals the width', sw0 === W, `${sw0}`);

const addr = MODE ? 'jovana8b@yahoo.co' : 'aisha.k@gmail.com';
await field.click();
await field.pressSequentially(addr, { delay: 25 });
await page.waitForTimeout(300);
check('no request while typing (' + addr.length + ' keys)', captures.length === 0, `${captures.length}`);
await shot('1-typing');

await leave();
check('one request on leaving the field', captures.length === 1 && captures[0] === addr, JSON.stringify(captures));
check('answered 204', statuses.every((s) => s === 204), JSON.stringify(statuses));
await shot('2-left');

await field.click(); await leave();
check('leaving again with the same address sends nothing', captures.length === 1, `${captures.length}`);

if (MODE) {
  const btn = page.locator('[data-efix-use]');
  check('the "Did you mean" fix is on show', await btn.count() === 1);
  if (W < 600) await btn.tap(); else await btn.click();
  await page.waitForTimeout(500);
  check('tapping the fix sends the corrected address, once', captures.length === 2 && captures[1] === 'jovana8b@yahoo.com', JSON.stringify(captures));
  await shot('3-fix-tapped');
}

if (MODE === 'place') {
  const fill = async (sel, v) => { if (await page.$(`${sel}:visible`)) { if (!(await page.inputValue(sel))) await page.fill(sel, v); } };
  await fill('#billing_phone', '0501234567');
  await fill('#billing_first_name', 'Jovana');
  await fill('#billing_last_name', 'Petrovic');
  await fill('#billing_address_1', 'Villa 12');
  await fill('#billing_address_2', 'Marina Walk');
  await fill('#billing_city', 'Dubai');
  const st = await page.$('#billing_state');
  if (st && (await st.evaluate((e) => e.tagName)) === 'SELECT') { if (!(await st.inputValue())) await page.selectOption('#billing_state', { index: 1 }); } else await fill('#billing_state', 'Dubai');
  await page.click('label[for="payment_method_cod"]');
  await page.waitForTimeout(300);
  const b = page.locator('[data-place]:visible').first();
  await b.scrollIntoViewIfNeeded();
  await b.click();
  let landed = false;
  try { await page.waitForURL(/checkout\/success|order-received|thank/, { timeout: 15000 }); landed = true; } catch (e) {}
  check('cash on delivery places the order', landed, page.url().replace(BASE, ''));
  if (landed) await page.screenshot({ path: `${OUT}/cap-${tag}-4-placed.png` });
  check('no further capture while placing', captures.length === 2, JSON.stringify(captures));
}

check('zero console errors', errors.length === 0, errors.join(' | '));
await browser.close();
let bad = 0;
for (const r of results) { if (!r.ok) bad++; console.log(`${r.ok ? 'PASS' : 'FAIL'}  [${tag}] ${r.name}${r.detail ? '  -- ' + r.detail : ''}`); }
process.exitCode = bad ? 1 : 0;
