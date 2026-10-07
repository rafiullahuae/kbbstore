/*
 * Lane AD: "Emirate / state as a list" on Appearance -> Checkout page -> Fields &
 * attention, attention.
 *   node tools/es-admin-shot.cjs <out-dir> [port]
 */
const { chromium } = require('playwright');
const fs = require('fs');
const [out, port = '8762'] = process.argv.slice(2);
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
  const row = page.locator('text=Emirate / state as a list').first();
  await row.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${out}/admin-fields-attention-state-list.png` });
  const labels = await page.evaluate(() => [...document.querySelectorAll('body *')].filter((e) => e.children.length === 0)
    .map((e) => e.textContent.trim()).filter((t) => /^(Emirate \/ state as a list|Address picker row on cart and checkout)$/.test(t)));
  console.log(JSON.stringify({ labels }));
  await browser.close();
})();
