/* Lane PG2: Appearance -> Product styles -> Layout -> "Photo loading placeholder", at 1280 and 390.
 *   node tools/pg2g-admin-shot.cjs LABEL OUTDIR */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const D = path.join(path.dirname(__dirname), 'storage/framework/testing/lane-spd-' + process.argv[2]);
const base = 'http://127.0.0.1:' + fs.readFileSync(D + '/port', 'utf8').trim();
const OUT = process.argv[3];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [w, h] of [[1280, 900], [390, 844]]) {
    const page = await (await b.newContext({ viewport: { width: w, height: h }, isMobile: w < 600, hasTouch: w < 600 })).newPage();
    const errs = []; page.on('pageerror', (e) => errs.push(e.message));
    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await page.click('button[type=submit], input[type=submit]');
    await page.waitForTimeout(2000);
    await page.goto(base + '/admin', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof window.go === 'function', null, { timeout: 30000 });
    await page.evaluate(() => window.go('prodstyles'));
    await page.waitForSelector('[data-pstab="layout"]', { timeout: 20000 });
    await page.click('[data-pstab="layout"]');
    await page.waitForTimeout(600);
    const sel = await page.evaluate(() => { const s = [...document.querySelectorAll('select')].find((x) => [...x.options].some((o) => o.textContent === 'Grey shimmer')); if (!s) return null; s.scrollIntoView({ block: 'center' }); return [...s.options].map((o) => (o.selected ? '*' : '') + o.textContent); });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/07-admin-product-styles-layout-${w}.png` });
    console.log(w, JSON.stringify(sel), 'scrollWidth', await page.evaluate(() => document.documentElement.scrollWidth), 'errors', errs.length);
  }
  await b.close();
})();
