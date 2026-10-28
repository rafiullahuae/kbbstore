/* Lane GBX: the gift box in the full size -> mini -> mask order, at 390 and
   1280, EN and AR: the cart panel (photos, then the opened list), the checkout
   summary (same) and the homepage gift section (rows, then a row's popup).
   FG_SEED=gbx-seed.php sh tools/fg-preview.sh 12340
   BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> node tools/gbx-seq-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID), OUT = path.resolve(__dirname, '../docs/gbx-previews/sequence');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const names = (loc, sel) => loc.evaluate((x, s) => [...x.querySelectorAll(s)].map((i) => (i.getAttribute('alt') || i.textContent).replace(/\s+/g, ' ').trim()), sel);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = [];
  for (const lang of ['en', 'ar']) for (const w of [390, 1280]) {
    const pre = lang === 'ar' ? '/ar' : '';
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    await ctx.addInitScript(() => { window.__cls = 0; new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    // Homepage section, before anything is in the bag.
    await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
    const sec = page.locator('section.fgh, .fgh').first();
    await sec.scrollIntoViewIfNeeded(); await page.waitForTimeout(500);
    const t = `${lang}-${w}`;
    await sec.screenshot({ path: `${OUT}/home-section-${t}.png` });
    const homeWords = await page.$$eval('.fgh-w', (els) => els.map((e) => e.textContent.trim()));
    const rows = page.locator('.fgh-r');
    await rows.nth(Math.min(3, (await rows.count()) - 1)).click(); await page.waitForTimeout(500);
    const pop = page.locator('.fgh-pop').filter({ visible: true }).first();
    let homePopup = null;
    if (await pop.count()) { homePopup = await names(pop, '.fgh-g:not([hidden]) .fgh-nm'); await page.screenshot({ path: `${OUT}/home-popup-${t}.png` }); }
    await page.keyboard.press('Escape');
    const homeCls = await page.evaluate(() => window.__cls);
    const homeScroll = await page.evaluate(() => document.documentElement.scrollWidth);
    const s = await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
    if (s !== 200) throw new Error('add ' + s);
    const out = { lang, w, homeWords, homePopup, homeCls, homeScroll };
    for (const where of ['panel', 'checkout']) {
      await page.goto(BASE + pre + (where === 'panel' ? '/' : '/checkout/'), { waitUntil: 'networkidle' });
      if (where === 'panel') { await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(400); }
      if (where === 'checkout' && w === 390) { const row = page.locator('#kbbSummary .cosr').first(); if (await row.count()) await row.click(); await page.waitForTimeout(400); }
      const f = page.locator((where === 'panel' ? '#cart' : '#kbbSummary') + ' .fgb').filter({ visible: true }).first();
      if (!(await f.count())) { out[where] = 'no box'; continue; }
      await f.scrollIntoViewIfNeeded();
      const words = await f.evaluate((x) => (x.querySelector('.fgb-w, .fgb-wd, .fgb-hd') || x).textContent.replace(/\s+/g, ' ').trim().slice(0, 160));
      const thumbs = await names(f, '.fgb-th img');
      await f.screenshot({ path: `${OUT}/${where}-photos-${t}.png` });
      await f.locator('.fgb-th .fgb-tg').click(); await page.waitForTimeout(250);
      const list = await f.evaluate((x) => [...x.querySelectorAll('.fgb-ls .fgb-lr')].map((r) => (r.querySelector('.fgb-nm, .fgb-ln b, .fgb-ln') || r).textContent.replace(/\s+/g, ' ').trim()));
      await f.screenshot({ path: `${OUT}/${where}-list-${t}.png` });
      out[where] = { words, thumbs, list, scrollW: await page.evaluate(() => document.documentElement.scrollWidth) };
    }
    out.errors = errors;
    results.push(out);
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'measured.json'), JSON.stringify(results, null, 1));
  console.log(results.map((r) => JSON.stringify(r)).join('\n'));
})().catch((e) => { console.error(e); process.exit(1); });
