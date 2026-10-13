/* 2.60.495 (the 13 October checkout and cart layout): the owner's bag on the
   preview shop, walked in Chromium at 390 and 1280 -- cart, checkout, the
   coupon applied and removed from the field inside the summary, the box at
   the foot of the page opened, Cash on delivery chosen -- with pictures and
   the numbers that matter, and every console error and failed request.
   BASE=http://127.0.0.1:PORT IDS=1,2,3,4,5,6 OUT=docs/lane-co13-shots node tools/cpb-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, IDS = (process.env.IDS || '').split(',').map(Number);
const OUT = path.resolve(__dirname, '..', process.env.OUT || 'docs/lane-co13-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const [label, vp] of [['390', { width: 390, height: 844, isMobile: true, hasTouch: true, userAgent: UA }], ['1280', { width: 1280, height: 900, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' }]]) {
    const ctx = await b.newContext({ viewport: { width: vp.width, height: vp.height }, isMobile: !!vp.isMobile, hasTouch: !!vp.hasTouch, userAgent: vp.userAgent, deviceScaleFactor: 2 });
    const pg = await ctx.newPage();
    const errs = [];
    pg.on('pageerror', (e) => errs.push('pageerror ' + e.message));
    pg.on('console', (m) => { if (m.type() === 'error') errs.push('console ' + m.text()); });
    pg.on('requestfailed', (r) => { if (!/stripe|tabby|tamara|google|facebook|tiktok|snap/.test(r.url())) errs.push('failed ' + r.url() + ' ' + ((r.failure() || {}).errorText || '')); });
    pg.on('response', (r) => { if (r.status() >= 400) errs.push('http ' + r.status() + ' ' + r.url()); });
    await pg.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    await pg.evaluate(async (ids) => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      for (const [i, id] of ids.entries()) {
        await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, quantity: i === 0 ? 2 : 1 }) });
      }
    }, IDS);
    const r = report[label] = { errors: errs };

    // Cart page.
    await pg.goto(BASE + '/cart/', { waitUntil: 'networkidle' }); await wait(500);
    await pg.screenshot({ path: `${OUT}/cart-${label}.png`, fullPage: true });
    r.cart = await pg.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      backlink: !!document.querySelector('.backlink'),
      box: (document.querySelector('.cpg-tb2') || {}).innerText || null,
      button: (() => { const a = document.querySelector('.cpg-cobar .cobtn'); if (!a) return null; const c = getComputedStyle(a); return { text: a.innerText, bg: c.backgroundColor, font: c.fontSize, transform: c.textTransform }; })(),
    }));

    // Checkout.
    await pg.goto(BASE + '/checkout/', { waitUntil: 'networkidle' }); await wait(700);
    await pg.screenshot({ path: `${OUT}/checkout-${label}.png`, fullPage: true });
    r.checkout = await pg.evaluate(() => {
      const q = (s) => document.querySelector(s);
      const box = (s) => { const e = q(s); if (!e) return null; const b = e.getBoundingClientRect(); return { top: Math.round(b.top + scrollY), bottom: Math.round(b.bottom + scrollY), left: Math.round(b.left), width: Math.round(b.width), height: Math.round(b.height) }; };
      return {
        scrollWidth: document.documentElement.scrollWidth,
        headCart: !!q('.co-head .co-cart'), secureBadge: !!q('.co-head .secure'), title: !!q('.co-titlebar'),
        steps: [...document.querySelectorAll('#customer_details > .sec > h2')].map((h) => h.innerText.replace(/\s+/g, ' ').trim()),
        firstField: (q('#customer_details .sec .form-row') || {}).id || null,
        country: (q('#billing_country') || {}).selectedOptions ? q('#billing_country').selectedOptions[0].text + ' = ' + q('#billing_country').value : null,
        rememberTick: (() => { const t = q('#kbb_remember'); return t ? { present: true, checked: t.checked, shown: !!t.offsetParent } : null; })(),
        place: [...document.querySelectorAll('[data-place]')].map((x) => x.textContent.trim()),
        trust: [...document.querySelectorAll('.trust > span:first-child')].map((x) => x.textContent.trim())[0] || null,
        cardSecure: !!q('.kbb-card-secure'), paySecure: (q('.co-psec') || {}).textContent || null,
        note: (() => { const n = q('#kbbDeliveryNote'); return n ? { hidden: n.hidden, text: n.textContent.trim() } : null; })(),
        thumbs: !!q('.kbb-mobile-thumbs'),
        apple: (() => { const li = q('[data-kbb-ap-li]'); return li ? { hidden: li.hidden, name: (li.querySelector('input') || {}).name || '' } : null; })(),
        gaps: { head: box('.co-head'), summary: box('#kbbSummary'), form: box('#customer_details') },
      };
    });

    // Apply GLOW from the field inside the summary, then take it off again.
    if (label === '390') { await pg.click('#kbbSumRow'); await wait(400); }
    await pg.fill('#kbb_coupon_code', 'glow');
    await pg.click('#kbb_apply_coupon'); await wait(1500);
    await pg.screenshot({ path: `${OUT}/checkout-coupon-${label}.png`, fullPage: true });
    r.coupon = await pg.evaluate(() => ({
      ticket: (document.querySelector('#kbbCouponMsg') || {}).innerText || null,
      rowWas: [...document.querySelectorAll('#kbbSumRow s')].filter((s) => !s.hidden && s.offsetParent).map((s) => s.textContent.trim()),
      rowTotal: (document.querySelector('#kbbSumRow .js-total') || {}).textContent || null,
      savings: [...document.querySelectorAll('.co-save')].map((x) => x.innerText.replace(/\s+/g, ' ').trim()),
      disc: [...document.querySelectorAll('.js-coupons .disc')].map((x) => x.innerText.replace(/\s+/g, ' ').trim())[0] || null,
      place: [...document.querySelectorAll('[data-place]')].map((x) => x.textContent.trim()),
    }));

    // The old layout (restored) has no arrow on the foot box: skip that part.
    if (label === '390' && await pg.locator('.kbb-mobile-order .co-ta').count()) {
      // The box at the foot of the page: closed, opened, the field moved in.
      const foot = pg.locator('.kbb-mobile-order');
      await foot.scrollIntoViewIfNeeded(); await wait(300);
      await foot.screenshot({ path: `${OUT}/foot-closed-390.png` });
      await pg.locator('.kbb-mobile-order .co-ta:visible').first().click(); await wait(400);
      await foot.screenshot({ path: `${OUT}/foot-open-390.png` });
      r.foot = await pg.evaluate(() => ({
        open: document.querySelector('.kbb-mobile-order').classList.contains('co-open'),
        couponInFoot: !!document.querySelector('.co-oscoupon .coupon #kbb_coupon_code'),
        couponFields: document.querySelectorAll('#kbb_coupon_code').length,
        lines: document.querySelectorAll('.co-ositems .ci').length,
      }));
      // Cash on delivery: the button's words and the total with the fee.
      await pg.locator('label[for="payment_method_cod"]').click(); await wait(300);
      r.cod = await pg.evaluate(() => ({ place: [...document.querySelectorAll('[data-place]')].map((x) => x.textContent.trim()), total: (document.querySelector('.kbb-mobile-order .js-total-fee') || {}).textContent }));
      await foot.screenshot({ path: `${OUT}/foot-cod-390.png` });
    }
    // Take the coupon off with the ticket's x.
    await pg.locator('[data-kbb-coupon-remove]').first().click(); await wait(1500);
    r.removed = await pg.evaluate(() => ({ ticket: (document.querySelector('#kbbCouponMsg') || {}).innerText || '', total: (document.querySelector('#kbbSumRow .js-total') || {}).textContent }));
    await ctx.close();
  }
  console.log(JSON.stringify(report, null, 1));
  await b.close();
})();
