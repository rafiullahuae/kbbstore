/*
 * LANE MAC — Platform → Users & Roles → Owner app, in the admin console, at
 * 390 and 1280. KBB_BASE=http://127.0.0.1:10221 node tools/mac-admin-shots.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10221';
const OUT = path.join(__dirname, '..', 'docs', 'mac-shots');
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 844 : 900 }, deviceScaleFactor: w < 700 ? 1.5 : 1 });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log('PAGE ERROR', e.message));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(BASE + '/admin?go=users', { waitUntil: 'networkidle' });
    await page.waitForSelector('#rlt_ownerapp', { timeout: 15000 });
    await page.click('#rlt_ownerapp');
    await page.waitForSelector('.oaa', { timeout: 10000 });
    await page.waitForTimeout(400);
    const sw = await page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
    console.log(w, 'scrollWidth', sw.join('/'));
    await page.screenshot({ path: path.join(OUT, 'admin-' + w + '--users-roles-owner-app.jpg'), type: 'jpeg', quality: 80, fullPage: w > 700 });
    await ctx.close();
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
