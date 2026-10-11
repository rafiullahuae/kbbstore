/* Lane RO2: Marketing Emails -> Footer + unsubscribe -> Footer words, and the
   Locked note, at 390 and 1280. BASE=http://127.0.0.1:PORT node tools/ro2-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-ro2-shots');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const M = {};
  for (const w of [1280, 390]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 } })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('mkt-email'));
    await page.waitForTimeout(1500);
    await page.getByRole('tab', { name: 'Templates' }).first().click();
    await page.waitForTimeout(1200);
    await page.locator('[data-mke="blank"]').first().click();
    await page.waitForTimeout(1500);
    await page.getByRole('tab', { name: 'Order', exact: true }).first().click();
    await page.waitForTimeout(500);
    await page.locator('[data-mke-pick]').last().click();
    await page.waitForTimeout(600);
    const box = page.locator('[data-mke-p="why_text"]');
    await box.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/footer-words-empty-${w}.png`, fullPage: false });
    await box.fill('You get our news because you love {store}.');
    await page.locator('[data-mke-p="copyright"]').fill('© {year} {store} — made with love in Dubai');
    await page.locator('[data-mke-p="unsub_label"]').fill('Stop these emails');
    await page.waitForTimeout(2500);
    await page.locator('[data-mke-p="unsub_label"]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/footer-words-typed-${w}.png`, fullPage: false });
    M[w] = {
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
      boxes: await page.locator('.mke-grp [data-mke-p]').count(),
      errors,
    };
  }
  console.log(JSON.stringify(M, null, 1));
  await browser.close();
})();
