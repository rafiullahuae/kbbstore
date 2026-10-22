/* Lane CK (22 October): small phones, in Chromium, on the local preview shop.
     BASE=http://127.0.0.1:PORT node tools/ck22-fit.cjs
   Sets Mobile · Overall Text % to +15 on the checkout, the cart page and the
   cart panel (through the admin's own endpoints), then measures one text on
   each at 320, 340, 360, 375, 390 and 414: the size must be 1.15x at 375 and
   up, step down at 374 and below and again at 340 and below, and never go
   under today's size; scrollWidth must equal the width. Pictures to
   docs/lane-ck/fit-*. Everything is put back to defaults afterwards. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE;
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const OUT = path.resolve(__dirname, '../docs/lane-ck');
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const SET = { '/checkout-page': { m_ov_text: 15 }, '/cart-page': { tx_ov_text_m: 15 }, '/cart-panel': { ov_text_m: 15 } };

async function admin(browser) {
  const ctx = await browser.newContext({ userAgent: UA_D });
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  return { ctx, page };
}

async function post(page, set, reset) {
  return page.evaluate(async ([set, reset]) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const base = location.pathname.replace(/\/+$/, '').replace(/\/[^/]*$/, '') + '/admin-api';
    const out = [];
    for (const p of Object.keys(set)) {
      const body = await (await fetch(base + p, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
      const settings = {};
      body.tabs.forEach((t) => t.fields.forEach((f) => { settings[f.key] = reset && body.type.arKeys.indexOf(f.key) !== -1 ? f['default'] : f.value; }));
      if (!reset) Object.assign(settings, set[p]);
      const r = await fetch(base + p, { method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf }, body: JSON.stringify({ settings, ar: {} }) });
      out.push(p + ' ' + r.status);
    }
    return out;
  }, [set, reset]);
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const a = await admin(browser);
  const report = { set: await post(a.page, SET, false), widths: [] };

  for (const w of [320, 340, 360, 375, 390, 414]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 800 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, userAgent: UA_M });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    for (const id of [26, 5, 11]) {
      await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
    }
    const row = { w };
    await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    row.checkoutH2 = await page.evaluate(() => getComputedStyle(document.querySelector('.kbb-checkout .sec > h2')).fontSize);
    row.checkoutField = await page.evaluate(() => getComputedStyle(document.querySelector('#billing_phone')).fontSize);
    row.checkoutSW = await page.evaluate(() => document.documentElement.scrollWidth);
    await page.screenshot({ path: path.join(OUT, `fit-checkout-${w}.png`) });
    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    row.cartHeading = await page.evaluate(() => { const e = document.querySelector('#cartPage aside.sum > h2'); return e ? getComputedStyle(e).fontSize : null; });
    row.cartSW = await page.evaluate(() => document.documentElement.scrollWidth);
    row.panelTab = await page.evaluate(() => { const e = document.querySelector('#cart .kc-tab'); return e ? getComputedStyle(e).fontSize : null; });
    report.widths.push(row);
    await ctx.close();
  }

  report.reset = await post(a.page, SET, true);
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'fit-facts.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
