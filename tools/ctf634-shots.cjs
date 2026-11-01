/* 2.60.634: free gifts on the cart page only, not the Cart Tracking list.
   BASE=http://127.0.0.1:PORT OA=/<owner-app path> CART=<open cart id> ORD=<ordered cart id> node tools/ctf634-shots.cjs
   Shots go to storage/ctf-shots/. */
const { chromium } = require('playwright');
const path = require('path'); const fs = require('fs');
const BASE = process.env.BASE, OA = process.env.OA, CART = +process.env.CART, ORD = +process.env.ORD;
const OUT = path.resolve(__dirname, '../storage/ctf-shots'); fs.mkdirSync(OUT, { recursive: true });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' }); const out = {};
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const pg = await ctx.newPage(); const errors = [];
    pg.on('pageerror', (e) => errors.push(String(e)));
    pg.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
    // Console: Cart Tracking list, then a cart's page.
    await pg.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await pg.fill('input[name=email]', 'owner@example.com'); await pg.fill('input[name=password]', 'preview-password');
    await Promise.all([pg.waitForNavigation({ waitUntil: 'networkidle' }), pg.click('button[type=submit]')]);
    await pg.evaluate(() => window.go('carttracking')); await wait(2500);
    await pg.evaluate(() => { const t = document.querySelector('[data-ctk-open]'); if (t) t.closest('table').scrollIntoView(); }); await wait(300);
    await pg.screenshot({ path: `${OUT}/desktop-list-${w}.png` });
    out['list' + w] = await pg.evaluate(() => ({ chips: document.querySelectorAll('[data-ctk-open] .ctk-fg').length, rows: document.querySelectorAll('[data-ctk-open]').length, sw: document.documentElement.scrollWidth }));
    for (const [k, id] of [['would', CART], ['ordered', ORD]]) {
      await pg.click(`[data-ctk-open="${id}"]`); await wait(1800);
      out[`detail-${k}-${w}`] = await pg.evaluate(() => { const h = [...document.querySelectorAll('.ctk-panel.on h4')].find((x) => x.textContent === 'Free gifts'); if (h) h.scrollIntoView(); return h ? h.parentElement.textContent : null; });
      await wait(300); await pg.screenshot({ path: `${OUT}/desktop-detail-${k}-${w}.png` });
      await pg.keyboard.press('Escape'); await wait(600);
    }
    out['errors-desktop' + w] = errors.splice(0);
    // Owner app: Carts list, then a cart's sheet.
    await pg.goto(BASE + OA + '/', { waitUntil: 'networkidle' });
    await pg.waitForSelector('[data-enrol]'); await pg.fill('input[name=email]', 'owner@example.com'); await pg.fill('input[name=pin]', '482615');
    await pg.click('[data-enrol] button[type=submit]'); await wait(3000);
    await pg.goto(BASE + OA + '/#/carts', { waitUntil: 'networkidle' }); await wait(2500);
    await pg.screenshot({ path: `${OUT}/owner-list-${w}.png` });
    out['owner-list' + w] = await pg.evaluate(() => ({ chips: document.querySelectorAll('.ct-row .ct-fg').length, rows: document.querySelectorAll('.ct-row').length, sw: document.documentElement.scrollWidth }));
    const idx = await pg.evaluate((id) => [...document.querySelectorAll('.ct-row')].findIndex((r) => r.textContent.includes('AED 450')), CART);
    await pg.locator('.ct-row').nth(Math.max(0, idx)).click(); await wait(2500);
    out['owner-sheet' + w] = await pg.evaluate(() => { const h = [...document.querySelectorAll('.ct-sheet h4')].find((x) => x.textContent.startsWith('Free gifts')); if (h) h.scrollIntoView({ block: 'center' }); return h ? h.parentElement.textContent : null; });
    await wait(300); await pg.screenshot({ path: `${OUT}/owner-sheet-${w}.png` });
    out['errors-owner' + w] = errors.splice(0);
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(out, null, 2)); console.log(JSON.stringify(out, null, 1)); await b.close();
})();
