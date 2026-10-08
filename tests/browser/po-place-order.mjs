/**
 * Place order, in Chromium, against the checkout this application renders --
 * Lane PO. Driven by tests/Feature/CheckoutPlaceOrderOnceTest.php, which writes
 * the page and reads the JSON this prints.
 *
 * NOTHING HERE TALKS TO A SERVER. The rendered checkout is served out of a file
 * and every request it makes is answered by page.route(): /checkout/place with
 * whatever the case needs, js.stripe.com with a stand-in for Stripe.js, the
 * received page with a one-line document. So it measures the page's own
 * behaviour -- the box, the tick, the storage -- and nothing else.
 *
 * It REPORTS; the Pest test judges. Every case returns the overlay's life as a
 * list of events (added, class, removed), whether the toast ever showed, where
 * the browser ended up, and what is in localStorage.
 *
 *   KBB_PO_HTML=<checkout.html> KBB_PO_BUILD=<public/build> \
 *   KBB_BROWSER_CHROME=<chrome> node tests/browser/po-place-order.mjs
 */
import fs from 'fs';
import path from 'path';
import { createRequire } from 'module';

const require = createRequire(import.meta.url);
let chromium;
try { ({ chromium } = require('playwright')); } catch { ({ chromium } = await import('/home/user/kbbstore/node_modules/playwright-core/index.mjs')); }

const HTML = fs.readFileSync(process.env.KBB_PO_HTML, 'utf8');
const BUILD = process.env.KBB_PO_BUILD;
const ORIGIN = 'http://localhost';

/* Stripe.js, as much of it as the checkout uses. window.__po.stripe picks the
   answer: 'ok', 'decline' or '3ds' (a bank modal appended to <body> at the top
   z-index -- where Stripe puts its own -- answered by its Submit button). */
const STRIPE = `(function(){
  function El(){this.mount=function(t){var e=typeof t==='string'?document.querySelector(t):t;if(e)e.innerHTML='<span style="display:block;font:14px/1.2 inherit">4242 4242 4242 4242</span>';};this.on=function(){};this.unmount=function(){};}
  window.Stripe=function(){return{elements:function(){return{create:function(){return new El();}};},
    confirmCardPayment:function(){
      var mode=(window.__po&&window.__po.stripe)||'ok';
      window.__poLog&&window.__poLog('stripe-confirm');
      if(mode==='decline')return new Promise(function(r){setTimeout(function(){r({error:{message:'Your card was declined.'}});},150);});
      if(mode==='3ds')return new Promise(function(r){
        var b=document.createElement('div');b.id='stubBank';
        b.setAttribute('style','position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center');
        b.innerHTML='<div style="background:#fff;padding:20px"><input id="stubOtp"><button id="stubOk" type="button">Submit</button></div>';
        document.body.appendChild(b);
        b.querySelector('#stubOk').addEventListener('click',function(){b.remove();r({paymentIntent:{id:'pi_po',status:'succeeded'}});});
      });
      return new Promise(function(r){setTimeout(function(){r({paymentIntent:{id:'pi_po',status:'succeeded'}});},300);});
    }};};
})();`;

const RECORDER = () => {
  const log = (n, x) => {
    try {
      const all = JSON.parse(sessionStorage.getItem('__po') || '[]');
      all.push([n, Math.round(performance.timeOrigin + performance.now()), x || '']);
      sessionStorage.setItem('__po', JSON.stringify(all));
    } catch (e) {}
  };
  window.__poLog = log;
  const isBox = (n) => n && n.nodeType === 1 && n.classList && n.classList.contains('kbb-placing');
  new MutationObserver((muts) => {
    for (const m of muts) {
      if (m.type === 'childList') {
        m.addedNodes.forEach((n) => { if (isBox(n)) log('box-added', n.className); });
        m.removedNodes.forEach((n) => { if (isBox(n)) log('box-removed'); });
      } else if (isBox(m.target)) log('box-class', m.target.className);
      else if (m.target.id === 'toast' && m.target.classList.contains('on')) log('toast-on', m.target.textContent);
    }
  }).observe(document, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
};

const browser = await chromium.launch({ executablePath: process.env.KBB_BROWSER_CHROME });
const out = { ok: true, cases: {} };

async function open({ html = HTML, place = null, stripe = 'ok', blockStorage = false, context = null } = {}) {
  const ctx = context || await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  const seen = { posts: [], errors: [], paidAnsweredAt: null, navAt: null };
  page.on('pageerror', (e) => seen.errors.push(e.message));
  await page.route('**/*', async (route) => {
    const req = route.request();
    const u = new URL(req.url());
    if (u.hostname === 'js.stripe.com') return route.fulfill({ contentType: 'application/javascript', body: STRIPE });
    if (u.origin !== ORIGIN) return route.abort();
    if (req.method() === 'POST') seen.posts.push(u.pathname);
    if (u.pathname.startsWith('/build/')) {
      const file = path.join(BUILD, u.pathname.slice('/build/'.length));
      if (fs.existsSync(file)) return route.fulfill({ path: file });
      return route.fulfill({ status: 404, body: '' });
    }
    if (u.pathname === '/checkout/place' && req.method() === 'POST') {
      const p = place || { status: 200, body: { ok: true, action: 'placed', order: 'PO1', success_url: '/checkout/success?order=PO1' } };
      return route.fulfill({ status: p.status, contentType: 'application/json', body: JSON.stringify(p.body) });
    }
    if (u.pathname === '/checkout/success') {
      if (seen.navAt === null) seen.navAt = Date.now();
      return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>ok</title><p>Thank you</p>' });
    }
    // The card's report to the shop, answered slowly on purpose: the tick
    // must not wait for it, and the received page must.
    if (u.pathname === '/checkout/card/paid') {
      await new Promise((r) => setTimeout(r, 700));
      seen.paidAnsweredAt = Date.now();
      return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
    }
    if (u.pathname === '/checkout/' || u.pathname === '/checkout') return route.fulfill({ contentType: 'text/html', body: html });
    if (req.method() === 'POST') return route.fulfill({ contentType: 'application/json', body: '{"ok":true}' });
    return route.fulfill({ status: 204, body: '' });
  });
  await page.addInitScript(RECORDER);
  await page.addInitScript(([mode, block]) => {
    window.__po = { stripe: mode };
    if (block) Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('blocked', 'SecurityError'); } });
  }, [stripe, blockStorage]);
  await page.goto(ORIGIN + '/checkout/', { waitUntil: 'load' });
  await page.waitForTimeout(150);
  return { ctx, page, seen };
}

async function fill(page, { email = 'buyer@example.com' } = {}) {
  const set = async (sel, v) => { if (await page.$(`${sel}:visible`)) { if (!(await page.inputValue(sel))) await page.fill(sel, v); } };
  await set('#billing_first_name', 'Aisha Khan');
  await set('#billing_phone', '0501234567');
  await set('#billing_email', email);
  await set('#billing_address_1', 'Villa 12');
  await set('#billing_address_2', 'Marina Walk');
  const st = await page.$('#billing_state');
  if (st && (await st.evaluate((e) => e.tagName)) === 'SELECT') {
    if (!(await st.inputValue())) await page.selectOption('#billing_state', 'Dubai');
  } else await set('#billing_state', 'Dubai');
  // Leave the last box so its `change` fires, as a shopper's tap elsewhere does.
  await page.focus('#billing_first_name');
}

async function choose(page, method) {
  await page.click(`label[for="payment_method_${method}"]`);
  await page.waitForTimeout(100);
}

async function press(page) {
  const btn = page.locator('[data-place]:visible').first();
  await btn.scrollIntoViewIfNeeded();
  await btn.click();
}

async function events(page) {
  return page.evaluate(() => { try { return JSON.parse(sessionStorage.getItem('__po') || '[]'); } catch (e) { return []; } });
}

const summarise = (ev) => ({
  added: ev.filter((e) => e[0] === 'box-added').length,
  removed: ev.filter((e) => e[0] === 'box-removed').length,
  done: ev.some((e) => e[0] === 'box-class' && /is-done/.test(e[2])),
  toast: ev.filter((e) => e[0] === 'toast-on').map((e) => e[2]),
});

const stored = (page) => page.evaluate(() => { try { return localStorage.getItem('kbb.checkout.details.v1'); } catch (e) { return 'THREW'; } });

try {
  /* 1. Cash on delivery: one box, the tick in it, then the received page. */
  {
    const { ctx, page, seen } = await open();
    await fill(page); await choose(page, 'cod'); await press(page);
    await page.waitForURL(/checkout\/success/, { timeout: 8000 });
    out.cases.cod = { ...summarise(await events(page)), url: new URL(page.url()).pathname, posts: seen.posts, errors: seen.errors };
    await ctx.close();
  }

  /* 2. Card, no challenge: the box never closes; nothing is inert while
        Stripe confirms; no /checkout/card/paid round trip. */
  {
    const { ctx, page, seen } = await open({ stripe: 'ok', place: { status: 200, body: { ok: true, action: 'confirm', client_secret: 'pi_po_secret_x', order: 'PO2', amount: 14000, return_url: ORIGIN + '/checkout/success?order=PO2', success_url: '/checkout/success?order=PO2' } } });
    await fill(page); await choose(page, 'stripe'); await press(page);
    await page.waitForFunction(() => (JSON.parse(sessionStorage.getItem('__po') || '[]')).some((e) => e[0] === 'stripe-confirm'), null, { timeout: 5000 });
    const during = await page.evaluate(() => ({ box: !!document.querySelector('.kbb-placing.is-up'), inert: document.querySelectorAll('body > [inert]').length }));
    await page.waitForURL(/checkout\/success/, { timeout: 8000 });
    const ev = await events(page);
    const tick = ev.find((e) => e[0] === 'box-class' && /is-done/.test(e[2]));
    out.cases.card = { ...summarise(ev), during, tickAt: tick ? tick[1] : null, paidAnsweredAt: seen.paidAnsweredAt, navAt: seen.navAt, url: new URL(page.url()).pathname, posts: seen.posts, errors: seen.errors };
    await ctx.close();
  }

  /* 3. Card with 3-D Secure: the bank's modal is on top and answerable, our
        box is still under it, and it turns into the tick when the bank is done. */
  {
    const { ctx, page, seen } = await open({ stripe: '3ds', place: { status: 200, body: { ok: true, action: 'confirm', client_secret: 'pi_po_secret_y', order: 'PO3', amount: 14000, return_url: ORIGIN + '/checkout/success?order=PO3', success_url: '/checkout/success?order=PO3' } } });
    await fill(page); await choose(page, 'stripe'); await press(page);
    await page.waitForSelector('#stubBank', { timeout: 5000 });
    await page.waitForTimeout(100);
    await page.focus('#stubOtp');
    await page.keyboard.type('123456');
    const during = await page.evaluate(() => {
      const otp = document.getElementById('stubOtp');
      const at = document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2);
      return {
        box: !!document.querySelector('.kbb-placing.is-up'),
        bankOnTop: !!at && !!at.closest('#stubBank'),
        focusInBank: document.activeElement === otp,
        typed: otp.value,
        inert: document.querySelectorAll('body > [inert]').length,
      };
    });
    await page.click('#stubOk');
    await page.waitForURL(/checkout\/success/, { timeout: 8000 });
    out.cases.threeDS = { ...summarise(await events(page)), during, url: new URL(page.url()).pathname, posts: seen.posts, errors: seen.errors };
    await ctx.close();
  }

  /* 4. A refusal from place(): the box closes once and says why. */
  {
    const { ctx, page, seen } = await open({ place: { status: 422, body: { ok: false, error: 'Nope, not today.' } } });
    await fill(page); await choose(page, 'cod'); await press(page);
    await page.waitForSelector('#kbbPlacingNotice', { timeout: 5000 });
    await page.waitForTimeout(400);
    out.cases.refused = { ...summarise(await events(page)), notice: await page.textContent('#kbbPlacingNotice'), url: new URL(page.url()).pathname, errors: seen.errors };
    await ctx.close();
  }

  /* 5. A declined card: the box closes once, Stripe's sentence on the card form. */
  {
    const { ctx, page, seen } = await open({ stripe: 'decline', place: { status: 200, body: { ok: true, action: 'confirm', client_secret: 'pi_po_secret_z', order: 'PO5', amount: 14000, return_url: ORIGIN + '/checkout/success?order=PO5', success_url: '/checkout/success?order=PO5' } } });
    await fill(page); await choose(page, 'stripe'); await press(page);
    await page.waitForFunction(() => { const e = document.querySelector('[data-kbb-card-error]'); return e && e.textContent.trim() !== ''; }, null, { timeout: 5000 });
    await page.waitForTimeout(300);
    out.cases.declined = { ...summarise(await events(page)), error: (await page.textContent('[data-kbb-card-error]')).trim(), url: new URL(page.url()).pathname, errors: seen.errors };
    await ctx.close();
  }

  /* 6. Remember: saved as fields are left, restored on the next visit, the
        clear link shown, a server-filled box never overwritten, untick erases. */
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    let { page, seen } = await open({ context: ctx });
    await fill(page);
    if (await page.$('#kbb_coupon_code')) { await page.fill('#kbb_coupon_code', 'SAVE10'); await page.focus('#billing_phone'); }
    await choose(page, 'cod');
    if (await page.$('#create_account')) await page.check('#create_account');
    if (await page.$('#account_password:visible')) { await page.fill('#account_password', 'hunter2hunter2'); await page.focus('#billing_phone'); }
    const first = await stored(page);
    await page.close();

    ({ page } = await open({ context: ctx }));
    const restored = await page.evaluate(() => {
      const v = (id) => (document.getElementById(id) || {}).value || '';
      const c = document.getElementById('kbbRememberClear');
      return {
        name: v('billing_first_name'), phone: v('billing_phone'), email: v('billing_email'),
        building: v('billing_address_1'), area: v('billing_address_2'), state: v('billing_state'),
        coupon: v('kbb_coupon_code'), password: v('account_password'),
        clearShown: !!c && c.classList.contains('on') && getComputedStyle(c).visibility === 'visible',
        tick: !!(document.getElementById('kbb_remember') || {}).checked,
        cls: performance.getEntriesByType('layout-shift').reduce((s, e) => s + (e.hadRecentInput ? 0 : e.value), 0),
      };
    });

    // The link: erases the copy and empties what it filled.
    await page.click('#kbbRememberClear');
    const afterClear = await page.evaluate(() => ({
      stored: (() => { try { return localStorage.getItem('kbb.checkout.details.v1'); } catch (e) { return 'THREW'; } })(),
      name: document.getElementById('billing_first_name').value,
      clearShown: document.getElementById('kbbRememberClear').classList.contains('on'),
    }));
    await page.close();

    // A box the server filled is never overwritten; the empty ones still are.
    ({ page } = await open({ context: ctx }));
    await fill(page); await page.close();
    const serverHtml = HTML.replace(/(<input[^>]*id="billing_email"[^>]*?)value="[^"]*"/, '$1').replace(/(<input[^>]*id="billing_email")/, '$1 value="server@example.com"');
    ({ page } = await open({ context: ctx, html: serverHtml }));
    const server = await page.evaluate(() => ({ email: document.getElementById('billing_email').value, name: document.getElementById('billing_first_name').value }));

    // Untick: the copy is gone at once.
    await page.click('label[for="kbb_remember"]');
    const unticked = await stored(page);
    await page.close();
    await ctx.close();

    out.cases.remember = { first, restored, afterClear, server, unticked, errors: seen.errors };
  }

  /* 7. Storage blocked (private mode, site data off): nothing throws, the
        order still places. */
  {
    const { ctx, page, seen } = await open({ blockStorage: true });
    await fill(page); await choose(page, 'cod'); await press(page);
    await page.waitForURL(/checkout\/success/, { timeout: 8000 });
    out.cases.blocked = { url: new URL(page.url()).pathname, errors: seen.errors };
    await ctx.close();
  }
} catch (e) {
  out.ok = false;
  out.error = String(e && e.stack || e);
}

await browser.close();
process.stdout.write(JSON.stringify(out));
