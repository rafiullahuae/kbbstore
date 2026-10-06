/*
 * LANE MN — Appearance → Mobile menu → Panel → "Menu opens from", signed in
 * as the owner, at 390 and 1280. The select is opened to show both choices'
 * labels in the report; nothing is saved.
 *
 *   KBB_BASE=http://127.0.0.1:10760 node tools/mn-admin-shots.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10760';
const OUT = path.join(__dirname, '..', 'docs', 'mn-shots', 'after');
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, deviceScaleFactor: width < 600 ? 2 : 1 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('mobilemenu'));
    await page.waitForSelector('select[data-mm="open_from"]', { timeout: 15000 });
    await page.waitForTimeout(500);
    const card = page.locator('.mmcard').first();
    await card.scrollIntoViewIfNeeded();
    const m = await page.evaluate(() => {
      const sel = document.querySelector('select[data-mm="open_from"]');
      const row = sel.closest('.mmrow');
      return {
        crumb: (document.querySelector('#crumb') || {}).textContent,
        card: row.closest('.mmcard').querySelector('.mmhd b').textContent,
        label: row.querySelector('.mmlbl b').textContent,
        help: (row.querySelector('.mmlbl span') || {}).textContent,
        value: sel.value,
        options: [...sel.options].map((o) => o.value + '=' + o.textContent),
        scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
      };
    });
    console.log(width, JSON.stringify(m));
    await card.screenshot({ path: `${OUT}/admin-open-from-${width}.png` });
    await page.screenshot({ path: `${OUT}/admin-mobile-menu-${width}.png` });
    await ctx.close();
  }
  await browser.close();
})();
