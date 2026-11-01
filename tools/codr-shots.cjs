/* Lane CODR: refused deliveries. BASE=http://127.0.0.1:PORT PID=<product id> node tools/codr-shots.cjs
   (a) signed-in refuser's checkout, no COD; (b) guest refuser: Place order with
   COD refused, then reload with COD gone; (c) unaffected customer's checkout,
   COD present; (d) Store -> Payments -> Cash on delivery -> Refused deliveries.
   Each at 390 and 1280, into storage/codr-shots/. */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, PID = process.env.PID, OUT = path.resolve(__dirname, '../storage/codr-shots');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

async function xsrfPost(page, url, body) {
  return page.evaluate(async ([url, body]) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', redirect: 'follow',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify(body) });
    return r.status;
  }, [url, body]);
}

async function shopper(browser, w, login) {
  const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 1000 } });
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/404|Failed to load resource/.test(m.text())) errors.push(m.text()); });
  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  if (login) await xsrfPost(page, '/my-account/login', { email: login, password: 'glow-secret-1' });
  await xsrfPost(page, '/api/cart/add', { product_id: +PID, qty: 1 });
  await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
  return { page, errors, ctx };
}

const methods = (page) => page.evaluate(() => [...document.querySelectorAll('input[name=payment_method]')].map((i) => i.value));
const sw = (page) => page.evaluate(() => document.documentElement.scrollWidth);

async function shotPay(page, file) {
  const el = page.locator('input[name=payment_method]').first();
  if (await el.count()) await el.scrollIntoViewIfNeeded().catch(() => {});
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/${file}` });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};
  for (const w of [390, 1280]) {
    // (a) signed-in refuser
    let s = await shopper(browser, w, 'refuser@example.com');
    out[`a-${w}`] = { methods: await methods(s.page), scrollWidth: await sw(s.page), errors: s.errors };
    await shotPay(s.page, `a-signed-in-refuser-${w}.png`);
    await s.ctx.close();

    // (c) unaffected customer
    s = await shopper(browser, w, 'aisha@example.com');
    out[`c-${w}`] = { methods: await methods(s.page), scrollWidth: await sw(s.page), errors: s.errors };
    await shotPay(s.page, `c-unaffected-customer-${w}.png`);
    await s.ctx.close();

    // (b) guest refuser: sees COD, Place order refused, then gone.
    s = await shopper(browser, w, null);
    const before = await methods(s.page);
    await s.page.evaluate(() => {
      const set = (n, v) => { document.querySelectorAll(`[name="${n}"]`).forEach((el) => { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }); };
      set('billing_email', 'guest-refuser@example.com'); set('billing_phone', '+971 50 222 3333');
      set('billing_first_name', 'Layla Hassan'); set('billing_last_name', 'Hassan'); set('billing_address_1', 'Villa 9, Al Safa Street');
      set('billing_address_2', 'Al Safa 1'); set('billing_city', 'Dubai'); set('billing_state', 'Dubai');
      const cod = document.querySelector('input[name=payment_method][value=cod]');
      if (cod) { cod.checked = true; cod.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    const place = s.page.locator('[data-place]:visible, #place_order:visible').first();
    await place.scrollIntoViewIfNeeded().catch(() => {});
    await place.click();
    await s.page.waitForTimeout(2500);
    const said = await s.page.evaluate(() => {
      const t = document.body.innerText; const i = t.indexOf("Cash on delivery isn't available");
      return i >= 0 ? t.slice(i, i + 90) : null;
    });
    const msg = s.page.getByText("Cash on delivery isn't available", { exact: false }).first();
    if (await msg.count()) await msg.scrollIntoViewIfNeeded().catch(() => {});
    await s.page.waitForTimeout(300);
    await s.page.screenshot({ path: `${OUT}/b-guest-refused-${w}.png` });
    await s.page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    const after = await methods(s.page);
    await shotPay(s.page, `b-guest-after-reload-${w}.png`);
    out[`b-${w}`] = { before, said, after, scrollWidth: await sw(s.page), errors: s.errors };
    await s.ctx.close();

    // (d) admin
    const actx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } });
    const page = await actx.newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('payments'));
    await page.waitForTimeout(1500);
    await page.locator('[data-paytab="cod"], [data-paytabid="cod"], button:has-text("Cash on delivery")').first().click().catch(() => {});
    await page.waitForTimeout(1500);
    const sec = page.locator('[data-codr]').first();
    if (await sec.count()) await sec.scrollIntoViewIfNeeded().catch(() => {});
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/d-admin-refused-deliveries-${w}.png` });
    if (await sec.count()) await sec.screenshot({ path: `${OUT}/d-admin-section-only-${w}.png` });
    out[`d-${w}`] = { rows: await page.locator('[data-codrallow]').count(), visible: await sec.isVisible().catch(() => false), scrollWidth: await sw(page), errors };
    await actx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
