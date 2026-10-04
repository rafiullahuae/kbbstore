/*
 * Lane PD — Store → Modules → This app only, with the two new switches, at
 * 1280 and 390. node tools/pd-admin-shot.cjs [base]
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:10110';
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(`${BASE}/admin?go=modules`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    const row = page.getByText('Videos in product descriptions').first();
    await row.scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy({ top: -120, behavior: 'instant' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(__dirname, '..', 'docs', 'lane-pd-shots', `admin-modules-${width}.png`) });
    await page.close();
  }
  await browser.close();
})();
