/**
 * Place order never answers with silence -- Lane PO hotfix, driven by
 * tests/Feature/CheckoutPlaceOrderNeverSilentTest.php.
 *
 * Firefox for Android draws no validation bubble, so the browser's own bubble
 * is SUPPRESSED here in every case (the `invalid` event's default is
 * prevented, which is what stops Chromium drawing it): whatever the shopper
 * is told, the page itself has to say.
 *
 * The rendered checkout is served from a file and every request it makes is
 * answered by page.route(), so nothing here reaches a server. It REPORTS; the
 * Pest test judges.
 *
 *   KBB_PO_HTML=<checkout.html> KBB_PO_BUILD=<public/build> \
 *   KBB_BROWSER_CHROME=<chrome> node tests/browser/po-place-notice.mjs
 */
import fs from 'fs';
import path from 'path';
import { createRequire } from 'module';

const require = createRequire(import.meta.url);
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = await import('/home/user/kbbstore/node_modules/playwright-core/index.mjs')); }

const HTML = fs.readFileSync(process.env.KBB_PO_HTML, 'utf8');
const DIAG = process.env.KBB_PO_DIAG ? fs.readFileSync(process.env.KBB_PO_DIAG, 'utf8') : null;
const BUILD = process.env.KBB_PO_BUILD;
const ORIGIN = 'http://localhost';

const STRIPE = `(function(){
  function El(){this.mount=function(t){var e=typeof t==='string'?document.querySelector(t):t;if(e)e.innerHTML='<span>4242</span>';};this.on=function(){};this.unmount=function(){};}
  window.Stripe=function(){return{elements:function(){return{create:function(){return new El();}};},
    confirmCardPayment:function(){return Promise.resolve({paymentIntent:{id:'pi_x',status:'succeeded'}});}};};
})();`;

const browser = await chromium.launch({ executablePath: process.env.KBB_BROWSER_CHROME });
const out = { ok: true, cases: {} };

async function open({ html = HTML, stripe = 'ok' } = {}) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0' });
  const page = await ctx.newPage();
  const seen = { posts: [], errors: [] };
  page.on('pageerror', (e) => seen.errors.push(e.message));
  await page.route('**/*', async (route) => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname === 'js.stripe.com') return stripe === 'blocked' ? route.abort() : route.fulfill({ contentType: 'application/javascript', body: STRIPE });
    if (u.origin !== ORIGIN) return route.abort();
    if (req.method() === 'POST') seen.posts.push(u.pathname);
    if (u.pathname.startsWith('/build/')) {
      const file = path.join(BUILD, u.pathname.slice('/build/'.length));
      return fs.existsSync(file) ? route.fulfill({ path: file }) : route.fulfill({ status: 404, body: '' });
    }
    if (u.pathname === '/checkout/place') {
      const card = (req.postData() || '').includes('stripe');
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(card
        ? { ok: true, action: 'confirm', client_secret: 'pi_x_secret_y', order: 'N1', amount: 1, return_url: ORIGIN + '/checkout/success?order=N1', success_url: '/checkout/success?order=N1' }
        : { ok: true, action: 'placed', order: 'N1', success_url: '/checkout/success?order=N1' }) });
    }
    if (u.pathname === '/checkout/success') return route.fulfill({ contentType: 'text/html', body: '<!doctype html><p>ok</p>' });
    if (u.pathname === '/checkout/' || u.pathname === '/checkout') return route.fulfill({ contentType: 'text/html', body: html });
    if (req.method() === 'POST') return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
    return route.fulfill({ status: 204, body: '' });
  });
  // No native bubble, in any case: Firefox for Android draws none.
  await page.addInitScript(() => { document.addEventListener('invalid', (e) => { e.preventDefault(); window.__bubbles = (window.__bubbles || 0) + 1; }, true); });
  await page.goto(ORIGIN + '/checkout/', { waitUntil: 'load' });
  await page.waitForTimeout(150);
  return { ctx, page, seen };
}

async function fill(page, skip = []) {
  const set = async (sel, v) => { if (!skip.includes(sel) && await page.$(`${sel}:visible`)) await page.fill(sel, v); };
  await set('#billing_first_name', 'Aisha Khan');
  await set('#billing_phone', '0501234567');
  await set('#billing_email', 'a@example.com');
  await set('#billing_address_1', 'Villa 12');
  await set('#billing_address_2', 'Marina Walk');
  if (!skip.includes('#billing_state')) {
    const st = await page.$('#billing_state');
    if (st && (await st.evaluate((e) => e.tagName)) === 'SELECT') await page.selectOption('#billing_state', 'Dubai');
    else await set('#billing_state', 'Dubai');
  }
}

async function press(page) {
  const btn = page.locator('.kbb-mobile-order [data-place]').first();
  await btn.scrollIntoViewIfNeeded();
  await btn.click();
  await page.waitForTimeout(250);
}

const state = (page, field) => page.evaluate((id) => {
  const note = document.getElementById('kbbPlaceNote');
  const el = id ? document.getElementById(id) : null;
  const err = id ? document.getElementById(id + '-kbberr') : null;
  return {
    note: note ? note.textContent.trim() : null,
    noteRole: note ? note.getAttribute('role') : null,
    noteAfterButton: !!note && !!note.previousElementSibling && note.previousElementSibling.hasAttribute('data-place'),
    fieldError: err ? err.textContent.trim() : null,
    describedBy: el ? el.getAttribute('aria-describedby') : null,
    ariaInvalid: el ? el.getAttribute('aria-invalid') : null,
    focused: document.activeElement ? document.activeElement.id : null,
    overlay: !!document.querySelector('.kbb-placing'),
  };
}, field);

try {
  for (const method of ['stripe', 'cod']) {
    const { ctx, page, seen } = await open();
    await fill(page, ['#billing_phone']);
    await page.click(`label[for="payment_method_${method}"]`);
    await press(page);
    const stopped = await state(page, 'billing_phone');
    await page.fill('#billing_phone', '0501234567');
    await page.waitForTimeout(100);
    const fixed = await state(page, 'billing_phone');
    out.cases['emptyPhone_' + method] = { stopped, fixed, posts: seen.posts, errors: seen.errors };
    await ctx.close();
  }

  // An unchosen emirate.
  {
    const { ctx, page, seen } = await open();
    await fill(page, ['#billing_state']);
    await page.click('label[for="payment_method_cod"]');
    await press(page);
    out.cases.emirate = { stopped: await state(page, 'billing_state'), posts: seen.posts, errors: seen.errors };
    await ctx.close();
  }

  // A box that is NOT rendered and is invalid (an autofilled short password in
  // the hidden account box) never blocks the order.
  {
    const { ctx, page, seen } = await open();
    await fill(page);
    await page.click('label[for="payment_method_cod"]');
    await page.evaluate(() => { document.getElementById('account_password_wrap').hidden = false; });
    await page.fill('#account_password', 'abc');
    await page.evaluate(() => { document.getElementById('account_password_wrap').hidden = true; });
    const invalidBefore = await page.evaluate(() => !document.getElementById('account_password').validity.valid);
    await press(page);
    await page.waitForURL(/checkout\/success/, { timeout: 6000 }).catch(() => {});
    out.cases.hiddenInvalid = { invalidBefore, url: new URL(page.url()).pathname, posts: seen.posts, errors: seen.errors };
    await ctx.close();
  }

  // Stripe.js blocked, card chosen: said beside the button, nothing posted.
  {
    const { ctx, page, seen } = await open({ stripe: 'blocked' });
    await fill(page);
    await page.click('label[for="payment_method_stripe"]');
    await press(page);
    out.cases.cardMissing = { state: await state(page, null), posts: seen.posts, errors: seen.errors };
    await ctx.close();
  }

  // Everything filled: both methods still place.
  for (const method of ['cod', 'stripe']) {
    const { ctx, page, seen } = await open();
    await fill(page);
    await page.click(`label[for="payment_method_${method}"]`);
    await press(page);
    await page.waitForURL(/checkout\/success/, { timeout: 6000 }).catch(() => {});
    out.cases['valid_' + method] = { url: new URL(page.url()).pathname, posts: seen.posts, errors: seen.errors };
    await ctx.close();
  }

  // The diagnostic panel, when the page carries it.
  if (DIAG) {
    const { ctx, page, seen } = await open({ html: DIAG });
    await fill(page, ['#billing_phone']);
    await page.click('label[for="payment_method_stripe"]');
    await press(page);
    out.cases.diag = { log: await page.textContent('#kbbDiagLog'), errors: seen.errors };
    await ctx.close();
  }
} catch (e) {
  out.ok = false;
  out.error = String(e && e.stack || e);
}

await browser.close();
process.stdout.write(JSON.stringify(out));
