/* Lane NA: New Arrivals, in Chromium, on the preview shop from tools/na-preview.sh.
     BASE=http://127.0.0.1:PORT node tools/na-shots.cjs
   Shop: /collections/new-arrivals/ EN + AR at 390 and 1280, /new-in/ landing.
   Admin: Catalog -> New Arrivals at 1280 and 390 -- the settings card, Display's
   grid, two crosses tapped (unsaved), after Save, and the grid reloaded with the
   saved exclusions faded in place. Pictures to docs/na-shots/. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE;
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const OUT = path.resolve(__dirname, '../docs/na-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const report = { shop: [], admin: [], errors: [] };

async function shop(browser, w) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, userAgent: w < 600 ? UA_M : UA });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => report.errors.push(`shop ${w}: ${e}`));
  page.on('console', (m) => { if (m.type() === 'error') report.errors.push(`shop ${w} console: ${m.text()}`); });
  for (const [name, url] of [['en', '/collections/new-arrivals/'], ['ar', '/ar/collections/new-arrivals/'], ['newin', '/new-in/'], ['newin-ar', '/ar/new-in/']]) {
    const res = await page.goto(BASE + url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(300);
    const m = await page.evaluate(() => ({
      url: location.pathname, sw: document.documentElement.scrollWidth, w: innerWidth,
      first: [...document.querySelectorAll('a[href*="/product/"]')].map((a) => a.getAttribute('href')).filter((v, i, s) => s.indexOf(v) === i).slice(0, 4),
      h1: (document.querySelector('h1') || {}).textContent?.trim(),
    }));
    // The first product card answers a real click.
    const hit = await page.evaluate(() => {
      const a = document.querySelector('main a[href*="/product/"], a[href*="/product/"]');
      if (!a) return null;
      const r = a.getBoundingClientRect();
      const el = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(r.height / 2, 40));
      return !!el && (el === a || a.contains(el));
    });
    report.shop.push({ w, name, status: res.status(), redirected: res.request().redirectedFrom() ? res.request().redirectedFrom().url() : null, hit, ...m });
    await page.screenshot({ path: `${OUT}/shop-${name}-${w}.png` });
  }
  await ctx.close();
}

async function admin(browser, w) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 1000 }, userAgent: w < 600 ? UA_M : UA, isMobile: w < 600, hasTouch: w < 600 });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => report.errors.push(`admin ${w}: ${e}`));
  page.on('console', (m) => { if (m.type() === 'error') report.errors.push(`admin ${w} console: ${m.text()}`); });
  page.on('dialog', (d) => d.accept());
  let gridBytes = 0;
  page.on('response', async (r) => { if (r.url().includes('/admin-api/new-arrivals/grid')) { try { gridBytes = (await r.body()).length; } catch (e) {} } });
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.evaluate(() => window.go('newarrivals'));
  await page.waitForSelector('[data-na] .na-set', { timeout: 15000 });
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/admin-settings-${w}.png`, fullPage: w < 600 });

  const t0 = Date.now();
  await page.click('[data-na-display]');
  await page.waitForSelector('.na-card');
  const firstPaint = Date.now() - t0;
  await page.waitForTimeout(400);
  await page.locator('[data-na-bar]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/admin-grid-${w}.png` });

  // Two crosses tapped, and one card moved to the top (drag on desktop, the up arrow on a phone).
  const xs = page.locator('.na-card .na-x');
  await xs.nth(1).click();
  await xs.nth(3).click();
  if (w < 600) { await page.locator('.na-card').nth(2).locator('[data-na-mv="-1"]').click(); }
  else { await page.locator('.na-card').nth(5).dragTo(page.locator('.na-card').nth(0)); }
  const pend = await page.textContent('.na-pend');
  await page.screenshot({ path: `${OUT}/admin-unsaved-${w}.png` });

  await page.click('[data-na-save]');
  await page.waitForFunction(() => /Saved/.test(document.querySelector('.na-flash')?.textContent || ''), null, { timeout: 15000 });
  const after = await page.textContent('[data-na-bar]');
  await page.screenshot({ path: `${OUT}/admin-saved-${w}.png` });

  await page.click('[data-na-display]');
  await page.waitForSelector('.na-card');
  await page.waitForTimeout(400);
  const faded = await page.locator('.na-card.off').count();
  await page.screenshot({ path: `${OUT}/admin-faded-${w}.png` });

  // Bring them back (tap the grey cross) and save, so the next run starts clean.
  const offs = await page.locator('.na-card.off .na-x').count();
  for (let i = 0; i < offs; i++) await page.locator('.na-card.off .na-x').first().click();
  await page.click('[data-na-save]');
  await page.waitForFunction(() => /Saved/.test(document.querySelector('.na-flash')?.textContent || ''), null, { timeout: 15000 });
  await page.click('[data-na-reset]');
  await page.waitForSelector('#kbbSureBg.on');
  if (w >= 600) await page.screenshot({ path: `${OUT}/admin-reset-asks-${w}.png` });
  await page.click('#kbbSureYes');
  await page.waitForFunction(() => /newest first/.test(document.querySelector('[data-na-note]')?.textContent || ''), null, { timeout: 15000 });
  await page.waitForSelector('.na-card');

  // Leaving with an unsaved cross asks nothing: it goes to "Unfinished" in the top bar.
  await page.locator('.na-card .na-x').first().click();
  await page.evaluate(() => window.go('dash'));
  await page.waitForTimeout(600);
  const unfinished = await page.evaluate(() => (document.getElementById('kbbDraftsBtn') || {}).textContent || '');
  await page.evaluate(() => window.kbbDrafts && window.kbbDrafts.flush && window.kbbDrafts.list && window.kbbDrafts.list());

  const sw = await page.evaluate(() => document.documentElement.scrollWidth);
  report.admin.push({ w, unfinished: unfinished.trim(), firstPaintMs: firstPaint, gridBytes, pendingText: pend, afterSave: after.replace(/\s+/g, ' ').trim(), fadedAfterReload: faded, scrollWidth: sw });
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) await shop(browser, w);
  for (const w of [1280, 390]) await admin(browser, w);
  await browser.close();
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
})();
