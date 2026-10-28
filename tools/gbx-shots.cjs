/* Lane GBX -- the PREVIEW shots: thumbnail popups A-D, the Gifts Criteria
   button and popups 1-3, the photo glow G1-G4, at 390 and 1280 (EN, plus an
   Arabic set), on the real preview shop with tools/gbx-kit.{js,css} injected.
   FG_SEED=gbx-seed.php sh tools/fg-preview.sh 12340
   BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> WEB=<webroot> node tools/gbx-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID);
const DOCS = path.resolve(__dirname, '../docs/gbx-previews');
const DATA = fs.readFileSync(path.join(process.env.WEB, 'gbx-data.json'), 'utf8');
const KIT = fs.readFileSync(path.join(__dirname, 'gbx-kit.js'), 'utf8');
const CSS = fs.readFileSync(path.join(__dirname, 'gbx-kit.css'), 'utf8');
for (const d of ['popup', 'criteria', 'glow']) fs.mkdirSync(path.join(DOCS, d), { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const out = (d, f) => path.join(DOCS, d, f);

async function openBox(page, pre, where, w) {
  await page.goto(BASE + pre + (where === 'panel' ? '/' : '/checkout/'), { waitUntil: 'networkidle' });
  if (where === 'panel') { await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(400); }
  if (where === 'checkout' && w === 390) { const row = page.locator('#kbbSummary .cosr').first(); if (await row.count()) await row.click(); await page.waitForTimeout(400); }
  const f = page.locator((where === 'panel' ? '#cart' : '#kbbSummary') + ' .fgb').filter({ visible: true }).first();
  await f.evaluate((x) => x.scrollIntoView({ block: 'center' }));
  await page.waitForTimeout(200);
  return f;
}
const firstTry = (loc) => loc.evaluate((b) => { const r = b.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!h && (h === b || b.contains(h)); });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const res = [];
  for (const lang of ['en', 'ar']) for (const w of [390, 1280]) {
    const pre = lang === 'ar' ? '/ar' : '', t = `${lang}-${w}`;
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    await ctx.addInitScript({ content: 'window.GBX_DATA=' + DATA + ';window.__cls=0;new PerformanceObserver(function(l){l.getEntries().forEach(function(e){if(!e.hadRecentInput)window.__cls+=e.value})}).observe({type:"layout-shift",buffered:true});'
      + 'document.addEventListener("DOMContentLoaded",function(){var s=document.createElement("style");s.textContent=' + JSON.stringify(CSS) + ';document.head.appendChild(s)});\n' + KIT });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    let reqs = 0; page.on('request', () => reqs++);
    await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
    const st = await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
    if (st !== 200) throw new Error('add ' + st);
    const r = { lang, w };
    for (const where of (lang === 'ar' ? ['panel'] : ['panel', 'checkout'])) {
      // The box with the new button, closed and opened.
      let f = await openBox(page, pre, where, w);
      reqs = 0;
      r[where + 'Cls'] = await page.evaluate(() => window.__cls);
      await f.screenshot({ path: out('criteria', `box-${where}-${t}.png`) });
      const cr = f.locator('.fgb-th .gbx-cr');
      r[where + 'CritFirstTry'] = await firstTry(cr);
      r[where + 'ViewAllFirstTry'] = await firstTry(f.locator('.fgb-th .fgb-tg'));
      r[where + 'ThumbFirstTry'] = await firstTry(f.locator('.fgb-th .fgb-t').nth(2));
      // Thumbnail popups A-D (the third photo: the Pink tint, on sale).
      for (const d of ['A', 'B', 'C', 'D']) {
        await page.evaluate((p) => { window.GBX_OPT = { pop: p }; }, d);
        const tile = f.locator('.fgb-th .fgb-t').nth(2);
        await tile.evaluate((x) => { x.scrollIntoView({ block: 'center' }); x.click(); }); await page.waitForTimeout(350);
        if (d === 'C') await f.screenshot({ path: out('popup', `${d}-${where}-${t}.png`) });
        else await page.screenshot({ path: out('popup', `${d}-${where}-${t}.png`) });
        r[`pop${d}-${where}`] = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, focusIn: !!document.activeElement.closest('.gbx-d,.gbx-c') }));
        await page.keyboard.press('Escape'); await page.waitForTimeout(150);
        r[`pop${d}-${where}`].escClosed = await page.evaluate(() => { const g = document.querySelector('dialog.gbx-d[open], .gbx-c'); if (g) { if (g.close) g.close(); else g.remove(); } return !g; });
        r[`pop${d}-${where}`].focusBack = await page.evaluate(() => document.activeElement.classList.contains('fgb-t'));
      }
      // Gifts Criteria 1-3.
      for (const d of ['1', '2', '3']) {
        await page.evaluate((c) => { window.GBX_OPT = { crit: c }; }, d);
        await cr.evaluate((x) => { x.scrollIntoView({ block: 'center' }); x.click(); }); await page.waitForTimeout(350);
        await page.screenshot({ path: out('criteria', `${d}-${where}-${t}.png`) });
        r[`crit${d}-${where}`] = await page.evaluate(() => { const g = document.querySelector('dialog.gbx-d'); return { sw: document.documentElement.scrollWidth, dlgOverflowX: g.scrollWidth > g.clientWidth, focusIn: !!document.activeElement.closest('.gbx-d') }; });
        await page.keyboard.press('Escape'); await page.waitForTimeout(150);
        r[`crit${d}-${where}`].escClosed = await page.evaluate(() => { const g = document.querySelector('dialog.gbx-d'); const open = g.open; if (open) g.close(); return !open; });
        r[`crit${d}-${where}`].focusBack = await page.evaluate(() => document.activeElement.classList.contains('gbx-cr'));
      }
      r[where + 'RequestsWhileOpening'] = reqs; // photos only: the popup's own picture
    }
    // Glow G1-G4 (+ today, for the compare sheet), English only.
    if (lang === 'en') {
      for (const g of ['G0', 'G1', 'G2', 'G3', 'G4']) {
        for (const where of ['panel', 'checkout']) {
          const f = await openBox(page, pre, where, w);
          await page.evaluate((x) => { if (x === 'G0') delete document.documentElement.dataset.gbxGlow; else document.documentElement.dataset.gbxGlow = x; }, g);
          await page.waitForTimeout(150);
          await f.screenshot({ path: out('glow', `${g}-${where}-${t}.png`) });
        }
        await page.goto(BASE + '/', { waitUntil: 'networkidle' });
        const before = await page.evaluate(() => document.querySelector('.fgh-card').offsetHeight);
        await page.evaluate((x) => { if (x !== 'G0') document.documentElement.dataset.gbxGlow = x; }, g);
        const card = page.locator('.fgh-card'); await card.scrollIntoViewIfNeeded(); await page.waitForTimeout(300);
        r[`glow${g}-homeHeightSame`] = before === await page.evaluate(() => document.querySelector('.fgh-card').offsetHeight);
        await card.screenshot({ path: out('glow', `${g}-home-${t}.png`) });
      }
    }
    r.errors = errors;
    res.push(r);
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(DOCS, 'measured.json'), JSON.stringify(res, null, 1));
  console.log(res.map((x) => JSON.stringify(x)).join('\n'));
})().catch((e) => { console.error(e); process.exit(1); });
