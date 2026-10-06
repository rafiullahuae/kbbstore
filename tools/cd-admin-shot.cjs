/*
 * Lane CD: the two switches on Appearance -> Checkout page -> Fields &
 * attention, before and after ticking "Desktop: totals above Place order" off.
 *   node tools/cd-admin-shot.cjs <out-dir> [port]
 */
const { chromium } = require('playwright');
const fs = require('fs');
const [out, port = '8771'] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 })).newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.evaluate(() => window.go('checkoutpage'));
  await page.waitForTimeout(1500);
  await page.click('[data-chp-tab="cues"]');
  await page.waitForTimeout(600);
  const row = page.locator('text=Desktop: totals above Place order').first();
  await row.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${out}/cd-admin-fields-attention.png` });
  const labels = await page.evaluate(() => [...document.querySelectorAll('body *')].filter((e) => e.children.length === 0)
    .map((e) => e.textContent.trim()).filter((t) => /^(Desktop: totals above Place order|Floating labels on checkout fields)$/.test(t)));
  console.log(JSON.stringify({ labels }));
  await browser.close();
})();
