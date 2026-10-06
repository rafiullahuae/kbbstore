/**
 * Lane MG — Appearance → Header → Navigation, where the mega-menu controls live.
 *
 *   NODE_PATH=./node_modules node tools/mg-admin-shots.cjs http://127.0.0.1:10960 docs/lane-mg-shots
 *
 * Signs in to the console of the PREVIEW (tools/mg-preview.sh seeds the owner),
 * never production.
 */
const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:10960';
const OUT = process.argv[3] || 'docs/lane-mg-shots';

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
  for (const [width, height] of [[1280, 1560], [390, 1400]]) {
    const p = await (await b.newContext({ viewport: { width, height } })).newPage();
    await p.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await p.fill('input[type="email"]', 'mg@preview.test');
    await p.fill('input[type="password"]', 'mg-preview-secret-1');
    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type="submit"]')]);
    if (width < 880) { await p.locator('.menubtn').click(); await p.waitForTimeout(400); }
    await p.locator('#nav .nav-group[data-sec="Appearance"] .nav-gh').click();
    await p.waitForTimeout(400);
    await p.locator('[data-go="header"]').first().click();
    await p.waitForSelector('[data-hdtab="nav"]', { timeout: 15000 });
    await p.locator('[data-hdtab="nav"]').click();
    await p.waitForTimeout(800);
    const field = p.getByText('Fit mega menus to the site width', { exact: true }).first();
    await field.evaluate((el) => el.scrollIntoView({ block: 'start' }));
    await p.evaluate(() => window.scrollBy(0, -140));
    await p.waitForTimeout(300);
    const info = await p.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      labels: [...document.querySelectorAll('label, .fl, .lbl')].map((x) => x.textContent.trim()).filter((t) => /mega|left beyond|column width|Pointer/i.test(t)).slice(0, 10),
    }));
    console.log(width, JSON.stringify(info));
    await p.screenshot({ path: `${OUT}/admin-header-navigation-${width}.png`, fullPage: false });
  }
  await b.close();
})();
