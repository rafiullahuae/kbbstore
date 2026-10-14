const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1000 }, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' });
  const pg = await ctx.newPage(); const errs = []; pg.on('pageerror', (e) => errs.push(e.message));
  const r = {};
  await pg.goto(process.env.BASE + '/collections/new-arrivals/', { waitUntil: 'networkidle' });
  r.order = await pg.$$eval('a[href*="/product/"]', (a) => [...new Set(a.map((x) => x.getAttribute('href').match(/\/product\/([^/]+)/)[1]))].slice(0, 6));
  r.sortShown = await pg.$eval('#sort', (s) => s.options[s.selectedIndex].text);
  await pg.screenshot({ path: process.env.OUT + '/new-arrivals-1280.png' });
  await pg.goto(process.env.BASE + '/collections/cleansers/', { waitUntil: 'networkidle' });
  r.otherSort = await pg.$eval('#sort', (s) => s.options[s.selectedIndex].text);
  // admin dialog
  await pg.goto(process.env.BASE + '/admin/login');
  await pg.fill('#email', 'co13@example.com'); await pg.fill('#password', 'preview-pass-123');
  await Promise.all([pg.waitForNavigation(), pg.click('button[type=submit]')]);
  await pg.evaluate(() => window.go('category-tree')); await pg.waitForSelector('.ct-row');
  const row = pg.locator('.ct-row', { hasText: 'New Arrivals' }).first();
  await row.locator('[data-edit]').click(); await pg.waitForSelector('#ct-order');
  r.dialog = await pg.$eval('#ct-order', (s) => s.options[s.selectedIndex].text);
  await pg.locator('#ct-order').scrollIntoViewIfNeeded();
  await pg.screenshot({ path: process.env.OUT + '/edit-product-order.png' });
  r.errors = errs; console.log(JSON.stringify(r, null, 1)); await b.close();
})();
