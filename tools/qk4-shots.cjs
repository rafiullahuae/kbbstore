/*
 * Lane QK4 screenshots: the bottom of home, category and product pages, the
 * last section and the top of the footer, at 390 and 1280.
 *   QK4_BASE=http://127.0.0.1:10842 QK4_OUT=dir QK4_TAG=before node tools/qk4-shots.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.QK4_BASE, OUT = process.env.QK4_OUT, TAG = process.env.QK4_TAG || 'shot';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const PAGES = { home: '/', category: '/product-category/cleansing-oils/', product: '/product/1025-dokdo-toner/' };
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const page = await (await b.newContext({ viewport: { width: w, height: 900 }, userAgent: UA, deviceScaleFactor: 1 })).newPage();
    for (const [name, u] of Object.entries(PAGES)) {
      await page.goto(BASE + u, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.querySelectorAll('img[loading=lazy]').forEach(i => i.loading = 'eager'));
      await page.waitForTimeout(600);
      const top = await page.evaluate(() => document.querySelector('footer').getBoundingClientRect().top + scrollY);
      const h = w < 600 ? 640 : 560;
      await page.screenshot({ path: path.join(OUT, `${TAG}-${name}-${w}.png`), fullPage: true, clip: { x: 0, y: Math.max(0, top - h + 180), width: w, height: h } });
    }
  }
  await b.close();
})();
