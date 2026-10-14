const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 }, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' });
  const pg = await ctx.newPage(); const errs = []; pg.on('pageerror', (e) => errs.push(e.message));
  await pg.goto(process.env.BASE + '/admin/login');
  await pg.fill('#email', 'co13@example.com'); await pg.fill('#password', 'preview-pass-123');
  await Promise.all([pg.waitForNavigation(), pg.click('button[type=submit]')]);
  await pg.evaluate(() => window.go('category-tree')); await pg.waitForSelector('.ct-visit');
  const all = { categories: await pg.$$eval('.ct-row .ct-visit', (a) => a.map((x) => x.getAttribute('href'))) };
  await pg.click('#content [data-tab="brands"]'); await pg.waitForTimeout(1200);
  all.brandTab = await pg.$$eval('.ct-row .ct-visit', (a) => a.map((x) => x.getAttribute('href')));
  all.brandTabPaths = await pg.$$eval('.ct-row .ct-path', (a) => a.slice(0, 3).map((x) => x.textContent));
  await pg.screenshot({ path: process.env.OUT + '/categories-brands-tab-visit.png', clip: { x: 0, y: 0, width: 1400, height: 700 } });
  await pg.evaluate(() => window.go('brands-manager')); await pg.waitForSelector('.bz-visit');
  all.brands = await pg.$$eval('.bz-visit', (a) => a.map((x) => x.getAttribute('href')));
  // every link: real status on the shop
  const status = {};
  for (const [k, list] of Object.entries(all)) {
    if (!Array.isArray(list) || k === 'brandTabPaths') continue;
    status[k] = {};
    for (const h of list) { const r = await ctx.request.get(process.env.BASE + h, { maxRedirects: 0 }); status[k][r.status()] = (status[k][r.status()] || 0) + 1; }
  }
  console.log(JSON.stringify({ counts: Object.fromEntries(Object.entries(all).map(([k, v]) => [k, v.length])), brandTabPaths: all.brandTabPaths, sub: all.categories.filter((h) => h.split('/').length > 4).slice(0, 3), status, errs }, null, 1));
  await b.close();
})();
