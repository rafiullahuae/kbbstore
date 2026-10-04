/*
 * Lane HC screenshots: Appearance → Homepage content → All sections, the Edit
 * content editor (every tab), the source picker in Automatic (mixed
 * categories) and Manual (typeahead open, list reordered), and the storefront
 * after each source change, plus the two phone-only strips.
 *
 *   sh tools/hc-preview.sh            # boots on 10010
 *   node tools/hc-shots.cjs 1280 && node tools/hc-shots.cjs 390
 *
 * Measuring happens HERE, in the harness — the screen itself measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.HC_BASE || 'http://127.0.0.1:10010';
const OUT = process.env.HC_OUT || (__dirname + '/../docs/lane-hc-shots');
const CHROME = process.env.HC_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  const w = +process.argv[2];
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const m = { width: w };
  const shot = async (name, opts = {}) => page.screenshot({ path: `${OUT}/${name}-${w}.png`, ...opts });
  const sw = () => page.evaluate(() => document.documentElement.scrollWidth);
  const box = (sel) => page.evaluate((s) => { const r = document.querySelector(s).getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), x: Math.round(r.x), y: Math.round(r.y) }; }, sel);

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  await page.evaluate(() => window.go('hpcontent'));
  await page.waitForSelector('.hph-card');
  await page.waitForTimeout(300);
  m.list = { cards: await page.locator('.hph-card').count(), editButtons: await page.locator('[data-hph-edit]').count(), scrollWidth: await sw() };
  await shot('hc-list', { fullPage: true });

  // The editor, every tab.
  await page.click('[data-hph-edit="bestselling"]');
  await page.waitForSelector('.hph-dlg');
  await page.waitForTimeout(300);
  m.popup = await box('.hph-dlg');
  m.popupScrollWidth = await sw();
  const tabs = await page.$$eval('[data-hph-tab]', (b) => b.map((x) => [x.dataset.hphTab, x.textContent]));
  m.tabs = tabs.map((t) => t[1]);
  for (const [key] of tabs) {
    await page.click(`[data-hph-tab="${key}"]`);
    await page.waitForTimeout(500);
    await shot('hc-edit-' + key);
  }

  // Automatic, mixed categories.
  await page.click('[data-hph-tab="products"]');
  await page.click('[data-hph-mode="auto"]');
  for (const label of ['Toners', 'Serums']) {
    await page.selectOption('select[data-hph-chipadd="cats"]', { label });
    await page.waitForTimeout(250);
  }
  await page.waitForTimeout(900);
  m.autoPreview = await page.locator('#hph-pv figure').count();
  await shot('hc-src-auto');
  await page.click('[data-hph-save]');
  await page.waitForFunction(() => /Saved/.test(document.getElementById('hph-msg')?.textContent || ''));
  m.autoSaved = await page.textContent('#hph-msg');

  const store = await ctx.newPage();
  await store.setViewportSize({ width: w, height: 900 });
  await store.goto(BASE + '/', { waitUntil: 'networkidle' });
  const rail = store.locator('section.hs-bestselling');
  await rail.scrollIntoViewIfNeeded();
  await rail.screenshot({ path: `${OUT}/hc-store-auto-${w}.png` });
  m.storeAutoCards = await rail.locator('.kbb-card').count();
  m.storeScrollWidth = await store.evaluate(() => document.documentElement.scrollWidth);

  // Manual: typeahead open, three picked, the last moved up.
  await page.click('[data-hph-mode="manual"]');
  await page.fill('#hph-ta-in', 'ton');
  await page.waitForSelector('#hph-ta-res .kpp-hit');
  await page.waitForTimeout(200);
  m.typeaheadRows = await page.locator('#hph-ta-res .kpp-hit').count();
  await shot('hc-src-manual-typeahead');
  await page.click('#hph-ta-res .kpp-hit >> nth=0');
  for (const q of ['serum', 'sun']) {
    await page.fill('#hph-ta-in', q);
    await page.waitForSelector('#hph-ta-res .kpp-hit');
    await page.click('#hph-ta-res .kpp-hit >> nth=0');
    await page.waitForTimeout(250);
  }
  m.manualBefore = await page.$$eval('.hph-pick b', (b) => b.map((x) => x.textContent));
  await page.click('[data-hph-mv="2|-1"]');
  await page.waitForTimeout(700);
  m.manualAfter = await page.$$eval('.hph-pick b', (b) => b.map((x) => x.textContent));
  await shot('hc-src-manual-reordered');
  await page.click('[data-hph-save]');
  await page.waitForFunction(() => /Saved/.test(document.getElementById('hph-msg')?.textContent || ''));
  await store.goto(BASE + '/', { waitUntil: 'networkidle' });
  const rail2 = store.locator('section.hs-bestselling');
  await rail2.scrollIntoViewIfNeeded();
  await rail2.screenshot({ path: `${OUT}/hc-store-manual-${w}.png` });
  m.storeManualOrder = await rail2.locator('.kbb-card').evaluateAll((els) => els.map((e) => e.innerText.split('\n').find((l) => /COSRX|Anua|Joseon|Round Lab|Torriden/.test(l))));

  // Unsaved-changes guard on close.
  await page.fill('#hph-ta-in', 'cream');
  await page.waitForSelector('#hph-ta-res .kpp-hit');
  await page.click('#hph-ta-res .kpp-hit >> nth=0');
  let asked = '';
  page.once('dialog', (d) => { asked = d.message(); d.dismiss(); });
  await page.click('.hph-x');
  m.guard = asked;

  // The two strips at the top of the homepage.
  await store.goto(BASE + '/', { waitUntil: 'networkidle' });
  await store.waitForTimeout(300);
  await store.screenshot({ path: `${OUT}/hc-strips-${w}.png`, clip: { x: 0, y: 0, width: w, height: w < 600 ? 640 : 700 } });
  m.strips = await store.evaluate(() => {
    const f = (s) => { const e = document.querySelector(s); if (!e) return null; const r = e.getBoundingClientRect(); const c = getComputedStyle(e); return { display: c.display, h: Math.round(r.height), w: Math.round(r.width), font: c.fontSize, bg: c.backgroundColor, color: c.color }; };
    return { top: f('.kts'), countries: f('.kbb-home .kfb'), pill: f('.kbb-home .kfb-tx') };
  });

  fs.writeFileSync(`${OUT}/measure-${w}.json`, JSON.stringify(m, null, 2));
  console.log(JSON.stringify(m));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
