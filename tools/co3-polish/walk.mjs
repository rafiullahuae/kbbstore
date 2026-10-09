/*
 * Lane CO (checkout polish) — the owner's 9 October checkout list, in a real
 * browser, at 390 and 1280:
 *
 *   node tools/co3-polish/walk.mjs PORT SERUM_ID [SHOTS_DIR]
 *
 * Stripe is stubbed (stripe-stub.js: an Express Checkout Element that reports
 * Apple Pay and Google Pay, or none with window.__noWallet). Proves: the coupon
 * box without its heading, the wallet row under it with its heading and OR line
 * (and none of the three, and no gap, without a wallet), equal section gaps,
 * the footer's last row and top padding, the Sign in window's three states and
 * its tick, a real sign-in that fills the form and then places a COD order with
 * no 419, no console errors, and no sideways scroll at 320 and 390.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PORT = process.argv[2] || '8961';
const BASE = `http://127.0.0.1:${PORT}`;
const SERUM = Number(process.argv[3] || 25);
const SHOTS = process.argv[4] || path.resolve(HERE, '../../docs/lane-co-shots/checkout-polish');
const STUB = fs.readFileSync(`${HERE}/stripe-stub.js`, 'utf8');
fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0;
const ok = (c, m) => { console.log(`   ${c ? 'PASS' : 'FAIL'}  ${m}`); if (!c) failures++; };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

async function open(width, { wallet = true } = {}) {
  const context = await browser.newContext({
    viewport: { width, height: width < 600 ? 844 : 900 },
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
  });
  const page = await context.newPage();
  const errors = [];
  const statuses = [];
  const refusals = [];
  const failed = [];
  // Last registered wins in Playwright: the abort first, the stub over it.
  await page.route('**stripe.com/**', r => r.abort());
  await page.route('**js.stripe.com/**', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
  page.on('pageerror', e => errors.push('pageerror: ' + e.message));
  /* The one 422 this walk provokes on purpose (the wrong password) is logged
     by Chromium as a console error; it is counted apart, and anything else in
     the console is a failure. A request that fails is named, so a blocked
     third-party host is told apart from a fault on the page. */
  page.on('console', m => {
    if (m.type() !== 'error') return;
    if (/status of 422/.test(m.text())) { refusals.push(m.text()); return; }
    errors.push('console: ' + m.text());
  });
  page.on('requestfailed', r => { if (!/stripe\.com/.test(r.url())) failed.push(r.url() + ' ' + (r.failure() || {}).errorText); });
  page.on('response', r => { if (r.request().method() === 'POST') statuses.push(`${r.status()} ${r.url().replace(BASE, '')}`); });
  await page.addInitScript((w) => { if (!w) window.__noWallet = true; }, wallet);
  return { context, page, errors, statuses, refusals, failed };
}

async function bag(page) {
  await page.goto(`${BASE}/__co/forget-carts`);
  await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
  await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
  const status = await page.evaluate(async (pid) => {
    const r = await fetch('/api/cart/add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
      body: JSON.stringify({ product_id: pid, quantity: 1 }),
    });
    return r.status;
  }, SERUM);
  if (status !== 200) console.log('   cart add answered', status);
}

const box = (page, sel) => page.evaluate((s) => {
  const e = document.querySelector(s);
  if (!e) return null;
  const r = e.getBoundingClientRect();
  return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), left: Math.round(r.left), width: Math.round(r.width), height: Math.round(r.height), visible: getComputedStyle(e).visibility !== 'hidden' && getComputedStyle(e).display !== 'none' && r.height > 0 };
}, sel);

/* The gap from the lowest visible thing in each numbered section to the next
   section's heading bar, and the block gaps either side of the coupon box. */
const gaps = (page) => page.evaluate(() => {
  const vis = (e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const secs = [...document.querySelectorAll('#customer_details > .sec')];
  const out = {};
  secs.forEach((s, i) => {
    if (!secs[i + 1]) return;
    let low = 0;
    s.querySelectorAll('*').forEach(e => { if (e.closest('h2') || !vis(e)) return; low = Math.max(low, e.getBoundingClientRect().bottom); });
    out[s.querySelector('h2 .n').textContent + '->' + secs[i + 1].querySelector('h2 .n').textContent] = Math.round(secs[i + 1].getBoundingClientRect().top - low);
  });
  const c = document.querySelector('.coupon').getBoundingClientRect();
  const t = document.querySelector('.co-titlebar').getBoundingClientRect();
  const next = [...document.querySelectorAll('[data-kbb-express-head], .formbox')].find(vis).getBoundingClientRect();
  out['coupon->next'] = Math.round(next.top - c.bottom);
  out['title->coupon'] = Math.round(c.top - t.bottom);
  return out;
});

async function clip(page, sel, file, pad = 8) {
  const b = await page.evaluate((s) => { const e = document.querySelector(s); if (!e) return null; e.scrollIntoView({ block: 'center', behavior: 'instant' }); const r = e.getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; }, sel);
  if (!b) { ok(false, `${sel} exists for ${file}`); return; }
  await page.waitForTimeout(120);
  const b2 = await page.evaluate((s) => { const r = document.querySelector(s).getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; }, sel);
  const vw = page.viewportSize().width;
  await page.screenshot({ path: `${SHOTS}/${file}`, clip: { x: Math.max(0, b2.x - pad), y: Math.max(0, b2.y - pad), width: Math.min(vw, b2.w + pad * 2), height: b2.h + pad * 2 } });
}

for (const width of [390, 1280]) {
  console.log(`\n== ${width}px, guest with a wallet`);
  const { context, page, errors, statuses, refusals, failed } = await open(width);
  await bag(page);
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-kbb-express-head]:not([hidden])', { timeout: 5000 }).catch(() => {});

  ok(!(await page.$('.coupon .ch')), 'coupon box has no "Have a discount code?" line');
  ok(await page.locator('#kbb_apply_coupon').isVisible(), 'coupon Apply button is there');
  const head = await box(page, '[data-kbb-express-head]');
  const row = await box(page, '[data-kbb-express]');
  const or = await box(page, '[data-kbb-express-divider]');
  const coupon = await box(page, '.coupon');
  const form = await box(page, '.formbox');
  ok(head && head.visible && row.visible && or.visible, 'wallet row, its heading and its OR line are shown');
  ok(head && head.top > coupon.bottom && row.top > head.top && or.top > row.top && form.top > or.bottom, 'order: coupon, heading, wallets, OR, then the form');
  const headText = await page.locator('[data-kbb-express-head]').textContent();
  ok(headText.trim() === 'Express checkout', `heading reads "${headText.trim()}"`);
  const orText = await page.locator('[data-kbb-express-divider]').textContent();
  ok(orText.trim() === 'OR', `divider reads "${orText.trim()}"`);
  ok(!(await page.$('.sec.pay [data-kbb-express]')), 'nothing wallet-shaped left under 4 Payment');
  const xs = await page.evaluate(() => { const h = getComputedStyle(document.querySelector('[data-kbb-express-head]')); return { color: h.color, size: h.fontSize, align: h.textAlign }; });
  console.log('   heading style', JSON.stringify(xs));
  const g = await gaps(page);
  console.log('   gaps', JSON.stringify(g), 'or->form', form.top - or.bottom, 'coupon->heading', head.top - coupon.bottom);
  const sec = Object.entries(g).filter(([k]) => k.includes('->') && /^\d/.test(k)).map(([, v]) => v);
  ok(sec.every(v => Math.abs(v - sec[0]) <= 1), `section gaps equal (${sec.join(', ')})`);
  ok(Math.abs(form.top - or.bottom - 16) <= 1 && Math.abs(head.top - coupon.bottom - 16) <= 1, 'coupon->heading and OR->form are the block gap (16)');

  await clip(page, '.coupon', `co-1-coupon-${width}.png`);
  await page.evaluate(() => window.scrollTo(0, 0));
  const top = await box(page, '.coupon');
  const bottomOfX = await box(page, '.formbox');
  await page.screenshot({ path: `${SHOTS}/co-2-express-${width}.png`, clip: { x: 0, y: Math.max(0, top.top - 20), width: width, height: Math.min(bottomOfX.top - top.top + 120, 700) } });
  // The two gaps the owner marked, with their neighbours.
  const r = await box(page, '#kbb_remember_field') || await box(page, '#customer_details > .sec:nth-child(2)');
  const pay = await box(page, '.sec.pay');
  await page.screenshot({ path: `${SHOTS}/co-3-section-gaps-${width}.png`, fullPage: true, clip: { x: 0, y: r.top - 60, width, height: Math.min(pay.top - r.top + 140, 900) } });

  // Footer
  const ft = await box(page, '.kbb-slimfoot');
  const fl = await page.evaluate(() => {
    const f = document.querySelector('.kbb-slimfoot');
    const inn = f.querySelector('.sf-in');
    const kids = [...inn.children].filter(e => !e.classList.contains('sf-top')).map(e => e.className);
    const end = f.querySelector('.sf-links-end');
    const brand = f.querySelector('.sf-brand');
    const cs = end ? getComputedStyle(end) : null;
    return {
      kids,
      padTop: Math.round(brand.getBoundingClientRect().top - f.getBoundingClientRect().top),
      inPadTop: getComputedStyle(inn).paddingTop,
      endLine: cs ? cs.borderTopWidth + ' ' + cs.borderTopColor : null,
      endAfterPay: !!end && !!end.previousElementSibling && end.previousElementSibling.classList.contains('sf-pay'),
      brandHasLinks: !!brand.querySelector('.sf-links'),
    };
  });
  console.log('   footer', JSON.stringify(fl));
  ok(fl.endAfterPay && !fl.brandHasLinks, 'policy links are the last row, after the payment marks, not under the brand');
  ok(fl.inPadTop === '30px', `footer top padding inside is ${fl.inPadTop}`);
  ok(fl.endLine && fl.endLine.startsWith('1px'), `grey line above the links (${fl.endLine})`);
  await page.screenshot({ path: `${SHOTS}/co-4-footer-${width}.png`, fullPage: true, clip: { x: 0, y: ft.top - 30, width, height: ft.height + 30 } });
  const links = await page.$$eval('.sf-links-end a', as => as.map(a => { const r = a.getBoundingClientRect(); a.scrollIntoView({ block: 'center', behavior: 'instant' }); const r2 = a.getBoundingClientRect(); const at = document.elementFromPoint(r2.left + r2.width / 2, r2.top + r2.height / 2); return !!at && (at === a || a.contains(at)); }));
  ok(links.length > 0 && links.every(Boolean), `footer links are what is under the pointer (${links.length})`);

  // The Sign in link and window
  const link = page.locator('[data-kbb-signin]');
  ok(await link.isVisible(), '"Sign in" is on the Contact bar');
  const lb = await page.evaluate(() => { const a = document.querySelector('[data-kbb-signin]'); const h = a.closest('h2'); a.scrollIntoView({ block: 'center', behavior: 'instant' }); const r = a.getBoundingClientRect(), hr = h.getBoundingClientRect(); const at = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { right: Math.round(hr.right - r.right), hit: !!at && (at === a || a.contains(at)), deco: getComputedStyle(a).textDecorationLine }; });
  ok(lb.hit && lb.deco.includes('underline'), `link is under the pointer and underlined (right inset ${lb.right}px)`);
  const contact = await box(page, '#customer_details > .sec:first-child > h2');
  await page.screenshot({ path: `${SHOTS}/co-5-signin-link-${width}.png`, fullPage: true, clip: { x: 0, y: contact.top - 10, width, height: contact.height + 20 } });
  await link.click();
  await page.waitForTimeout(300);
  ok(await page.evaluate(() => document.getElementById('kbbSignIn').open), 'window opens on click (no navigation)');
  ok(await page.evaluate(() => document.activeElement && document.activeElement.id === 'kbbSiEmail'), 'focus lands in the email box');
  ok(!(await page.locator('#kbbSignIn').getByText(/create/i).count()), 'no create-account option in the window');
  await page.screenshot({ path: `${SHOTS}/co-6-popup-login-${width}.png` });
  await page.keyboard.press('Escape');
  await page.waitForTimeout(350);
  ok(!(await page.evaluate(() => document.getElementById('kbbSignIn').open)), 'Esc closes it');
  await link.click();
  await page.waitForTimeout(250);
  await page.click('[data-si-to="forgot"]');
  ok(await page.locator('[data-si-pane="forgot"]').isVisible() && !(await page.locator('[data-si-pane="login"]').isVisible()), 'Forgot password? switches in place');
  await page.fill('#kbbSiFEmail', `nobody-${width}@example.com`);
  await page.screenshot({ path: `${SHOTS}/co-7-popup-forgot-${width}.png` });
  await page.click('[data-si-form="forgot"] [type="submit"]');
  await page.waitForSelector('[data-si-sent]:not([hidden])', { timeout: 5000 })
    .catch(async () => { console.log('   forgot error box:', await page.locator('[data-si-form="forgot"] [data-si-err]').textContent()); });
  const sent = await page.locator('[data-si-sent]').textContent();
  ok(/If that address has an account/.test(sent), 'forgot answers the one generic sentence');
  await page.screenshot({ path: `${SHOTS}/co-8-popup-forgot-sent-${width}.png` });
  await page.click('[data-si-pane="forgot"] [data-si-to="login"]');
  ok(await page.locator('[data-si-pane="login"]').isVisible(), '"Sign in" switches back to the login form');

  // Wrong password first, then the real one.
  await page.fill('#kbbSiEmail', 'aisha@example.com');
  await page.fill('#kbbSiPw', 'not-it');
  await page.click('[data-si-form="login"] [type="submit"]');
  await page.waitForSelector('[data-si-form="login"] [data-si-err]:not([hidden])', { timeout: 5000 });
  ok((await page.locator('[data-si-form="login"] [data-si-err]').textContent()).includes('did not match'), 'wrong password: inline error, window stays');
  await page.screenshot({ path: `${SHOTS}/co-9-popup-error-${width}.png` });

  // A shopper who typed a phone before signing in: the account's own value replaces it, nothing is blanked.
  const csrfBefore = await page.evaluate(() => window.KBB.csrf);
  await page.fill('#kbbSiPw', 'glow-secret-1');
  /* Reduced motion for the picture only: the tick is then drawn whole at once
     (the animated stroke takes half a second, and the window closes itself at
     0.8s), so the shot shows the finished tick rather than a frame of it. */
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.click('[data-si-form="login"] [type="submit"]');
  await page.waitForSelector('[data-si-pane="done"]:not([hidden])', { timeout: 5000 })
    .catch(async () => { console.log('   login error box:', await page.locator('[data-si-form="login"] [data-si-err]').textContent(), JSON.stringify(statuses.slice(-3))); });
  await page.waitForTimeout(120);
  await page.screenshot({ path: `${SHOTS}/co-10-popup-tick-${width}.png` });
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  await page.waitForFunction(() => !document.getElementById('kbbSignIn').open, null, { timeout: 3000 });
  await page.waitForTimeout(400);
  const after = await page.evaluate(() => ({
    csrf: window.KBB.csrf,
    tokenInputs: [...document.querySelectorAll('input[name="_token"]')].map(i => i.value),
    link: !!document.querySelector('[data-kbb-signin]'),
    acct: !!document.getElementById('create_account_field'),
    v: Object.fromEntries(['billing_email', 'billing_first_name', 'billing_phone', 'billing_address_1', 'billing_address_2', 'billing_state', 'billing_city', 'billing_country'].map(n => { const e = document.querySelector(`#kbbCheckoutForm [name="${n}"]`); return [n, e ? e.value : null]; })),
  }));
  console.log('   after sign-in', JSON.stringify(after.v));
  ok(after.csrf !== csrfBefore && after.tokenInputs.length > 0 && after.tokenInputs.every(t => t === after.csrf), 'new CSRF token in window.KBB and every _token input');
  ok(!after.link && !after.acct, 'Sign in link and Create-account row are gone');
  ok(after.v.billing_email === 'aisha@example.com' && after.v.billing_first_name === 'Aisha Rahman' && after.v.billing_phone === '+971501234567', 'name, email and phone filled');
  ok(/Villa 12/.test(after.v.billing_address_1 || '') && after.v.billing_state === 'Dubai', 'address and emirate filled');
  await page.evaluate(() => window.scrollTo(0, 0));
  const c1 = await box(page, '#customer_details');
  await page.screenshot({ path: `${SHOTS}/co-11-filled-${width}.png`, fullPage: true, clip: { x: 0, y: c1.top - 10, width, height: Math.min(c1.height, 1100) } });

  if (width === 390) {
    // Place the order (cash on delivery) on the page that signed in in place.
    await page.click('label[for="payment_method_cod"]');
    await page.waitForTimeout(200);
    const btn = page.locator('[data-place]:visible').first();
    await btn.scrollIntoViewIfNeeded();
    await btn.click();
    await page.waitForURL(/order-received|checkout\/success|thank/i, { timeout: 15000 }).catch(() => {});
    const placed = statuses.filter(s => s.includes('/checkout/place'));
    console.log('   place posts', JSON.stringify(placed), 'now at', page.url().replace(BASE, ''));
    ok(placed.length > 0 && placed.every(s => !s.startsWith('419')), 'Place order answered without a 419');
    ok(/order-received|success|thank/i.test(page.url()), 'landed on the order-received page');
    await page.screenshot({ path: `${SHOTS}/co-12-order-placed-${width}.png` });
  }

  /* A third-party host this sandbox cannot reach (its proxy re-signs TLS) is
     reported by Chromium as a console error too; those are excused only when
     every failed request was off this shop. */
  const offsiteCert = failed.some(f => !f.startsWith(BASE) && f.includes('ERR_CERT_AUTHORITY_INVALID'));
  const real = errors.filter(e => !(offsiteCert && /ERR_CERT_AUTHORITY_INVALID/.test(e)));
  ok(real.length === 0, `no console errors from the shop (${real.join(' | ')})`);
  ok(statuses.filter(s => s.startsWith('422 /checkout/sign-in')).length === 1, 'exactly one refused sign-in, the wrong password');
  ok(refusals.length === statuses.filter(s => s.startsWith('422')).length, `every console 422 is a refusal the walk can name (${statuses.filter(s => s.startsWith('422')).join(', ')})`);
  console.log('   POSTs', JSON.stringify(statuses));
  if (failed.length) console.log('   failed requests (not this page\'s):', JSON.stringify(failed));
  await context.close();
}

/* No wallet on this device: no heading, no OR line, and the form sits exactly
   where it does without the row at all. */
for (const width of [390, 1280]) {
  console.log(`\n== ${width}px, guest with NO wallet`);
  const { context, page, errors } = await open(width, { wallet: false });
  await bag(page);
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  const gone = await page.evaluate(() => ({
    head: !!document.querySelector('[data-kbb-express-head]'),
    row: !!document.querySelector('[data-kbb-express]'),
    or: !!document.querySelector('[data-kbb-express-divider]'),
  }));
  ok(!gone.head && !gone.row && !gone.or, 'heading, row and OR line all removed');
  const c = await box(page, '.coupon');
  const f = await box(page, '.formbox');
  if (!c || !f) { console.log('   not a checkout:', page.url(), (await page.content()).slice(0, 300)); failures++; await context.close(); continue; }
  ok(f.top - c.bottom === 16, `coupon -> form is the block gap (${f.top - c.bottom})`);
  await page.screenshot({ path: `${SHOTS}/co-13-no-wallet-${width}.png`, clip: { x: 0, y: Math.max(0, c.top - 20), width, height: 360 } });
  ok(errors.length === 0, `no console errors (${errors.join(' | ')})`);
  await context.close();
}

/* The owner's slider: "Space between blocks" at 28. Every section gap must
   follow it, not only the gaps around the coupon box. */
for (const width of [390, 1280]) {
  const { context, page } = await open(width, { wallet: false });
  await bag(page);
  await page.goto(`${BASE}/__co/block-gap/28`);
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  const g = await gaps(page);
  console.log(`\n== ${width}px, Space between blocks = 28`, JSON.stringify(g));
  const sec = Object.entries(g).filter(([k]) => /^\d/.test(k)).map(([, v]) => v);
  ok(sec.every(v => v >= 28 && v <= 29) && g['coupon->next'] === 28, `every section gap follows the slider (${sec.join(', ')}; coupon->form ${g['coupon->next']})`);
  const r = await box(page, '#kbb_remember_field');
  const pay = await box(page, '.sec.pay');
  await page.screenshot({ path: `${SHOTS}/co-3b-section-gaps-28-${width}.png`, fullPage: true, clip: { x: 0, y: r.top - 60, width, height: Math.min(pay.top - r.top + 140, 900) } });
  await page.goto(`${BASE}/__co/block-gap/16`);
  await context.close();
}

/* No sideways scroll, closed and open. */
for (const width of [320, 390]) {
  const { context, page } = await open(width);
  await bag(page);
  await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  const closed = await page.evaluate(() => document.documentElement.scrollWidth);
  if (!(await page.$('[data-kbb-signin]'))) console.log('   no link at', page.url(), await page.title());
  await page.click('[data-kbb-signin]');
  await page.waitForTimeout(300);
  const dialog = await page.evaluate(() => { const r = document.getElementById('kbbSignIn').getBoundingClientRect(); return { sw: document.documentElement.scrollWidth, left: Math.round(r.left), right: Math.round(r.right) }; });
  ok(closed === width && dialog.sw === width && dialog.left >= 16 && dialog.right <= width - 16, `${width}px: scrollWidth ${closed} closed, ${dialog.sw} open; window ${dialog.left}..${dialog.right}`);
  if (width === 320) await page.screenshot({ path: `${SHOTS}/co-14-popup-320.png` });
  await context.close();
}

await browser.close();
console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
process.exit(failures ? 1 : 0);
