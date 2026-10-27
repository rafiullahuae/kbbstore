/* Lane FGV: the gift that is ONE VARIATION ("Glow Lip Tint – Pink") in the
   cart panel, the cart page and the checkout summary, at 390 and 1280: its
   own name, its own photo, View all / Hide answering the first real click,
   scrollWidth equal to the viewport, console errors counted.
   FG_SEED=fgv-seed.php sh tools/fg-preview.sh 11780
   BASE=http://127.0.0.1:11780 PID=<a product in a range> node tools/fgv-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID), OUT = path.resolve(__dirname, '../docs/fgv-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const clickable = (loc) => loc.evaluate((b) => { const r = b.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!h && (h === b || b.contains(h)); });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = [];
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    const s = await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
    if (s !== 200) throw new Error('add ' + s);
    for (const where of ['panel', 'cart', 'checkout']) {
      const url = where === 'panel' ? '/' : where === 'cart' ? '/cart/' : '/checkout/';
      await page.goto(BASE + url, { waitUntil: 'networkidle' });
      const scope = where === 'panel' ? '#cart' : where === 'cart' ? 'main' : '#kbbSummary';
      if (where === 'panel') { await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(400); }
      if (where === 'checkout' && w === 390) { const row = page.locator('#kbbSummary .cosr').first(); if (await row.count()) await row.click(); await page.waitForTimeout(400); }
      const f = page.locator(scope + ' .fgb').filter({ visible: true }).first();
      if (!(await f.count())) { results.push({ w, where, box: false }); continue; }
      await f.scrollIntoViewIfNeeded();
      const va = f.locator('.fgb-th .fgb-tg');
      const vaOk = await clickable(va);
      await va.click();
      await page.waitForTimeout(200);
      const pink = await f.evaluate((x) => {
        const row = [...x.querySelectorAll('.fgb-lr')].find((r) => /Pink/.test(r.textContent));
        const img = row && row.querySelector('img');
        return row ? { name: row.querySelector('.fgb-nm, .fgb-ln b, .fgb-ln').textContent.replace(/\s+/g, ' ').trim(), img: img ? (img.currentSrc || img.src).split('/').pop() : null, imgW: img ? Math.round(img.getBoundingClientRect().width) : 0, loaded: img ? img.complete && img.naturalWidth > 0 : false } : null;
      });
      const thumbs = await f.evaluate((x) => [...x.querySelectorAll('.fgb-th img')].map((i) => (i.currentSrc || i.src).split('/').pop()));
      await f.screenshot({ path: `${OUT}/shop-${where}-${w}.png` });
      const hd = f.locator('.fgb-ls .fgb-tg'); await hd.scrollIntoViewIfNeeded();
      const hdOk = await clickable(hd); await hd.click();
      results.push({ w, where, pink, closedThumbs: thumbs, viewAllFirstTry: vaOk, hideFirstTry: hdOk, scrollW: await page.evaluate(() => document.documentElement.scrollWidth), errors: errors.splice(0) });
    }
    await ctx.close();
  }
  await browser.close();
  console.log(results.map((r) => JSON.stringify(r)).join('\n'));
})().catch((e) => { console.error(e); process.exit(1); });
