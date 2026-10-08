/*
 * Lane CB storefront screenshots, 390 and 1280, top of the page.
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/cb-shots.cjs <base> <out> <tag> name=/path,name=/path ...
 */
const { chromium } = require('playwright');
const path = require('path');
const [BASE, OUTD, TAG, LIST] = process.argv.slice(2);
const OUT = path.resolve(OUTD);
(async () => {
  const browser = await chromium.launch();
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 1, isMobile: w < 600, hasTouch: w < 600 });
    const page = await ctx.newPage();
    for (const pair of LIST.split(',')) {
      const [name, p] = pair.split('=');
      await page.goto(BASE + p, { waitUntil: 'load' });
      await page.waitForTimeout(500);
      const m = await page.evaluate(() => {
        const r = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return `${Math.round(b.width)}x${Math.round(b.height)}@${Math.round(b.top)}`; };
        const h1 = document.querySelector('h1');
        const img = document.querySelector('.brw-ph__img, .kbb-th__img');
        return { media: r('.brw-ph__media') || r('.kbb-th'), panel: r('.brw-ph__panel') , id: r('.brw-ph__id'), desc: r('.brw-ph__desc'), crumb: r('.crumb, .brw-crumb'),
          h1: h1 ? `${h1.textContent.trim().slice(0, 30)} ${getComputedStyle(h1).fontSize}` : null, img: img ? `${img.currentSrc.split('/').slice(-3).join('/')}` : null, sw: document.documentElement.scrollWidth, dir: document.documentElement.dir };
      });
      console.log(`${TAG} ${name} ${w} ${JSON.stringify(m)}`);
      await page.screenshot({ path: path.join(OUT, `${TAG}-${name}-${w}.png`), clip: { x: 0, y: 0, width: w, height: w < 600 ? 700 : 620 } });
    }
    await ctx.close();
  }
  await browser.close();
})();
