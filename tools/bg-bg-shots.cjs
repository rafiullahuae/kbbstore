/*
 * /shop/ and a product page, before and after the white/pink split was
 * settled.                                                          (Lane BG)
 *
 *   BG_BASE=… node tools/bg-bg-shots.cjs <before|after>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8931';
const OUT = process.env.BG_OUT || path.resolve(__dirname, '..', 'docs', 'bg-shots');
const LABEL = process.argv[2] || 'after';
const PAGES = [['shop', '/shop/'], ['product', '/product/lanebg-1/'], ['home', '/'], ['cart', '/cart/']];
(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const [w, h] of [[390, 844], [1280, 900]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    for (const [name, url] of PAGES) {
      await page.goto(BASE + url, { waitUntil: 'networkidle' });
      await page.waitForTimeout(400);
      await page.screenshot({ path: `${OUT}/split-${LABEL}-${name}-${w}.png` });
      console.log(`split-${LABEL}-${name}-${w}`);
    }
    await ctx.close();
  }
  await browser.close();
})();
