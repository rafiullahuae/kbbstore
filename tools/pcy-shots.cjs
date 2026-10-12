/* Lane PCY: Store -> Payments -> Countries, and the checkout's payment list
   for a UAE and a Saudi address. BASE=http://127.0.0.1:PORT PID=<product id> node tools/pcy-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, PID = process.env.PID, OUT = path.resolve(__dirname, '../docs/lane-pcy-shots');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  // 1. The admin box, 1280 and 390.
  for (const w of [1280, 390]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('payments'));
    await page.waitForTimeout(1800);
    const box = page.getByText('Countries', { exact: true }).first();
    await box.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/admin-cod-countries-${w}.png` });
    console.log('admin', w, JSON.stringify({ boxes: await page.locator('[data-paycountry="cod"]').count(), checked: await page.locator('[data-paycountry="cod"]:checked').evaluateAll((els) => els.map((e) => e.value)), scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors }));
  }
  // 2. The checkout at 390: UAE, then Saudi Arabia.
  const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error' && !/404/.test(m.text())) errors.push(m.text()); });
  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  await page.evaluate(async (pid) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: +pid, qty: 1 }) });
  }, PID);
  await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
  const methods = () => page.evaluate(() => [...document.querySelectorAll('input[name=payment_method]')].map((i) => i.value));
  const shotPay = async (tag) => {
    const el = page.locator('input[name=payment_method]').first();
    if (await el.count()) await el.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/checkout-${tag}-390.png` });
  };
  const ae = await methods();
  await shotPay('uae');
  await page.selectOption('select[name=billing_country]', 'SA');
  await page.waitForTimeout(2500);
  const sa = await methods();
  await shotPay('saudi');
  console.log('checkout', JSON.stringify({ uae: ae, saudi: sa, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors }));
  await browser.close();
})();
