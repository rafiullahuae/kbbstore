/*
 * Lane PH storefront shots: the top of each page at 390 and 1280 (the
 * viewport, not the full page -- the header is what changed), plus the numbers
 * that matter for it: the h1 count, the header's height, scrollWidth.
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/phb-shots.cjs http://127.0.0.1:10761 docs/lane-ph-shots before /about/,/contact-us/
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = process.argv[2] || 'http://127.0.0.1:10761';
const OUT = path.resolve(process.argv[3] || 'docs/lane-ph-shots');
const TAG = process.argv[4] || 'after';
const PAGES = (process.argv[5] || '/about/,/contact-us/,/privacy-policy/,/blog/').split(',');
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext(w < 600 ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, userAgent: UA } : { viewport: { width: 1280, height: 900 } });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
    const page = await ctx.newPage();
    for (const p of PAGES) {
      const errs = [];
      page.removeAllListeners('console');
      page.on('console', (c) => { if (c.type() === 'error') errs.push(c.text().slice(0, 120)); });
      await page.goto(BASE + p, { waitUntil: 'load' });
      await page.waitForTimeout(700);
      const m = await page.evaluate(() => {
        const ph = document.querySelector('.brw-phw');
        const r = ph ? ph.getBoundingClientRect() : null;
        const h1 = [...document.querySelectorAll('h1')];
        return { h1: h1.length, h1text: h1.map((e) => e.textContent.trim().slice(0, 40)), panel: r ? { top: Math.round(r.top), h: Math.round(r.height), w: Math.round(r.width) } : null,
          nameFs: ph ? getComputedStyle(ph.querySelector('.brw-ph__name')).fontSize : null, sw: document.documentElement.scrollWidth, pt: !!document.querySelector('.kbb-pt'), pb: !!document.querySelector('.kbb-pb') };
      });
      const name = `${TAG}-${p.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home'}-${w}.png`;
      await page.screenshot({ path: `${OUT}/${name}` });
      console.log(JSON.stringify({ w, p, ...m, errors: errs.length ? errs : 0, shot: name }));
    }
    await ctx.close();
  }
  await browser.close();
})();
