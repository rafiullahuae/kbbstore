/* Lane FG5: the free gifts box's "Gift box look", photographed and measured.
   BASE=http://127.0.0.1:PORT MODE=<mode> TAG=<name> node tools/fg5-shots.cjs
   MODE=dump   the cart page's box markup, EN and AR, to storage/fg5-logs/<TAG>-<lang>.html
               (for the before/after diff at defaults);
   MODE=shots  the OPENED box on the cart page at 390 and 1280, EN and AR, to
               docs/lane-fg5/<TAG>-<lang>-<w>.png, plus its measurements;
   MODE=check  cart panel, cart page and checkout at 390 and 1280: View all and
               Hide answer the first real click (elementFromPoint over the
               button), scrollWidth equals the viewport, console errors.
   A basket in the AED 2,000+ range (7 gifts, the longest names). */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, MODE = process.env.MODE || 'shots', TAG = process.env.TAG || 'defaults';
const LOGS = path.resolve(__dirname, '../storage/fg5-logs'), OUT = path.resolve(__dirname, '../docs/lane-fg5');
fs.mkdirSync(LOGS, { recursive: true }); fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

async function add(page, pre, pid) {
  await page.goto(BASE + pre + '/', { waitUntil: 'domcontentloaded' });
  const s = await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, pid);
  if (s !== 200) throw new Error('add ' + s);
}
async function clickable(page, loc) {
  return loc.evaluate((b) => { const r = b.getBoundingClientRect(); const h = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!h && (h === b || b.contains(h)); });
}

(async () => {
  const PID = Number(process.env.PID);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = [];
  for (const lang of ['en', 'ar']) for (const w of (MODE === 'dump' ? [1280] : [390, 1280])) {
    const pre = lang === 'ar' ? '/ar' : '';
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await add(page, pre, PID);
    if (MODE === 'dump') {
      await page.goto(BASE + pre + '/cart/', { waitUntil: 'domcontentloaded' });
      fs.writeFileSync(`${LOGS}/${TAG}-${lang}.html`, await page.locator('.fgb').first().evaluate((f) => f.outerHTML));
    } else if (MODE === 'shots') {
      await page.goto(BASE + pre + '/cart/', { waitUntil: 'load' });
      const f = page.locator('.fgb').filter({ visible: true }).first();
      await f.scrollIntoViewIfNeeded();
      await f.locator('.fgb-th .fgb-tg').click();
      await page.waitForTimeout(150);
      const m = await f.evaluate((x) => {
        const rows = [...x.querySelectorAll('.fgb-lr')].map((r) => {
          const ln = r.querySelector('.fgb-ln').getBoundingClientRect(), lv = r.querySelector('.fgb-lv').getBoundingClientRect(), t = r.querySelector('.fgb-t').getBoundingClientRect();
          const rtl = getComputedStyle(r).direction === 'rtl';
          return { h: Math.round(r.getBoundingClientRect().height), photo: Math.round(t.width), spaceBeforeFree: Math.round(rtl ? ln.left - lv.right : lv.left - ln.right) };
        });
        const nm = x.querySelector('.fgb-nm') || x.querySelector('.fgb-ln');
        const cs = getComputedStyle(nm);
        return { boxH: Math.round(x.getBoundingClientRect().height), titlePx: cs.fontSize, mask: cs.webkitMaskImage || cs.maskImage, clamp: cs.webkitLineClamp,
          brandShown: [...x.querySelectorAll('.fgb-ln small')].some((s) => s.getClientRects().length > 0), closedPhoto: Math.round(x.querySelector('.fgb-th .fgb-t').getBoundingClientRect().width),
          sep: getComputedStyle(x.querySelectorAll('.fgb-lr')[1] || x).borderTopStyle + ' ' + getComputedStyle(x.querySelectorAll('.fgb-lr')[1] || x).borderTopColor, rows };
      });
      await f.screenshot({ path: `${OUT}/${TAG}-${lang}-${w}.png` });
      results.push({ tag: TAG, lang, w, ...m, scrollW: await page.evaluate(() => document.documentElement.scrollWidth), errors });
    } else {
      for (const where of ['panel', 'cart', 'checkout']) {
        const url = where === 'panel' ? '/' : where === 'cart' ? '/cart/' : '/checkout/';
        await page.goto(BASE + pre + url, { waitUntil: 'networkidle' });
        const scope = where === 'panel' ? '#cart' : where === 'cart' ? 'main' : '#kbbSummary';
        if (where === 'panel') { await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(400); }
        if (where === 'checkout' && w === 390) { const row = page.locator('#kbbSummary .cosr').first(); if (await row.count()) await row.click(); await page.waitForTimeout(400); }
        const f = page.locator(scope + ' .fgb').filter({ visible: true }).first();
        if (!(await f.count())) { results.push({ lang, w, where, box: false }); continue; }
        await f.scrollIntoViewIfNeeded();
        const va = f.locator('.fgb-th .fgb-tg');
        const vaOk = await clickable(page, va); await va.click();
        const opened = await f.evaluate((x) => !x.querySelector('.fgb-ls').hidden);
        const hd = f.locator('.fgb-ls .fgb-tg'); await hd.scrollIntoViewIfNeeded();
        const hdOk = await clickable(page, hd); await hd.click();
        const closed = await f.evaluate((x) => x.querySelector('.fgb-ls').hidden);
        results.push({ lang, w, where, viewAllFirstTry: vaOk && opened, hideFirstTry: hdOk && closed, scrollW: await page.evaluate(() => document.documentElement.scrollWidth), errors: errors.splice(0) });
      }
    }
    await ctx.close();
  }
  await browser.close();
  if (results.length) { fs.appendFileSync(`${LOGS}/${MODE}.jsonl`, results.map((r) => JSON.stringify(r)).join('\n') + '\n'); console.log(results.map((r) => JSON.stringify(r)).join('\n')); }
})().catch((e) => { console.error(e); process.exit(1); });
