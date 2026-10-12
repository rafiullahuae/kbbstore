/* Lane CRUMB: Appearance -> Header -> Breadcrumbs, at 1280 and 390. BASE=... node tools/crumb-admin-shot.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-crumb-shots');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [1280, 390]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('header'));
    await page.waitForTimeout(1500);
    await page.screenshot({ path: OUT + '/admin-header-probe.png' }); console.log(await page.evaluate(() => [...document.querySelectorAll('button,[role=tab],a')].map(b => b.textContent.trim()).filter(t => /crumb/i.test(t)).join(' | ')));
    await page.locator('button,[role=tab],a', { hasText: /Breadcrumb/ }).first().click();
    await page.waitForTimeout(800);
    const el = page.getByText('Long breadcrumb · phone').first();
    await el.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/admin-breadcrumbs-${w}.png` });
    console.log(w, JSON.stringify({ found: await page.getByText('Long breadcrumb · phone').count(), sizeLabels: await page.getByText('Font size · phone').count(), scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors }));
  }
  await browser.close();
})();
