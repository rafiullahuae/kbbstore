/*
 * Lane PH admin screenshots, driven the way the owner drives it:
 *   Appearance -> Site layout -> Page header (brand design)
 *   Pages -> User pages -> Edit page -> Page header
 *     NODE_PATH=/opt/node22/lib/node_modules node tools/phb-admin-shots.cjs http://127.0.0.1:10761 docs/lane-ph-shots <pageId>
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:10761';
const OUT = path.resolve(process.argv[3] || 'docs/lane-ph-shots');
const PAGE = Number(process.argv[4] || 7);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
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
    await page.evaluate(() => window.go('sitelayout'));
    await page.waitForTimeout(1500);
    await page.click('[data-sls-tab="pagebanner"]');
    await page.waitForTimeout(900);
    const sl = await page.evaluate(() => ({
      strip: [...document.querySelectorAll('[data-sls-tab]')].map((t) => t.textContent.trim()),
      first: [...document.querySelectorAll('.sls-fields label, .sls-fields .sls-lab')].map((l) => l.textContent.trim()).slice(0, 4),
      count: document.querySelectorAll('.sls-fields select, .sls-fields input').length,
      sw: document.documentElement.scrollWidth,
    }));
    console.log(JSON.stringify({ width, step: 'site layout', ...sl }));
    await page.screenshot({ path: `${OUT}/admin-site-layout-page-header-${width}.png` });

    await page.evaluate(() => window.go('pages-user'));
    await page.waitForTimeout(1200);
    await page.evaluate((id) => window.KBBPageEditor.open(id), PAGE);
    await page.waitForTimeout(1500);
    await page.evaluate(() => document.getElementById('pg-hdr').scrollIntoView({ block: 'start' }));
    await page.waitForTimeout(300);
    const card = await page.$('#pg-hdr');
    await card.screenshot({ path: `${OUT}/admin-page-editor-header-${width}.png` });
    const pe = await page.evaluate(() => ({
      labels: [...document.querySelectorAll('#pg-hdr label')].map((l) => l.textContent.trim()),
      options: [...document.querySelectorAll('#pg-hdr-hero option')].map((o) => o.textContent.trim()),
      sw: document.documentElement.scrollWidth,
    }));
    console.log(JSON.stringify({ width, step: 'page editor', ...pe, errors: errs }));
    await ctx.close();
  }
  await browser.close();
})();
