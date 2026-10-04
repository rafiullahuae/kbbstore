// Lane PF: the admin controls this lane added, on the screens they live on.
//   Appearance → Homepage content → Section headings / Big savings bundles /
//   Brands / About us, and Appearance → Homepage → Layouts (Signature card).
//   Appearance → #KBeautyBliss Spotted (Cards in view · phone / Arrows · phone).
const { chromium } = require('playwright');
(async () => {
  const BASE = process.argv[2], out = process.argv[3];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1100 : 844 } });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('pageerror', String(e)));
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    // The preview runs APP_ENV=production (as the shop does), whose redirect
    // after login is https://; the preview is http, so go there directly.
    await page.click('button[type=submit], input[type=submit]').catch(() => {});
    await page.waitForTimeout(1500);
    await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await page.evaluate(() => window.go('hpcontent'));
    await page.waitForSelector('[data-hpc-tab="headings"]', { timeout: 20000 });
    for (const t of (w > 500 ? ['headings', 'bundles', 'brands', 'about'] : ['headings'])) {
      await page.click(`[data-hpc-tab="${t}"]`);
      await page.waitForTimeout(400);
      const m = await page.evaluate(() => ({ labels: [...document.querySelectorAll('label, .hpc-row b, .hpc-row span')].map(l => l.textContent.trim()).filter(t => /size|Line under|Show how many|Tile height|Read more|Cards in view · phone|Arrows · phone/.test(t)).slice(0, 12), scrollW: document.documentElement.scrollWidth }));
      console.log(w, t, JSON.stringify(m));
      await page.screenshot({ path: `${out}/admin-${t}-${w}.png`, fullPage: true });
    }
    if (w > 500) {
      await page.evaluate(() => window.go('homepage'));
      await page.waitForTimeout(1500);
      const cards = await page.evaluate(() => [...document.querySelectorAll('.hpl')].map(c => c.innerText.replace(/\s+/g, ' ').slice(0, 160)));
      console.log(JSON.stringify(cards, null, 1));
      const el = await page.$('.hplayouts');
      if (el) await el.screenshot({ path: `${out}/admin-layouts-${w}.png` });
    }
    await ctx.close();
  }
  await b.close();
})();
