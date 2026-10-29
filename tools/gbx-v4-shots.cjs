/* Lane GBX v4 (2.60.621): the Gifts Criteria popup, design 3 (tabs), at 320,
   360, 390, 430 and 1280, English and Arabic. Every range tab is tapped with a
   real click; after each the sheet's box (left, width, height), its own
   scrollLeft, the page's scrollWidth, the close button's corner and the
   product grid's horizontal scroll are measured. Shots of 500-999, 1,000-1,999
   and 2,000+ per width. Also the link size (View all / Gifts Criteria).
   GBX_X='{"mode":"live","crit":"3"}' FG_SEED=gbx-feature-seed.php sh tools/fg-preview.sh 12400
   BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> OUT=v4 node tools/gbx-v4-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID), OUT = path.resolve(__dirname, '../docs/gbx-feature-shots', process.env.OUT || 'v4');
const WIDTHS = (process.env.WIDTHS || '320,360,390,430,1280').split(',').map(Number);
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const tap = async (page, loc) => { const b = await loc.boundingBox(); await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2); };
const res = [];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const lang of ['en', 'ar']) for (const w of WIDTHS) {
    const pre = lang === 'ar' ? '/ar' : '';
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w <= 430 ? (w === 320 ? 568 : 780) : 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    const errors = []; page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); }); page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
    await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
    await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
    await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(500);
    const f = page.locator('#cart .fgb').first();
    await f.evaluate((x) => x.scrollIntoView({ block: 'center' })); await page.waitForTimeout(200);
    const r = { lang, w, links: await f.evaluate((x) => [getComputedStyle(x.querySelector('.fgb-th .fgb-tg')).fontSize, getComputedStyle(x.querySelector('.fgb-th .gbx-cr')).fontSize]) };
    if (w === 390 || w === 1280) await f.screenshot({ path: path.join(OUT, `box-${lang}-${w}.png`) });
    await tap(page, f.locator('.fgb-th .gbx-cr')); await page.waitForTimeout(400);
    const n = await page.locator('.gbx-cp:popover-open .gbx-tabs label').count();
    r.tabs = [];
    for (let i = 0; i < n; i++) {
      // As a thumb would: swipe the tab strip so the tab is in sight, then tap it.
      await page.evaluate((i) => { const s = document.querySelector('.gbx-cp:popover-open .gbx-tabs'), l = s.querySelectorAll('label')[i]; const sr = s.getBoundingClientRect(), lr = l.getBoundingClientRect();
        if (lr.right > sr.right) s.scrollLeft += lr.right - sr.right + 4; if (lr.left < sr.left) s.scrollLeft -= sr.left - lr.left + 4; }, i);
      await page.waitForTimeout(150);
      await tap(page, page.locator('.gbx-cp:popover-open .gbx-tabs label').nth(i)); await page.waitForTimeout(350);
      const m = await page.evaluate(() => {
        const d = document.querySelector('.gbx-cp:popover-open'); if (!d) return { closed: true };
        const b = d.getBoundingClientRect(), x = d.querySelector('.gbx-x').getBoundingClientRect();
        const tp = [...d.querySelectorAll('.gbx-tp')].find((t) => getComputedStyle(t).display !== 'none' && getComputedStyle(t).visibility !== 'hidden'); const g = tp && tp.querySelector('.gbx-grid');
        const lab = d.querySelector('.gbx-tabs input:checked').closest('label').textContent.trim();
        return { tab: lab, left: Math.round(b.left), right: Math.round(innerWidth - b.right), width: Math.round(b.width), height: Math.round(b.height), dialogScrollLeft: d.scrollLeft, bodyScrollLeft: d.querySelector('.gbx-cb').scrollLeft,
          pageSW: document.documentElement.scrollWidth, closeAt: document.documentElement.dir === 'rtl' ? (x.left - b.left < 40 ? 'start' : 'other') : (b.right - x.right < 40 ? 'end' : 'other'),
          items: g ? g.children.length : 0, gridScrolls: g ? g.scrollWidth > g.clientWidth + 1 : false, rows: g ? new Set([...g.children].map((c) => Math.round(c.getBoundingClientRect().top))).size : 0,
          bodyScrollsV: d.querySelector('.gbx-cb').scrollHeight > d.querySelector('.gbx-cb').clientHeight + 1, fontPx: getComputedStyle(d).fontSize };
      });
      r.tabs.push(m);
      if (m.closed) { await tap(page, f.locator('.fgb-th .gbx-cr')); await page.waitForTimeout(400); continue; }
      if (/500|1,000|2,000|1,600/.test(m.tab)) await page.screenshot({ path: path.join(OUT, `criteria-${lang}-${w}-${m.tab.replace(/[^0-9]+/g, '-').replace(/^-|-$/g, '')}.png`) });
    }
    // The grid scrolls sideways when a range has more than 6 gifts.
    r.errors = errors;
    res.push(r);
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'measured.json'), JSON.stringify(res, null, 1));
  for (const r of res) {
    console.log(r.lang, r.w, 'links', r.links.join('/'), 'errors', r.errors.length);
    for (const t of r.tabs) console.log('   ', JSON.stringify(t));
  }
})().catch((e) => { console.error(e); process.exit(1); });
