/*
 * LANE ZM — every focusable text field on the shop, the owner app and the admin,
 * with its COMPUTED font-size, on a touch phone. iOS Safari zooms the page when
 * a field under 16px takes focus; this lists every field that would.
 *
 *   KBB_BASE=http://127.0.0.1:10640 KBB_APP=/<owner-app-secret> \
 *     node tools/zm-measure.cjs <out.json> [touch|desktop]
 *
 * Against tools/zm-preview.sh. "touch" is iPhone 390 / isMobile / hasTouch /
 * DPR 3, which is what puts `(hover:none)` and `(pointer:coarse)` on; "desktop"
 * is 1280 with a mouse, to prove a laptop resolves exactly what it did.
 *
 * Fields are read from the WHOLE document, hidden ones included (a closed
 * drawer's field still has a computed size), after the page's JS-built sheets
 * have been opened, so markup a script writes is measured too. For every
 * VISIBLE field it also focuses it and reads window.visualViewport.scale.
 * Chromium does not perform iOS's focus zoom, so scale 1 here proves only that
 * nothing on the page scripts a zoom; the font-size column is the iOS proof.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10640';
const APP = process.env.KBB_APP || '';
const OUT = process.argv[2] || 'zm-fields.json';
const MODE = process.argv[3] || 'touch';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

const TEXTLIKE = 'input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]):not([type=file])'
  + ':not([type=submit]):not([type=button]):not([type=reset]):not([type=image]):not([type=hidden]),'
  + 'select,textarea,[contenteditable]:not([contenteditable=false])';

async function fields(page, focus) {
  return page.evaluate(async ({ sel, focus }) => {
    const out = [];
    const seen = new Set();
    const name = (e) => {
      let s = e.tagName.toLowerCase();
      if (e.id) s += '#' + e.id;
      else if (e.getAttribute('name')) s += '[name=' + e.getAttribute('name') + ']';
      if (e.type && e.tagName === 'INPUT') s += '[type=' + e.getAttribute('type') + ']';
      const cls = (typeof e.className === 'string' ? e.className : '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
      if (cls.length) s += '.' + cls.join('.');
      const p = e.closest('[id],form[action],[class]');
      const host = e.parentElement && e.parentElement.closest('[id]');
      if (host) s = '#' + host.id + ' ' + s;
      return s;
    };
    for (const e of document.querySelectorAll(sel)) {
      if (e.closest('[contenteditable]:not([contenteditable=false])') !== e && e.closest('[contenteditable]:not([contenteditable=false])')) continue;
      const cs = getComputedStyle(e);
      const r = e.getBoundingClientRect();
      const visible = r.width > 0 && r.height > 0 && cs.visibility !== 'hidden';
      const key = name(e);
      if (seen.has(key)) continue;
      seen.add(key);
      const row = { field: key, px: parseFloat(cs.fontSize), visible, h: Math.round(r.height * 10) / 10, w: Math.round(r.width * 10) / 10 };
      if (focus && visible && !e.disabled && !e.readOnly) {
        e.focus({ preventScroll: true });
        await new Promise((ok) => setTimeout(ok, 30));
        row.scale = window.visualViewport ? window.visualViewport.scale : null;
        e.blur();
      }
      out.push(row);
    }
    return { fields: out, scrollWidth: document.documentElement.scrollWidth, vw: window.innerWidth };
  }, { sel: TEXTLIKE, focus });
}

const settle = (p, ms = 500) => p.waitForTimeout(ms);
// The quiz writes its contact step from JS only after six answers; the probe
// drops the same markup (`.field input`, `.field textarea`) into the page so the
// rule that styles it is measured without walking the quiz.
const probe = (html) => async (p) => { await p.evaluate((h) => { const d = document.createElement('div'); d.innerHTML = h; (document.querySelector('main,#app,body')).appendChild(d); }, html); };
const clickIf = async (p, sel) => { const h = await p.$(sel); if (h && await h.isVisible()) { await h.click().catch(() => {}); await settle(p, 700); return true; } return false; };

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const touch = MODE === 'touch';
  const opts = touch
    ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, userAgent: IPHONE }
    : { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, userAgent: MAC };
  const report = { mode: MODE, media: null, pages: {} };
  const record = async (p, label, extra) => {
    if (extra) await extra(p);
    const r = await fields(p, true);
    if (!report.media) report.media = await p.evaluate(() => ({ hoverNone: matchMedia('(hover:none)').matches, coarse: matchMedia('(pointer:coarse)').matches }));
    report.pages[label] = r;
    const small = r.fields.filter((f) => f.px < 16);
    process.stdout.write((small.length ? '  !! ' : '  ok ') + label + ' fields=' + r.fields.length + ' <16=' + small.length + ' sw=' + r.scrollWidth + '/' + r.vw + '\n');
  };
  const visit = async (p, label, url, extra) => {
    const res = await p.goto(BASE + url, { waitUntil: 'networkidle' }).catch((e) => ({ status: () => 'ERR ' + e.message }));
    await settle(p);
    await record(p, label + ' [' + res.status() + ']', extra);
  };

  // ── THE SHOP, GUEST ───────────────────────────────────────────────────────
  let ctx = await browser.newContext(opts);
  let p = await ctx.newPage();
  const SHOP = [
    ['home', '/'], ['home-search-open', '/', async (pg) => { await clickIf(pg, '[data-search-open],[aria-label*="earch" i]'); }],
    ['shop', '/shop/'], ['category', '/product-category/mac-cat-0/'], ['brands', '/brands/'], ['brand', '/brands/mac-0/'],
    ['collection', '/best-sellers/'], ['wishlist', '/my-wishlist/'],
    ['product', '/product/mac-anua/'], ['product-oos', '/product/mac-dalba/'],
    ['account-guest', '/my-account/'], ['forgot', '/my-account/forgot/'], ['reset', '/my-account/reset/1/x/'],
    ['track-order', '/track-my-order/'], ['contact', '/contact-us/'], ['skin-quiz', '/skin-quiz/', probe('<div class="field"><input id="zmQuizName"><input id="zmQuizEmail" inputmode="email"><textarea id="zmQuizNote"></textarea></div>')],
    ['routines', '/routines/'], ['review-wall', '/reviews/'], ['blog', '/skincare-guide/'],
    ['404', '/no-such-page-zm/'],
    ['ar-home', '/ar/'], ['ar-shop', '/ar/shop/'], ['ar-product', '/ar/product/mac-anua/'], ['ar-account', '/ar/my-account/'], ['ar-track', '/ar/track-my-order/'],
  ];
  for (const [label, url, extra] of SHOP) await visit(p, label, url, extra);

  // Cart: add a line (opens the JS-built drawer), then the page and checkout.
  await p.goto(BASE + '/product/mac-anua/', { waitUntil: 'networkidle' });
  await p.evaluate(async () => {
    const t = (document.querySelector('meta[name=csrf-token]') || {}).content || window.KBB.csrf;
    for (const id of [25, 26]) await fetch((window.KBB_BASE || '') + '/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': t, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
  });
  await visit(p, 'cart-drawer', '/product/mac-cosrx/', async (pg) => { await clickIf(pg, '[data-cart-open],.cart-btn,[aria-label*="art" i]'); });
  await visit(p, 'cart', '/cart/');
  await visit(p, 'checkout-guest', '/checkout/');
  await visit(p, 'ar-checkout', '/ar/checkout/');
  await visit(p, 'ar-cart', '/ar/cart/');

  // ── THE SHOP, SIGNED IN ───────────────────────────────────────────────────
  await p.goto(BASE + '/my-account/', { waitUntil: 'networkidle' });
  await p.fill('form[action*="login"] input[name=email]', 'sabina.dev@example.com').catch(() => {});
  await p.fill('form[action*="login"] input[name=password]', 'preview-password').catch(() => {});
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}), p.click('form[action*="login"] [type=submit]').catch(() => {})]);
  for (const [label, url] of [['account-in', '/my-account/'], ['addresses', '/my-account/edit-address/'], ['address-edit', '/my-account/edit-address/1/'],
    ['orders', '/my-account/orders/'], ['checkout-signed-in', '/checkout/'], ['ar-account-in', '/ar/my-account/']]) await visit(p, label, url);
  await ctx.close();

  // ── THE OWNER APP ─────────────────────────────────────────────────────────
  if (APP) {
    ctx = await browser.newContext(opts);
    p = await ctx.newPage();
    await visit(p, 'owner-enrol', APP + '/');
    await p.fill('input[name=email]', 'owner@example.com');
    await p.fill('input[name=pin]', '482615');
    await p.fill('input[name=device_name]', 'zm');
    await p.click('[data-enrol] button[type=submit]');
    await p.waitForLoadState('networkidle'); await settle(p, 900);
    for (const h of ['#/', '#/orders', '#/products', '#/customers', '#/notifications', '#/more']) {
      await p.evaluate((x) => { location.hash = x; }, h); await p.waitForLoadState('networkidle'); await settle(p, 600);
      await record(p, 'owner ' + h);
    }
    // The order filter, note and mark-paid sheets, and a product's four editors.
    await p.evaluate(() => { location.hash = '#/orders'; }); await settle(p, 700);
    for (const act of ['filters']) {
      if (await clickIf(p, '[data-act="' + act + '"]')) { await record(p, 'owner sheet ' + act); await p.keyboard.press('Escape'); await settle(p, 400); }
    }
    const ord = await p.$$eval('.row.ord[data-o]', (rs) => rs.map((r) => r.getAttribute('data-o')));
    if (ord[0]) {
      await p.evaluate((x) => { location.hash = '#/orders/' + x; }, ord[0]); await settle(p, 900);
      for (const act of ['note', 'paid']) {
        if (await clickIf(p, '[data-act="' + act + '"]')) { await record(p, 'owner order sheet ' + act); await p.keyboard.press('Escape'); await settle(p, 400); }
      }
    }
    await p.evaluate(() => { location.hash = '#/products'; }); await settle(p, 900);
    const pid = await p.$$eval('.list a.row', (rs) => rs.map((r) => r.getAttribute('href')));
    if (pid[1] || pid[0]) { await p.evaluate((x) => { location.hash = x.replace(/^.*#/, '#'); }, pid[1] || pid[0]); await settle(p, 900); }
    for (const ed of ['inv', 'price', 'cat', 'desc']) {
      if (await clickIf(p, '[data-ed="' + ed + '"]')) { await record(p, 'owner sheet ' + ed); await p.keyboard.press('Escape'); await settle(p, 400); }
    }
    await p.evaluate(() => { location.hash = '#/more'; }); await settle(p, 700);
    if (await clickIf(p, '[data-lock]')) await record(p, 'owner pin');
    await ctx.close();
  }

  // ── THE ADMIN, EVERY SCREEN IN ITS NAV ────────────────────────────────────
  ctx = await browser.newContext(opts);
  p = await ctx.newPage();
  await visit(p, 'admin-login', '/admin/login');
  await p.fill('input[name=email]', 'owner@example.com');
  await p.fill('input[name=password]', 'preview-password');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
  await settle(p, 1200);
  const screens = await p.evaluate(() => Array.from(new Set(Array.from(document.querySelectorAll('[data-go]')).map((e) => e.getAttribute('data-go')))));
  for (const s of screens) {
    await p.evaluate((x) => { try { window.go(x); } catch (e) {} }, s);
    await p.waitForLoadState('networkidle').catch(() => {}); await settle(p, 450);
    await record(p, 'admin ' + s);
  }
  for (const [label, url] of [['admin-updates', '/admin/updates'], ['admin-import-history', '/admin/import-history'], ['app-page', '/app/']]) await visit(p, label, url);
  await browser.close();

  // ── SUMMARY: every distinct field under 16px, and any focus that scaled ───
  const under = {};
  let total = 0;
  const scaled = [];
  for (const [page, r] of Object.entries(report.pages)) {
    for (const f of r.fields) {
      total++;
      if (f.scale !== undefined && f.scale !== 1) scaled.push(page + ' ' + f.field + ' scale=' + f.scale);
      if (f.px < 16) (under[f.px] = under[f.px] || []).push(page + ' :: ' + f.field);
    }
  }
  report.summary = { fieldsMeasured: total, under16: Object.values(under).reduce((a, b) => a + b.length, 0), minPx: Math.min(...Object.values(report.pages).flatMap((r) => r.fields.map((f) => f.px))), focusScaled: scaled };
  fs.writeFileSync(OUT, JSON.stringify(report, null, 1));
  process.stdout.write(JSON.stringify(report.summary) + '\n');
})();
