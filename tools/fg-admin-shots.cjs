/* Lane FG: Growth & Marketing -> Free gifts, every tab, at 1280 and 390; and
   the checkout summary background before (Almost white) and after (#fbf7f8).
   BASE=http://127.0.0.1:PORT DB=.../preview.sqlite node tools/fg-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const { execFileSync } = require('child_process');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-fg');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
fs.mkdirSync(OUT, { recursive: true });
const sql = (q) => execFileSync('php', ['-r', '$p=new PDO("sqlite:' + process.env.DB + '");$p->exec(' + JSON.stringify(q) + ');']);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage(); const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('freegifts'));
    await page.locator('[data-screen="freegifts"] .fg-card').first().waitFor();
    await page.waitForTimeout(500);
    for (const [i, tab] of ['Overview', 'Ranges & gifts', 'Gift products', 'Display', 'Homepage section'].entries()) {
      await page.locator('.fg-tab', { hasText: tab }).click();
      await page.waitForTimeout(300);
      if (tab === 'Ranges & gifts') {
        const find = page.locator('.fg-range').first().locator('input[type=search]');
        await find.fill('mask');
        await find.press('Enter');
        await page.waitForTimeout(800);
      }
      await page.screenshot({ path: `${OUT}/admin-${i + 1}-${tab.toLowerCase().replace(/[^a-z]+/g, '-')}-${w}.png`, fullPage: w === 1280 });
    }
    console.log('admin', w, JSON.stringify({ errors, scrollW: await page.evaluate(() => document.documentElement.scrollWidth) }));
    await ctx.close();
  }

  // The checkout summary background, before and after.
  const pid = 42;
  for (const [name, value] of [['before-white', 'white'], ['after-blush', 'blush']]) {
    sql("insert or replace into settings (key, value, autoload) values ('checkoutpage_lo_sum_open', '" + value + "', 1)");
    try { const d = path.join(path.dirname(process.env.DB), '..', '..', 'cache', 'data'); for (const f of fs.readdirSync(d)) if (f !== '.gitignore') fs.rmSync(path.join(d, f), { recursive: true, force: true }); } catch (e) {}
    for (const w of [390, 1280]) {
      const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
      const page = await ctx.newPage();
      await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
      await page.evaluate(async (id) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) }), pid);
      await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
      if (w === 390) { await page.locator('#kbbSummary .cosr').first().click(); await page.waitForTimeout(400); }
      const bg = await page.evaluate(() => getComputedStyle(document.querySelector('#kbbSummary')).backgroundColor);
      await page.screenshot({ path: `${OUT}/summary-bg-${name}-${w}.png` });
      console.log('summary', name, w, bg);
      await ctx.close();
    }
  }
  await browser.close();
})();
