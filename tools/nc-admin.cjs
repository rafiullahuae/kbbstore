/* Lane MN (2.60.441): Appearance → Header → Navigation → Show the current page,
   on the PREVIEW console (tools/nc-tinker.sh seeds the owner), never production.
   node tools/nc-admin.cjs BASE */
const path = require('path');
const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const BASE = process.argv[2];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [width, height] of [[1280, 1000], [390, 1200]]) {
    const p = await (await b.newContext({ viewport: { width, height } })).newPage();
    await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await p.fill('input[type="email"]', 'nc@preview.test');
    await p.fill('input[type="password"]', 'nc-preview-pass-1');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);
    if (width < 880) { await p.locator('.menubtn').click(); await p.waitForTimeout(400); }
    await p.locator('#nav .nav-group[data-sec="Appearance"] .nav-gh').click();
    await p.waitForTimeout(400);
    await p.locator('[data-go="header"]').first().click();
    await p.waitForSelector('[data-hdtab="nav"]', { timeout: 15000 });
    await p.locator('[data-hdtab="nav"]').click();
    await p.waitForTimeout(800);
    const field = p.getByText('Show the current page', { exact: true }).first();
    await field.evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await p.evaluate(() => window.scrollBy(0, -100));
    await p.waitForTimeout(300);
    console.log(width, JSON.stringify(await p.evaluate(() => ({ scrollWidth: document.documentElement.scrollWidth, labels: [...document.body.innerText.matchAll(/Show the current page|Current page style|Current page colour/g)].map((m) => m[0]) }))));
    await p.screenshot({ path: path.join(APP, `docs/lane-mn-shots/admin-header-navigation-current-${width}.png`) });
  }
  await b.close();
})();
