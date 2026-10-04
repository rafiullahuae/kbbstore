/*
 * Lane FS screenshots: the Fonts & size tab, the font picker with previews,
 * a section before/after on the storefront, and Site layout → Fonts.
 * node tools/fs-shots.cjs PORT   (against tools/fs-preview.sh)
 */
const { chromium } = require('playwright');
const path = require('path');
const PORT = process.argv[2] || 10090;
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-fs-shots');
const stage = process.argv[3] || 'admin';

async function login(b, w) {
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1000 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('pageerror', String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return { ctx, page };
}

async function fontRequests(page) {
  return page.evaluate(() => performance.getEntriesByType('resource').filter((r) => /\.woff2/.test(r.name)).map((r) => r.name.split('/').pop()));
}

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    if (stage === 'admin') {
      const { ctx, page } = await login(b, w);
      await page.evaluate(() => window.go('hpcontent'));
      await page.waitForSelector('[data-hph-edit="bestselling"]', { timeout: 20000 });
      await page.click('[data-hph-edit="bestselling"]');
      await page.waitForSelector('[data-hph-tab="type"]');
      const tabs = await page.$$eval('.hph-tab', (t) => t.map((x) => x.textContent));
      await page.click('[data-hph-tab="type"]');
      await page.waitForTimeout(400);
      const before = await fontRequests(page);
      const m = await page.evaluate(() => ({ rows: [...document.querySelectorAll('#hph-bd .hph-row label, #hph-bd .hph-row .hph-l')].map((x) => x.textContent),
        sw: document.documentElement.scrollWidth, dlg: Math.round(document.querySelector('.hph-dlg').getBoundingClientRect().width) }));
      console.log(w, 'tabs', JSON.stringify(tabs), JSON.stringify(m), 'fonts loaded', before.length);
      await page.screenshot({ path: `${OUT}/tab-${w}.png` });
      // The picker: open it on the serif kind.
      await page.click('[data-hph-font="font"] [data-kfp-toggle]');
      await page.click('[data-kfp-kind="serif"]');
      await page.waitForTimeout(1200);
      const loaded = await fontRequests(page);
      console.log(w, 'picker open on serif: woff2 requests', loaded.length, JSON.stringify(loaded.map((x) => x.replace(/-[A-Za-z0-9_]{8}\.woff2$/, ''))));
      const pick = await page.$('[data-kfp-opt="playfair-display"]');
      await pick.scrollIntoViewIfNeeded();
      await page.screenshot({ path: `${OUT}/picker-${w}.png` });
      await pick.click();
      // Heading size: laptop 44, phone 30; uppercase.
      await page.$eval('[data-hph-t="h_d"]', (e) => { e.value = 44; e.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.$eval('[data-hph-t="h_m"]', (e) => { e.value = 30; e.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.selectOption('[data-hph-t="case"]', 'upper');
      await page.waitForTimeout(500);
      await page.screenshot({ path: `${OUT}/tab-moved-${w}.png` });
      if (w === 1280) {
        await page.click('[data-hph-save]');
        await page.waitForSelector('#hph-msg span', { timeout: 10000 });
        console.log('save:', await page.textContent('#hph-msg'));
      }
      await ctx.close();
    } else if (stage === 'sitefonts') {
      const { ctx, page } = await login(b, w);
      await page.evaluate(() => window.go('sitelayout'));
      await page.waitForSelector('.sls-tabs', { timeout: 20000 });
      await page.evaluate(() => { const t = [...document.querySelectorAll('.sls-tabs button, .sls-tabs [data-sls-tab]')].find((x) => /Fonts/.test(x.textContent)); t && t.click(); });
      await page.waitForTimeout(600);
      await page.click('[data-kfp-toggle]');
      await page.waitForTimeout(1000);
      console.log(w, 'site fonts tab, woff2 loaded:', (await fontRequests(page)).length, 'sw', await page.evaluate(() => document.documentElement.scrollWidth));
      await page.screenshot({ path: `${OUT}/site-fonts-${w}.png` });
      await ctx.close();
    } else {
      const ctx = await b.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: w > 500 ? 1 : 2 });
      const page = await ctx.newPage();
      await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
      const el = await page.$('.hs-bestselling');
      await el.scrollIntoViewIfNeeded();
      const m = await page.evaluate(() => { const h = document.querySelector('.hs-bestselling h2'); const c = getComputedStyle(h);
        return { font: c.fontFamily, size: c.fontSize, transform: c.textTransform, h2h: Math.round(h.getBoundingClientRect().height), sw: document.documentElement.scrollWidth,
          woff2: performance.getEntriesByType('resource').filter((r) => /\.woff2/.test(r.name)).map((r) => r.name.split('/').pop().replace(/-[A-Za-z0-9_]{8}\.woff2$/, '')) }; });
      console.log(stage, w, JSON.stringify(m));
      await page.evaluate(() => scrollTo(0, 0));
      await page.waitForTimeout(400);
      const box = await page.evaluate(() => { const r = document.querySelector('.hs-bestselling').getBoundingClientRect(); return { y: r.top + scrollY, height: r.height }; });
      await page.screenshot({ path: `${OUT}/${stage}-${w}.png`, fullPage: true, clip: { x: 0, y: Math.max(0, box.y - 20), width: w, height: Math.min(box.height + 40, w > 500 ? 560 : 640) } });
      await ctx.close();
    }
  }
  await b.close();
})();
