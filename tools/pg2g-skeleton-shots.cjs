/*
 * Lane PG2: what a shopper sees while the photos are still on their way.
 *
 *   node tools/pg2g-skeleton-shots.cjs OUTDIR LABEL[,LABEL]
 *
 * Every photograph request is held for 6 s (a very slow connection), the page
 * is captured 1.5 s after it is usable, then again once the photos are in.
 * Product page and a category grid, at 390 and 1280. Also records CLS across
 * the photos' arrival and document.documentElement.scrollWidth.
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const [OUT, LABELS] = process.argv.slice(2);
const APP = path.dirname(__dirname);
const port = (l) => fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-spd-' + l, 'port'), 'utf8').trim();
const PAGES = [['product', '/product/spd-product-500/'], ['category', '/collections/spd-dept-1/'], ['brand', '/brands/anua/'], ['home', '/']];
const SIZES = [[390, 844, 2, true], [1280, 800, 1, false]];

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const label of LABELS.split(',')) for (const [name, url] of PAGES) for (const [w, h, dpr, mob] of SIZES) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: dpr, isMobile: mob, hasTouch: mob, serviceWorkers: 'block' });
    await ctx.addInitScript(() => {
      window.__cls = 0; window.__errs = [];
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
    });
    const page = await ctx.newPage();
    const errs = [];
    page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text().slice(0, 120)); });
    page.on('pageerror', (e) => errs.push('pageerror ' + e.message.slice(0, 120)));
    await page.route(/\.(jpe?g|png|webp)(\?|$)/, (r) => setTimeout(() => r.continue().catch(() => {}), 6000));
    const cdp = await ctx.newCDPSession(page);
    page.goto('http://127.0.0.1:' + port(label) + url, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1500);
    const shot = async (tag) => {
      const s = await cdp.send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(path.join(OUT, `${name}-${w}-${label}-${tag}.png`), Buffer.from(s.data, 'base64'));
    };
    await shot('loading');
    await page.waitForLoadState('load', { timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(800);
    await shot('loaded');
    out[`${label} ${name} ${w}`] = await page.evaluate(() => ({ cls: Math.round(window.__cls * 1000) / 1000, sw: document.documentElement.scrollWidth }));
    out[`${label} ${name} ${w}`].consoleErrors = errs;
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
