/* Lane SSB: Pages -> Page banners -> Super Sale banner, at 1280 and 390, then
   the control moving the shop: the coupon code and title changed and the
   gifts narrowed to the first range, saved, /super-sale/ photographed, and the
   shipped values saved back. Typing must send nothing; console errors counted.
   sh tools/ssb-preview.sh 11460, then BASE=http://127.0.0.1:<port> node tools/ssb-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../storage/ssb-shots');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [];
    let apiCalls = 0;
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    ctx.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.url().includes('/admin-api/')) apiCalls++; });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('pagebanners'));
    await page.locator('[data-pbs-tab="ssb"]').waitFor({ timeout: 10000 });
    await page.locator('[data-pbs-tab="ssb"]').click();
    await page.locator('[data-pbs-ssb-chk="on"]').waitFor();
    await page.screenshot({ path: `${OUT}/admin-ssb-${w}.png`, fullPage: true });
    await page.locator('[data-pbs-ssb-chk="model_on"]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/admin-ssb-${w}-model.png` });
    await page.locator('[data-pbs-ssb-band]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/admin-ssb-${w}-gifts.png` });
    const r = { scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) };

    if (w === 1280) {
      const before = apiCalls;
      await page.fill('[data-pbs-ssb-t="code"]', 'SALE20');
      await page.fill('[data-pbs-ssb-t="title_en"]', 'MEGA SALE');
      await page.selectOption('[data-pbs-ssb-band]', 'first');
      r.requestsWhileEditing = apiCalls - before;
      await page.screenshot({ path: `${OUT}/admin-ssb-changed-${w}.png`, fullPage: true });
      await Promise.all([page.waitForResponse((x) => x.url().includes('/admin-api/page-banners') && x.request().method() === 'POST'), page.click('[data-pbs-save]')]);
      const shop = await ctx.newPage();
      await shop.goto(BASE + '/super-sale/', { waitUntil: 'networkidle' });
      await shop.screenshot({ path: `${OUT}/admin-ssb-shop-after-save-${w}.png`, clip: { x: 0, y: 0, width: w, height: 560 } });
      r.shopAfterSave = await shop.evaluate(() => ({ code: document.querySelector('.ssb-coupon b')?.textContent, title: document.querySelector('.ssb-super')?.textContent, thumbs: document.querySelectorAll('.ssb-c').length }));
      // Back to what ships.
      await page.fill('[data-pbs-ssb-t="code"]', 'GLOW');
      await page.fill('[data-pbs-ssb-t="title_en"]', 'SUPER SALE');
      await page.selectOption('[data-pbs-ssb-band]', 'all');
      await Promise.all([page.waitForResponse((x) => x.url().includes('/admin-api/page-banners') && x.request().method() === 'POST'), page.click('[data-pbs-save]')]);
      await shop.reload({ waitUntil: 'networkidle' });
      r.shopAfterRestore = await shop.evaluate(() => ({ code: document.querySelector('.ssb-coupon b')?.textContent, title: document.querySelector('.ssb-super')?.textContent, thumbs: document.querySelectorAll('.ssb-c').length }));
    }
    r.errors = errors;
    report[w] = r;
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/admin-numbers.json`, JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
