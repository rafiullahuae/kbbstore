// Lane AS -- the new switch in Translation -> Language settings, before and
// after a press, at 390 and 1280. Usage: node tools/as-admin-shots.cjs <base> <out-dir>
const { chromium } = require('playwright');

const BASE = process.argv[2];
const OUT = process.argv[3];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin#tr-settings', { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await page.evaluate(() => window.go('tr-settings'));
    const tog = '[data-tr-tog="translate_opens_english"]';
    await page.waitForSelector(tog, { timeout: 15000 });
    await page.locator(tog).scrollIntoViewIfNeeded();
    const before = await page.getAttribute(tog, 'aria-checked');
    await page.screenshot({ path: OUT + '/admin-switch-' + w + '-on.png' });
    if (w === 1280) {
      await page.click(tog);
      await page.waitForFunction((s) => document.querySelector(s) && document.querySelector(s).getAttribute('aria-checked') === 'false', tog, { timeout: 10000 });
      await page.locator(tog).scrollIntoViewIfNeeded();
      await page.screenshot({ path: OUT + '/admin-switch-' + w + '-off.png' });
      await page.click(tog);
      await page.waitForFunction((s) => document.querySelector(s) && document.querySelector(s).getAttribute('aria-checked') === 'true', tog, { timeout: 10000 });
    }
    console.log(w, 'default', before, 'errors', JSON.stringify(errors), 'scrollWidth', await page.evaluate(() => document.documentElement.scrollWidth));
    await ctx.close();
  }
  await browser.close();
})();
