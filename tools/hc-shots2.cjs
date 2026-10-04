/*
 * Lane HC follow-up screenshots: a Grid section carousel with Cards in view
 * and arrows — on the shop, and its Layout tab in Homepage content. `node tools/hc-shots2.cjs 390` / `1280`, preview from hc-preview.sh.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.HC_BASE || 'http://127.0.0.1:10010';
const OUT = process.env.HC_OUT || (__dirname + '/../docs/lane-hc-shots');
const CHROME = process.env.HC_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  const w = +process.argv[2];
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 1 });
  const m = { width: w };
  const store = await ctx.newPage();
  await store.goto(BASE + '/', { waitUntil: 'networkidle' });
  m.scrollWidth = await store.evaluate(() => document.documentElement.scrollWidth);
  for (const [sel, name] of [['section.kbb-gsec', 'hc-gridcarousel']]) {
    const el = store.locator(sel).first();
    await el.scrollIntoViewIfNeeded();
    await store.waitForTimeout(300);
    await el.screenshot({ path: `${OUT}/${name}-${w}.png` });
  }
  m.carousel = await store.evaluate(() => {
    const g = document.querySelector('.kbb-gsec .gs-grid'); const c = g.querySelector('.gs-cell'); const r = g.getBoundingClientRect(); const cr = c.getBoundingClientRect();
    const arr = [...document.querySelectorAll('.kbb-gsec .gs-arr')].map((b) => getComputedStyle(b).display);
    return { track: Math.round(r.width), card: Math.round(cr.width), inView: +(r.width / cr.width).toFixed(2), style: g.getAttribute('style'), arrows: arr };
  });

  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.evaluate(() => window.go('hpcontent'));
  await page.waitForSelector('.hph-card');
  const open = async (key, tab, name) => {
    await page.click(`[data-hph-edit="${key}"]`);
    await page.waitForSelector(`[data-hph-tab="${tab}"]`);
    await page.click(`[data-hph-tab="${tab}"]`);
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
    await page.click('.hph-x');
    await page.waitForTimeout(200);
  };
  const gridKey = await page.$eval('[data-hph-edit^="grid_"]', (b) => b.dataset.hphEdit);
  await open(gridKey, 'r-layout', 'hc-edit-grid-layout');

  fs.writeFileSync(`${OUT}/measure2-${w}.json`, JSON.stringify(m, null, 2));
  console.log(JSON.stringify(m));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
