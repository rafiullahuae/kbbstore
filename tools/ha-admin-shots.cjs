// Lane HA: Appearance → Homepage content, one shot per new tab at 1280, two at
// 390, plus Appearance → Homepage showing the switched-off rows.
const { chromium } = require('playwright');
(async () => {
  const BASE = process.argv[2] || 'http://127.0.0.1:9871';
  const out = process.argv[3] || 'docs/ha-shots';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 2000 : 844 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', String(e)));
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.evaluate(() => window.go('hpcontent'));
    await page.waitForSelector('[data-hpc-tab="bestselling"]', { timeout: 20000 });
    const tabs = w > 500 ? ['bestselling', 'brands', 'trending', 'blog', 'under54', 'feature', 'about'] : ['bestselling', 'brands'];
    for (const t of tabs) {
      await page.click(`[data-hpc-tab="${t}"]`);
      await page.waitForTimeout(400);
      const m = await page.evaluate(() => ({ rows: document.querySelectorAll('.hpc-row').length, chips: document.querySelectorAll('.hpc-chip').length,
        scrollW: document.documentElement.scrollWidth }));
      console.log(w, t, JSON.stringify(m));
      await page.screenshot({ path: `${out}/admin-${t}-${w}.png`, fullPage: true });
    }
    if (w > 500) {
      // A manual pick, to show the chips with their ↑ ↓ ×.
      await page.click('[data-hpc-tab="brands"]');
      for (let i = 0; i < 3; i++) {
        const v = await page.$eval('[data-hpc-ids-add]', s => s.options[1] && s.options[1].value);
        await page.selectOption('[data-hpc-ids-add]', v);
        await page.waitForTimeout(200);
      }
      await page.screenshot({ path: `${out}/admin-brands-picked-${w}.png`, fullPage: true });
      await page.evaluate(() => window.go('homepage'));
      await page.waitForTimeout(1500);
      await page.screenshot({ path: `${out}/admin-homepage-sections-${w}.png`, fullPage: true });
    }
    await ctx.close();
  }
  await b.close();
})();
