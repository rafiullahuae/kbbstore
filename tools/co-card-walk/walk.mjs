/*
 * Lane CO — the owner's 8 October sequence in a real browser.
 *
 *   node tools/co-card-walk/walk.mjs PORT SERUM_ID JELLY_ID [SHOTS_DIR] [only-refusal]
 *
 * Stripe is stubbed on both halves (stripe-stub.js for js.stripe.com in the
 * page, preview-index.php for api.stripe.com on the server): this proves the
 * page and the shop agree, not that Stripe does.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.argv[2] || '8861';
const BASE = `http://127.0.0.1:${PORT}`;
const SERUM = Number(process.argv[3]);
const JELLY = Number(process.argv[4]);
const SHOTS = process.argv[5] || path.resolve(HERE, '../../docs/lane-co-shots');
const ONLY = process.argv[6] || '';
const STUB = fs.readFileSync(`${HERE}/stripe-stub.js`, 'utf8');
fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0;
const ok = (c, m) => { console.log(`   ${c ? 'PASS' : 'FAIL'}  ${m}`); if (!c) failures++; };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

async function open(width, stubAnswer) {
  const context = await browser.newContext({
    viewport: { width, height: width < 600 ? 844 : 900 },
    // The cart refuses a HeadlessChrome user agent (Cart Tracking's bot gate).
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
  });
  const page = await context.newPage();
  const errors = [];
  const refusals = [];
  const posts = [];
  await page.route('**stripe.com/**', r => r.abort());
  await page.route('**js.stripe.com/**', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  /* A refusal IS a 422 — place() has always answered a refused basket that
     way — and Chromium logs every 4xx response as a console error. Those are
     the refusals this walk provokes on purpose, so they are counted
     separately; anything else in the console is a failure. */
  page.on('console', m => {
    if (m.type() !== 'error') return;
    if (/Failed to load resource: the server responded with a status of 422/.test(m.text())) { refusals.push(m.text()); return; }
    errors.push('console: ' + m.text());
  });
  page.on('request', r => { if (r.method() === 'POST') posts.push(r.url().replace(BASE, '')); });
  await page.addInitScript((a) => {
    if (a) window.__stripeStub = a;
    window.__onConfirm = (secret) => fetch('/__co/confirmed/' + String(secret).split('_secret')[0]);
  }, stubAnswer || null);
  return { context, page, errors, posts, refusals };
}

async function bag(page, ids) {
  for (const slug of ['co-glow-serum', 'co-fwee-jelly-pot']) await page.goto(`${BASE}/__co/in-stock/${slug}`);
  await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
  for (const id of ids) {
    await page.evaluate(async (pid) => {
      await fetch('/api/cart/add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
        body: JSON.stringify({ product_id: pid, quantity: 1 }),
      });
    }, id);
  }
}

async function checkout(page, method = 'stripe') {
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  await page.fill('#billing_email', 'buyer@example.com');
  await page.fill('#billing_phone', '0501234567');
  await page.fill('#billing_first_name', 'Aisha');
  if (await page.$('#billing_last_name:visible')) await page.fill('#billing_last_name', 'Khan');
  await page.fill('#billing_address_1', 'Villa 12');
  if (await page.$('#billing_address_2:visible')) await page.fill('#billing_address_2', 'Marina Walk');
  if (await page.$('#billing_city:visible')) await page.fill('#billing_city', 'Dubai');
  const state = await page.$('#billing_state');
  if (state) {
    const tag = await state.evaluate(e => e.tagName);
    if (tag === 'SELECT') await page.selectOption('#billing_state', { index: 1 }); else await page.fill('#billing_state', 'Dubai');
  }
  await page.click(`label[for="payment_method_${method}"]`);
  await page.waitForTimeout(250);
}

/* The Place order button the shopper can see, clicked where it is drawn --
   and proved to be the thing under the pointer first (CLAUDE.md: clickable on
   the first try). */
async function place(page) {
  const btn = page.locator('[data-place]:visible').first();
  await btn.scrollIntoViewIfNeeded();
  const hit = await btn.evaluate((el) => {
    const r = el.getBoundingClientRect();
    const at = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return !!at && (at === el || el.contains(at));
  });
  ok(hit, 'Place order is the element under the pointer');
  await btn.click();
}

async function dialogOpen(page) {
  await page.waitForSelector('dialog#kbbSoldOut[open]', { timeout: 5000 });
  return page.evaluate(() => {
    const d = document.getElementById('kbbSoldOut');
    const r = d.getBoundingClientRect();
    const go = d.querySelector('button');
    const b = go && !go.hidden ? go.getBoundingClientRect() : null;
    const hit = b ? document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2) === go : null;
    return {
      text: d.innerText, width: Math.round(r.width), height: Math.round(r.height),
      focusInside: d.contains(document.activeElement), modal: d.matches(':modal'),
      buttonHit: hit, buttonHeight: b ? Math.round(b.height) : null,
      scrollWidth: document.documentElement.scrollWidth, vw: window.innerWidth,
    };
  });
}

if (!ONLY) for (const width of [1280, 390]) {
  console.log(`\n=== ${width}: sold out -> popup -> Remove and continue -> card goes through ===`);
  const { context, page, errors, posts } = await open(width);
  await bag(page, [SERUM, JELLY]);
  await page.goto(`${BASE}/__co/sold-out/co-fwee-jelly-pot`);
  await checkout(page);

  await place(page);
  let d = await dialogOpen(page);
  console.log('   dialog', JSON.stringify(d));
  ok(d.text.includes('fwee - Lip&Cheek Glowy Jelly Pot - Compote is sold out'), 'the dialog names the sold-out product');
  ok(!d.text.includes('Glow Serum'), 'the in-stock line is not listed');
  ok(d.modal && d.focusInside, 'modal, with focus inside it');
  ok(d.buttonHit, '"Remove and continue" answers a real click');
  ok(d.scrollWidth <= d.vw, `no horizontal scroll (${d.scrollWidth} <= ${d.vw})`);
  await page.screenshot({ path: `${SHOTS}/co-1-popup-${width}.png` });

  await page.keyboard.press('Escape');
  await page.waitForTimeout(150);
  ok(!(await page.$('dialog#kbbSoldOut')), 'Esc closes it, nothing removed');
  ok((await page.textContent('#kbbSummary')).includes('fwee'), 'the line is still in the bag after Esc');

  await place(page);
  await dialogOpen(page);
  const before = await page.evaluate(() => document.querySelector('.js-total')?.textContent.trim());
  await page.click('dialog#kbbSoldOut button');
  await page.waitForSelector('dialog#kbbSoldOut', { state: 'detached', timeout: 5000 });
  await page.waitForTimeout(300);
  const after = await page.evaluate(() => ({
    total: document.querySelector('.js-total')?.textContent.trim(),
    summary: document.querySelector('#kbbSummary .co-items')?.innerText || '',
    browsed: document.getElementById('kbbBrowsedList')?.innerText || '',
    email: document.getElementById('billing_email')?.value,
    card: !!document.querySelector('#kbb-card-number [data-stub-card]'),
    chosen: document.querySelector('input[name="payment_method"]:checked')?.value,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  console.log('   after', JSON.stringify({ before, ...after }));
  ok(!after.summary.includes('fwee') && after.summary.includes('Glow Serum'), 'the sold-out line is gone, the serum stays');
  ok(after.total !== before, `the total moved (${before} -> ${after.total})`);
  ok(after.email === 'buyer@example.com', 'what the shopper typed is still there');
  ok(after.card && after.chosen === 'stripe', 'the card fields are mounted again in the new box, card still chosen');
  await page.screenshot({ path: `${SHOTS}/co-2-after-remove-${width}.png` });

  await place(page);
  await page.waitForURL(/checkout\/success/, { timeout: 15000 });
  await page.waitForLoadState('networkidle');
  const received = await page.evaluate(() => document.body.innerText);
  ok(/order/i.test(received) && !/could not find/i.test(received), 'the order-received page shows the order');
  ok(posts.filter(p => p.startsWith('/checkout/place')).length === 3, `three presses of Place order, three posts (${posts.filter(p => p.startsWith('/checkout/place')).length})`);
  ok(posts.includes('/checkout/card/paid'), 'the card report was sent');
  await page.screenshot({ path: `${SHOTS}/co-3-confirmed-${width}.png` });
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await context.close();
}

{
  console.log('\n=== 1280: Stripe refuses to open the payment once, then the retry goes through ===');
  const { context, page, errors } = await open(1280);
  await bag(page, [SERUM]);
  await page.goto(`${BASE}/__co/refuse-next`);
  await checkout(page);
  await place(page);
  await page.waitForFunction(() => (document.querySelector('[data-kbb-card-error]')?.textContent || '').length > 0, null, { timeout: 8000 });
  const first = await page.textContent('[data-kbb-card-error]');
  console.log('   first press:', first);
  await page.locator('#kbb-card-error').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${SHOTS}/co-4-${ONLY ? 'before' : 'after'}-refused-first-1280.png` });
  await place(page);
  try {
    await page.waitForURL(/checkout\/success/, { timeout: 6000 });
    ok(true, 'the second press reached the order-received page');
    await page.screenshot({ path: `${SHOTS}/co-4-after-retry-confirmed-1280.png` });
  } catch {
    await page.waitForTimeout(500);
    const second = await page.textContent('[data-kbb-card-error]');
    console.log('   second press:', second);
    ok(false, 'the second press did not go through: ' + second);
    await page.locator('#kbb-card-error').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${SHOTS}/co-4-${ONLY ? 'before' : 'after'}-retry-1280.png` });
  }
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await context.close();
}

if (!ONLY) {
  console.log('\n=== 1280: 3-D Secure (4000 0025 0000 3155) — the bank step, then paid ===');
  const { context, page, errors } = await open(1280, { paymentIntent: { id: 'x', status: 'succeeded' }, __delay: 1500 });
  await bag(page, [SERUM]);
  await checkout(page);
  await place(page);
  await page.waitForTimeout(700);
  const locked = await page.evaluate(() => [...document.querySelectorAll('[data-place]')].every(b => b.disabled));
  ok(locked, 'Place order stays locked while the bank step is open');
  await page.waitForURL(/checkout\/success/, { timeout: 15000 });
  ok(true, '3-D Secure card reached the order-received page');
  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await context.close();

  console.log('\n=== 390: cash on delivery, only the sold-out line -> the bag is empty ===');
  const c2 = await open(390);
  // Only the serum in the bag, and then the shelf empties under the shopper.
  await bag(c2.page, [SERUM]);
  await c2.page.goto(`${BASE}/__co/sold-out/co-glow-serum`);
  await checkout(c2.page, 'cod');
  await place(c2.page);
  const d = await dialogOpen(c2.page);
  ok(d.text.includes('Glow Serum is sold out'), 'cash on delivery gets the same dialog');
  await c2.page.screenshot({ path: `${SHOTS}/co-5-popup-cod-390.png` });
  await c2.page.click('dialog#kbbSoldOut button');
  await c2.page.waitForFunction(() => /empty/i.test(document.querySelector('#kbbSoldOut [role=status]')?.textContent || ''), null, { timeout: 5000 });
  const link = await c2.page.evaluate(() => { const a = document.querySelector('#kbbSoldOut a'); return { text: a.textContent, href: a.getAttribute('href'), focused: document.activeElement === a }; });
  ok(link.href === '/shop/' && link.focused, `the empty bag says so and offers the shop (${JSON.stringify(link)})`);
  await c2.page.screenshot({ path: `${SHOTS}/co-6-empty-390.png` });
  ok(c2.errors.length === 0, 'no console errors' + (c2.errors.length ? ': ' + c2.errors.join(' | ') : ''));
  await c2.context.close();
}

await browser.close();
console.log(`\n${failures === 0 ? 'ALL PASS' : failures + ' FAILED'}`);
process.exit(failures === 0 ? 0 : 1);
