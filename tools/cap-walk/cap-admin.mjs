/*
 * Lane CAP: what the console shows for a typed checkout email, at 1280 and 390.
 *   node tools/cap-walk/cap-admin.mjs PORT OUTDIR
 * Cart Tracking (the row, the cart, the Settings tab) and Store -> Customers
 * (the list label, the customer). Needs tools/cap-walk/owner.php.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';

const [PORT, OUT = '.'] = process.argv.slice(2);
const BASE = `http://127.0.0.1:${PORT}`;
fs.mkdirSync(OUT, { recursive: true });
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

for (const W of [1280, 390]) {
  const ctx = await browser.newContext({ viewport: { width: W, height: W < 600 ? 844 : 860 }, deviceScaleFactor: W < 600 ? 2 : 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  const out = { W };

  // Cart Tracking: the carts list.
  await page.evaluate(() => window.go('carttracking'));
  await page.waitForSelector('.ctk-who', { timeout: 10000 });
  await page.waitForTimeout(400);
  out.who = await page.$$eval('.ctk-who', (els) => els.slice(0, 6).map((e) => e.innerText.split('\n').slice(0, 2).join(' / ')));
  await page.locator('.ctk-who').first().scrollIntoViewIfNeeded();
  await page.evaluate(() => { const r = document.querySelector('.ctk-who'); const sc = document.querySelector('#content'); if (r && sc) sc.scrollTop += r.getBoundingClientRect().top - 120; });
  await page.waitForTimeout(200);
  await page.screenshot({ path: `${OUT}/cap-admin-${W}-1-cart-tracking.png` });

  // One cart, opened.
  await page.locator('[data-ctk-open]').first().click({ position: { x: 5, y: 5 } }).catch(() => {});
  await page.waitForTimeout(900);
  await page.screenshot({ path: `${OUT}/cap-admin-${W}-2-cart.png` });
  // The name links to the customer.
  const link = page.locator('#ctkPanel [data-ctk-cust]').first();
  out.linkInPanel = await link.count();
  if (out.linkInPanel) {
    await link.click();
    await page.waitForTimeout(900);
    out.customerOpened = await page.evaluate(() => document.querySelector('#content').innerText.includes('From checkout, no order yet') || document.querySelector('#content').innerText.includes('All customers'));
    await page.screenshot({ path: `${OUT}/cap-admin-${W}-3-customer-from-cart.png` });
  }

  // Settings tab.
  await page.evaluate(() => window.go('carttracking'));
  await page.waitForTimeout(700);
  await page.locator('[data-kbt="carttracking"] >> text=Settings').first().click().catch((e) => console.log('tab', e.message));
  await page.waitForTimeout(900);
  const set = page.locator('h4', { hasText: 'Emails typed at checkout' });
  if (await set.count()) { await set.scrollIntoViewIfNeeded(); await page.evaluate(() => { const h = [...document.querySelectorAll('h4')].find((x) => x.textContent === 'Emails typed at checkout'); const sc = document.querySelector('#content'); if (h && sc) sc.scrollTop += h.getBoundingClientRect().top - 100; }); }
  out.settings = await page.evaluate(() => ['capture_email', 'capture_reminder'].map((k) => { const el = document.querySelector('[data-ctk-set="' + k + '"]'); return k + '=' + (el ? el.checked : 'missing'); }));
  await page.screenshot({ path: `${OUT}/cap-admin-${W}-4-settings.png` });

  // Store -> Customers.
  await page.evaluate(() => window.go('customers'));
  await page.waitForTimeout(1200);
  out.labels = await page.evaluate(() => (document.querySelector('#content').innerText.match(/From checkout, no order yet/g) || []).length);
  await page.screenshot({ path: `${OUT}/cap-admin-${W}-5-customers.png` });

  // Emails -> Sent mail: the one reminder, beside the order's own emails.
  await page.evaluate(() => window.go('emails-sent'));
  await page.waitForTimeout(1500);
  await page.screenshot({ path: `${OUT}/cap-admin-${W}-6-sent-mail.png` });

  out.sw = await page.evaluate(() => document.documentElement.scrollWidth);
  out.errors = errors;
  console.log(JSON.stringify(out));
  await ctx.close();
}
await browser.close();
