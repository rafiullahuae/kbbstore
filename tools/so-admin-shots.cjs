/*
 * Lane SO -- where the controls sit (rule 3), on the AFTER preview:
 *   Catalog -> Catalog -> Reorder, with docs/so-wiring.json applied (run
 *   `php tools/so-wire.php` in the preview's app first), and
 *   Appearance -> Site layout -> Product grid, the new switches.
 *
 *   AFTER=http://127.0.0.1:10591 node tools/so-admin-shots.cjs
 */
const { chromium } = require('playwright');
const AFTER = process.env.AFTER;
const OUT = process.env.OUT || 'docs/so-shots';
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await b.newContext({ viewport: { width: 1280, height: 1000 } })).newPage();
  await page.goto(AFTER + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.evaluate(() => window.go('catalog', 'reorder'));
  await page.waitForSelector('#reTypeBrand');
  await page.click('#reTypeBrand');
  await page.waitForTimeout(1000);
  await page.screenshot({ path: `${OUT}/admin-reorder-wired-1280.png` });
  console.log('reorder note:', await page.evaluate(() => [...document.querySelectorAll('#content p')].map((p) => p.textContent.trim()).find((t) => /own order/.test(t)) || 'NOT FOUND'));
  await page.evaluate(() => window.go('sitelayout'));
  await page.waitForTimeout(1500);
  await page.waitForSelector('[data-sls-tab="grid"]', { timeout: 20000 });
  await page.click('[data-sls-tab="grid"]');
  await page.waitForTimeout(800);
  const sw = page.locator('.sls-fields >> text=Filters · laptop').first();
  if (await sw.count()) await sw.scrollIntoViewIfNeeded();
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${OUT}/admin-site-layout-product-grid-1280.png` });
  console.log('switches:', await page.evaluate(() => ['Filters · laptop', 'Links to the whole shop on category and campaign pages', 'Filters button · phone'].map((t) => t + '=' + document.body.innerText.includes(t))));
  await b.close();
})();
