// Lane PX: "You may also like" on the product page, at 390 and 1280.
// node tools/px-ymal-shots.cjs <tag> [port]  -> docs/lane-px-shots/<tag>-ymal-<w>.png
// (getBoundingClientRect is the harness measuring, not shop code.)
const { chromium } = require('playwright');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const TAG = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 10040}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-px-shots');

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const out = {};
  for (const w of [390, 1280]) {
    const page = await browser.newPage({ viewport: { width: w, height: w === 390 ? 844 : 900 } });
    await page.goto(BASE + '/product/pdp-heartleaf-toner/', { waitUntil: 'networkidle' });
    await page.evaluate(() => document.querySelectorAll('body *').forEach((el) => { if (getComputedStyle(el).position === 'fixed') el.style.display = 'none'; }));
    const sec = await page.$('.ymal');
    await sec.scrollIntoViewIfNeeded();
    out[`${TAG}-${w}`] = await page.evaluate(() => {
      const s = document.querySelector('.ymal');
      const cards = [...s.querySelectorAll('.ymal-track > *')];
      const vw = document.documentElement.clientWidth;
      const vis = cards.filter((c) => { const r = c.getBoundingClientRect(); return r.left < vw && r.right > 0; })
        .map((c) => { const r = c.getBoundingClientRect(); return Math.round((Math.min(r.right, vw) - Math.max(r.left, 0)) / r.width * 100) / 100; });
      return {
        style: s.getAttribute('style'),
        cardWidth: Math.round(cards[0].getBoundingClientRect().width * 10) / 10,
        cardsInView: Math.round(vis.reduce((a, b) => a + b, 0) * 100) / 100,
        arrowsShown: getComputedStyle(s.querySelector('.ymal-nav')).display !== 'none',
        sectionHeight: Math.round(s.getBoundingClientRect().height),
        scrollWidth: document.documentElement.scrollWidth,
      };
    });
    // boundingBox() is viewport-relative; a fullPage clip is page-relative.
    const b = await sec.boundingBox();
    const sy = await page.evaluate(() => scrollY);
    await page.screenshot({ path: path.join(OUT, `${TAG}-ymal-${w}.png`), fullPage: true, clip: { x: 0, y: b.y + sy, width: w, height: Math.min(b.height, 700) } });
    await page.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
