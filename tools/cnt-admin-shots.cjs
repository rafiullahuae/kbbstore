/*
 * Lane CT admin shots: Store → Inquiries (inbox, an opened inquiry) and its
 * Contact page tab, at 1280 and 390, with console errors and the sidebar row.
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/cnt-admin-shots.cjs http://127.0.0.1:10791 docs/lane-ct-shots
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:10791';
const OUT = path.resolve(process.argv[3] || 'docs/lane-ct-shots');

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));
    page.on('console', (c) => { if (c.type() === 'error') errs.push(c.text().slice(0, 160)); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    const rows = await page.evaluate(() => [...document.querySelectorAll('#nav [data-go="inquiries"]')].length);
    await page.evaluate(() => window.go('inquiries'));
    await page.waitForTimeout(1200);
    await page.screenshot({ path: `${OUT}/admin-inbox-${width}.png`, fullPage: width < 600 });
    await page.click('[data-ctx-open]:nth-child(1)');
    await page.waitForTimeout(900);
    const unread = await page.evaluate(() => document.querySelectorAll('.ctx-row.unread').length);
    await page.screenshot({ path: `${OUT}/admin-detail-${width}.png`, fullPage: width < 600 });
    await page.click('[data-ctx-tab="settings"]');
    await page.waitForTimeout(1000);
    await page.screenshot({ path: `${OUT}/admin-settings-${width}.png`, fullPage: true });
    const sw = await page.evaluate(() => document.documentElement.scrollWidth);
    console.log(width, 'sidebar rows', rows, '| unread after open', unread, '| scrollWidth', sw, '| errors', errs.length ? errs.join(' ; ') : 0);
    await ctx.close();
  }
  await browser.close();
})();
