/* Lane FG5: Growth & Marketing -> Free gifts -> Display -> "Gift box look",
   at 1280 and 390: the new controls and the live preview (English and
   Arabic), then the preview after each control moves -- brand off, one line
   fade, separator, bigger photos and title -- measured from the preview's
   own box, then Save and a reload to prove the values are kept. Console
   errors and requests are counted: moving a control must send nothing.
   FG_SEED=fg5-seed.php sh tools/fg-preview.sh 10960
   BASE=http://127.0.0.1:10960 node tools/fg5-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-fg5');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = [];
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [];
    let apiCalls = 0;
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.url().includes('/admin-api/')) apiCalls++; });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('freegifts'));
    await page.locator('[data-screen="freegifts"] .fg-status').waitFor({ timeout: 8000 }).catch(() => { console.log(JSON.stringify(errors)); });
    await page.locator('#fg-tab-display').click();
    await page.locator('[data-fk="lk-titles"]').waitFor();
    const card = page.locator('.fg-card', { hasText: 'Gift box look' });
    const pv = () => page.locator('.fg-pvbg .fgp').nth(1);
    const measure = async () => pv().evaluate((x) => {
      const nm = x.querySelector('.fgp-nm'), ln = x.querySelector('.fgp-ln').getBoundingClientRect(), lv = x.querySelector('.fgp-lv').getBoundingClientRect();
      return { cls: x.className, style: x.getAttribute('style'), title: getComputedStyle(nm).fontSize, photo: Math.round(x.querySelector('.fgp-lr .fgp-t').getBoundingClientRect().width),
        gapFree: Math.round(x.dir === 'rtl' ? ln.left - lv.right : lv.left - ln.right), brand: [...x.querySelectorAll('.fgp-ln small')].some((s) => s.getClientRects().length > 0),
        sep: getComputedStyle(x.querySelectorAll('.fgp-lr')[1]).borderTopStyle };
    });
    const r = { w, defaults: await measure() };
    await card.scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/admin-display-${w}.png`, fullPage: w === 1280 });
    const before = apiCalls;
    await card.locator('button[role=switch]', { hasText: 'Show brand names' }).click();
    await page.selectOption('[data-fk="lk-titles"]', 'fade');
    await card.locator('button[role=switch]', { hasText: 'Separator' }).click();
    await page.fill('[data-fk="lk-photo_list"]', '40');
    await page.fill('[data-fk="lk-title_px"]', '14');
    await page.fill('[data-fk="lk-free_gap"]', '99'); // clamped to 40 by the preview, as by the server
    r.changed = await measure();
    r.requestsWhileEditing = apiCalls - before;
    if (w === 1280) {
      await page.locator('.fg-pvcol .seg button', { hasText: 'العربية' }).click();
      r.changedAr = await measure();
      await page.screenshot({ path: `${OUT}/admin-display-changed-ar-${w}.png`, fullPage: true });
      await page.locator('.fg-pvcol .seg button', { hasText: 'English' }).click();
    }
    await page.screenshot({ path: `${OUT}/admin-display-changed-${w}.png`, fullPage: w === 1280 });
    // Save, reload, and the look is what was saved; then put the defaults back.
    await page.locator('.fg-save button', { hasText: 'Save' }).first().click();
    await page.waitForTimeout(800);
    await page.reload();
    await page.evaluate(() => window.go('freegifts'));
    await page.locator('#fg-tab-display').click();
    await page.locator('[data-fk="lk-titles"]').waitFor();
    r.afterReload = await measure();
    r.savedFreeGapField = await page.inputValue('[data-fk="lk-free_gap"]');
    await page.evaluate(async () => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x };
      const base = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/free-gifts';
      const d = await (await fetch(base, { headers: h, credentials: 'same-origin' })).json();
      const c = d.config;
      await fetch(base + '/settings', { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify({ show_value: c.show_value, countries: c.countries, basis: c.basis, texts: c.texts, home: c.home, look: {} }) });
    });
    r.scrollW = await page.evaluate(() => document.documentElement.scrollWidth);
    r.errors = errors;
    report.push(r);
    await ctx.close();
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
