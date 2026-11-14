/* Lane ARDX: the product details section of /product/<slug>/ and /ar/product/<slug>/,
   at 390 and 1280, with the tab body opened in full (read-more expanded).
   BASE=https://kbeautybliss.com OUT=dir SLUGS=a,b PREFIX=before node tools/ardx-shots.cjs
   Prints per shot: details box height, scrollWidth, console errors. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE || 'https://kbeautybliss.com';
const OUT = path.resolve(process.env.OUT || 'docs/plans/arabic-description-spacing/shots');
const PREFIX = process.env.PREFIX || 'before';
const LOCS = (process.env.LOCS || 'ar').split(',');
const SLUGS = (process.env.SLUGS || '').split(',').filter(Boolean);
fs.mkdirSync(OUT, { recursive: true });
(async () => {
  const opts = { executablePath: '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome' };
  if (process.env.HTTPS_PROXY && BASE.startsWith('https')) opts.proxy = { server: process.env.HTTPS_PROXY };
  const browser = await chromium.launch(opts);
  for (const slug of SLUGS) for (const loc of LOCS) for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, ignoreHTTPSErrors: true, bypassCSP: true });
    const page = await ctx.newPage(); const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    const url = BASE + (loc === 'ar' ? '/ar' : '') + '/product/' + slug + '/';
    await page.goto(url, { waitUntil: 'load', timeout: 90000 });
    // the host's bot check ("One moment, please...") reloads itself after 5 s
    for (let i = 0; i < 4 && !(await page.$('#details')); i++) { await page.waitForTimeout(8000); if (!(await page.$('#details'))) await page.goto(url, { waitUntil: 'load', timeout: 90000 }); }
    // open the whole body: desktop clamp off, phone accordion's first panel is open already
    // the shop's own "Read more" opens the whole body; then its button is hidden from the picture
    await page.evaluate(() => { const b = [...document.querySelectorAll('.dtabpanel.on .readmore')].find((x) => x.getClientRects().length); if (b) b.click(); });
    await page.waitForTimeout(400);
    await page.addStyleTag({ content: '.readmore{visibility:hidden!important}' });
    await page.evaluate(() => {
      const c = ['.dtabpanel.on .dcontent', '.macc-i.open .macc-b .dcontent', '#details'].map((s) => document.querySelector(s))
        .find((e) => e && e.getClientRects().length && e.getBoundingClientRect().height > 0);
      if (c) c.setAttribute('data-ardx', '1');
    });
    const sel = '[data-ardx]';
    const el = await page.$(sel);
    await el.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    const file = `${OUT}/${PREFIX}-${loc}-${slug}-${w}.png`;
    await el.screenshot({ path: file });
    const m = await page.evaluate((s) => {
      const b = document.querySelector(s);
      return { boxH: b ? Math.round(b.getBoundingClientRect().height) : null, blanks: b ? b.querySelectorAll('.kbb-bl').length : null, scrollW: document.documentElement.scrollWidth };
    }, sel);
    console.log(path.basename(file), JSON.stringify({ ...m, errors }));
    await ctx.close();
  }
  await browser.close();
})();
