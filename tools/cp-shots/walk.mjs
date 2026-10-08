/*
 * Lane CP — the checkout coupon box, walked in Chromium with every field filled.
 *
 *   node tools/cp-shots/walk.mjs PORT TAG [SHOTS_DIR]
 *
 * For EN and AR at 390 and 1280: fill name, phone, email, building, area,
 * emirate, country, notes, the delivery option and a NON-default payment method
 * (cash on delivery), then try an invalid code, an expired code and a valid one,
 * then remove it. Records, for each step: whether the page navigated, every
 * field that lost its value, whether the payment choice moved, whether the card
 * element's mount box was replaced or re-created, and where the message showed.
 * Then the cart page's coupon box, once per width, for comparison.
 *
 * Layout is MEASURED here (a harness may measure; the shop's script may not).
 * Preview: tools/cp-shots/preview.sh PORT [GIT-REF].
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.argv[2] || '11431';
const TAG = process.argv[3] || 'before';
const BASE = `http://127.0.0.1:${PORT}`;
const SHOTS = process.argv[4] || path.resolve(HERE, '../../docs/lane-cp-shots');
const STUB = fs.readFileSync(path.resolve(HERE, '../co-card-walk/stripe-stub.js'), 'utf8');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
fs.mkdirSync(SHOTS, { recursive: true });

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const report = {};

const FILL = {
  billing_first_name: ['Aisha Khan', 'عائشة خان'],
  billing_phone: ['0501234567', '0501234567'],
  billing_email: ['aisha@example.com', 'aisha@example.com'],
  billing_address_1: ['Villa 12', 'فيلا 12'],
  billing_address_2: ['Marina Walk', 'ممشى المارينا'],
  customer_note: ['Ring the bell twice', 'اطرق الجرس مرتين'],
};

async function snapshot(page) {
  return page.evaluate(() => {
    const out = {};
    document.querySelectorAll('#kbbCheckoutForm input, #kbbCheckoutForm select, #kbbCheckoutForm textarea').forEach((e) => {
      if (e.type === 'hidden' || e.name === '_token') return;
      const k = e.type === 'radio' ? e.name + '=' + e.value : e.name;
      out[k] = (e.type === 'radio' || e.type === 'checkbox') ? e.checked : e.value;
    });
    return out;
  });
}

async function fillAll(page, lang) {
  const i = lang === 'ar' ? 1 : 0;
  for (const [name, vals] of Object.entries(FILL)) {
    if (await page.$(`[name="${name}"]:visible`)) await page.fill(`[name="${name}"]`, vals[i]);
  }
  await page.selectOption('#billing_state', { index: 2 });
  await page.selectOption('#billing_country', 'AE');
  await page.check('#shipping_method_0');
  await page.waitForTimeout(800);
  if (!(await page.$('label[for="payment_method_cod"]'))) console.error('NO COD', await page.evaluate(() => document.getElementById('payment')?.innerHTML.slice(0, 600)), await page.evaluate(() => location.href));
  await page.click('label[for="payment_method_cod"]');
  await page.waitForTimeout(400);
  // Mark the nodes a re-render would replace, so "kept" is measured, not assumed.
  await page.evaluate(() => {
    document.querySelectorAll('[data-kbb-card-el]').forEach((b) => { b.dataset.cpMark = '1'; });
    const p = document.getElementById('payment'); if (p && p.firstElementChild) p.firstElementChild.dataset.cpMark = '1';
    const f = document.getElementById('billing_first_name'); if (f) f.dataset.cpMark = '1';
  });
}

async function message(page) {
  return page.evaluate(() => {
    const vis = (el) => el && el.offsetParent !== null && getComputedStyle(el).visibility !== 'hidden' && getComputedStyle(el).opacity !== '0';
    const toast = document.getElementById('toast');
    const inline = document.querySelector('.coupon [role="alert"], .coupon [role="status"], .coupon .co-cmsg');
    return {
      toast: toast && toast.classList.contains('on') ? toast.textContent.trim() : null,
      inline: vis(inline) ? inline.textContent.trim() : null,
      inlineColour: inline ? getComputedStyle(inline).color : null,
      couponBox: document.getElementById('kbb_coupon_code')?.value ?? null,
      discountRow: [...document.querySelectorAll('#kbbSummary .js-coupons .sumrow, .kbb-order-slot .js-coupons .sumrow')].map((r) => r.textContent.replace(/\s+/g, ' ').trim())[0] || null,
      total: document.querySelector('#kbbSummary .js-total, .kbb-order-slot .js-total')?.textContent.trim() || null,
      paymentMarked: !!document.querySelector('#payment [data-cp-mark]'),
      cardBoxMarked: document.querySelectorAll('[data-kbb-card-el][data-cp-mark]').length,
      cardBoxes: document.querySelectorAll('[data-kbb-card-el]').length,
      stripeCreates: (window.__stripeCalls || []).filter((c) => /create/.test(JSON.stringify(c))).length,
      firstNameNodeKept: !!document.querySelector('#billing_first_name[data-cp-mark]'),
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

function diff(a, b) {
  const lost = [];
  for (const k of Object.keys(a)) if (JSON.stringify(a[k]) !== JSON.stringify(b[k])) lost.push(`${k}: ${JSON.stringify(a[k])} -> ${JSON.stringify(b[k])}`);
  return lost;
}

async function run(lang, width) {
  const pre = lang === 'ar' ? '/ar' : '';
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, userAgent: UA });
  const page = await ctx.newPage();
  const errors = [];
  let navs = 0;
  let posts = [];
  await page.route('**stripe.com/**', (r) => r.abort());
  await page.route('**js.stripe.com/**', (r) => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('framenavigated', (f) => { if (f === page.mainFrame()) navs++; });
  page.on('request', (r) => { if (r.method() === 'POST') posts.push(r.url().replace(BASE, '')); });
  await page.addInitScript(() => {
    window.__cls = 0;
    try { new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); } catch (e) {}
  });

  await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
  for (const id of [25, 26]) {
    await page.evaluate(async (pid) => {
      await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: pid, quantity: 1 }) });
    }, id);
  }
  await page.goto(`${BASE}${pre}/checkout/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  // CP_DROP_ROUTE=1: the page without its published coupon route -- what an
  // older bundle, or a layout older than its bundle, does. checkout.js then
  // takes its fallback, which on main posts to the cart endpoint and RELOADS.
  if (process.env.CP_DROP_ROUTE) await page.evaluate(() => { delete window.KBB.routes.checkoutCoupon; });
  const key = `${lang}-${width}`;
  const r = (report[key] = { steps: {} });
  r.clsLoad = await page.evaluate(() => window.__cls);

  await fillAll(page, lang);
  if (process.env.CP_DEBUG) await page.evaluate(() => { window.__sc = []; document.querySelector('.coupon').addEventListener('scroll', (e) => { const b = document.getElementById('kbb_apply_coupon'); window.__sc.push(e.target.scrollLeft + ' ' + (document.activeElement && (document.activeElement.id || document.activeElement.tagName)) + ' disabled=' + b.disabled + ' busy=' + b.getAttribute('aria-busy') + ' msg=' + !!document.getElementById('kbbCouponMsg') + ' ' + Math.round(performance.now())); }); document.addEventListener('click', () => window.__sc.push('click ' + Math.round(performance.now())), true); });
  const filled = await snapshot(page);
  r.filled = filled;

  const steps = [
    ['invalid', 'NOPE', 'click'],
    ['expired', 'OLD20', 'enter'],
    ['minimum', 'BIG900', 'click'],
    ['valid', 'SAVE10', 'enter'],
  ];

  for (const [name, code, how] of steps) {
    navs = 0; posts = [];
    const clsBefore = await page.evaluate(() => window.__cls);
    await page.fill('#kbb_coupon_code', code);
    const answer = page.waitForResponse((res) => res.url().includes('/coupon'), { timeout: 15000 }).catch(() => null);
    if (how === 'enter') await page.press('#kbb_coupon_code', 'Enter');
    else await page.click('#kbb_apply_coupon');
    const res = await answer;
    await page.waitForTimeout(250);
    const after = await snapshot(page).catch(() => ({}));
    r.steps[name] = {
      how,
      status: res ? res.status() : null,
      navigated: navs > 0,
      posts,
      lost: diff(filled, after).filter((l) => !l.startsWith('coupon_code')),
      ...(await message(page)),
      clsDelta: +((await page.evaluate(() => window.__cls)) - clsBefore).toFixed(4),
    };
    // The picture that matters for this step: the coupon box and the fields under it.
    await page.locator('.coupon').scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, -80));
    if (process.env.CP_DEBUG) console.error(name, await page.evaluate(() => [document.querySelector('.coupon').scrollLeft, ...window.__sc]));
    if (name === 'invalid' || name === 'valid') {
      await page.screenshot({ path: `${SHOTS}/${TAG}-${name}-${lang}-${width}.png` });
    }
  }

  // Remove (if the page offers a way to).
  const rm = await page.$('[data-kbb-coupon-remove]:visible');
  if (rm) {
    navs = 0; posts = [];
    const answer = page.waitForResponse((res) => res.url().includes('/coupon'), { timeout: 15000 }).catch(() => null);
    await rm.click();
    await answer;
    await page.waitForTimeout(250);
    const after = await snapshot(page);
    r.steps.remove = { navigated: navs > 0, posts, lost: diff(filled, after).filter((l) => !l.startsWith('coupon_code')), ...(await message(page)) };
  } else {
    r.steps.remove = 'no remove control on the checkout page';
  }

  // Enter must never place the order. A browser that submits the form from
  // the focused coupon box without a keydown Enter (a phone's Go key) is
  // modelled by requestSubmit() with the box focused: count what it posts.
  navs = 0; posts = [];
  await page.fill('#kbb_coupon_code', 'NOPE');
  await page.focus('#kbb_coupon_code');
  await page.evaluate(() => document.getElementById('kbbCheckoutForm').requestSubmit());
  await page.waitForTimeout(2500);
  r.goKey = { posts: [...posts], navigated: navs > 0, overlay: await page.evaluate(() => !!document.querySelector('.kbb-placing.on, [data-placing].on, .placing-on')) };
  r.placePosts = posts.filter((p) => p.includes('/checkout/place')).length;
  r.errors = errors;
  await ctx.close();
}

async function cartPage(width) {
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, userAgent: UA });
  const page = await ctx.newPage();
  let navs = 0;
  page.on('framenavigated', (f) => { if (f === page.mainFrame()) navs++; });
  await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
  await page.evaluate(async () => {
    await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) });
  });
  await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
  const out = { box: (await page.$('#kbbCartCoupon')) ? '#kbbCartCoupon' : null };
  for (const code of ['NOPE', 'SAVE10']) {
    navs = 0;
    const input = page.locator('#kbbCartCoupon').first();
    if (!(await input.count())) { out[code] = 'no coupon box on /cart/'; continue; }
    await input.fill(code);
    const answer = page.waitForResponse((res) => res.url().includes('/coupon'), { timeout: 15000 }).catch(() => null);
    await input.press('Enter');
    await answer;
    await page.waitForTimeout(250);
    out[code] = await page.evaluate(() => ({
      toast: document.getElementById('toast')?.classList.contains('on') ? document.getElementById('toast').textContent.trim() : null,
      inline: document.querySelector('#kbbCartNotices')?.textContent.trim() || null,
      inlineAbove: (() => { const n = document.querySelector('#kbbCartNotices'), c = document.getElementById('kbbCartCoupon'); return n && c ? Math.round(c.getBoundingClientRect().top - n.getBoundingClientRect().top) : null; })(),
    }));
    out[code].navigated = navs > 0;
  }
  report[`cart-${width}`] = out;
  await ctx.close();
}

// /checkout/coupon is throttled 20 a minute per address, and one run makes
// six requests: wait the minute out between runs rather than photograph a 429.
const PAUSE = Number(process.env.CP_PAUSE ?? 62000);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let first = true;
const ONLY = process.env.CP_ONLY || '';
for (const lang of ['en', 'ar']) for (const width of [390, 1280]) { if (ONLY && ONLY !== `${lang}-${width}`) continue; if (!first) await sleep(PAUSE); first = false; await run(lang, width); }
if (!process.env.CP_NO_CART) for (const width of [390, 1280]) { await sleep(PAUSE); await cartPage(width); }

fs.writeFileSync(path.resolve(HERE, `../../storage/cp-logs/walk-${TAG}.json`), JSON.stringify(report, null, 2));
console.log(JSON.stringify(report, (k, v) => (k === 'filled' ? undefined : v), 2));
await browser.close();
