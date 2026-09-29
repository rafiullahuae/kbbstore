/*
 * The owner's brand colour, on the pages the layout never reached. (Lane BG)
 *
 *   BG_BASE=… node tools/bg-accent-shots.cjs <before|after>
 *
 * /shop/ is the control: it follows the brand colour today and in both runs.
 * The skin quiz and the review wall are the finding -- twelve uses of
 * var(--pink) and fourteen of var(--pink-deep) on the quiz alone, all of them
 * resolving to the SHIPPED pink whatever the owner had saved, because
 * StoreComposer is registered for `layouts.store` and nothing else.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8932';
const OUT = process.env.BG_OUT || path.resolve(__dirname, '..', 'docs', 'bg-shots');
const LABEL = process.argv[2] || 'after';
const PAGES = [['shop', '/shop/'], ['quiz', '/skin-quiz/'], ['reviews', '/reviews/'], ['journal', '/blog/']];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const rows = [];
  for (const [w, h] of [[390, 844], [1280, 900]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    for (const [name, url] of PAGES) {
      await page.goto(BASE + url, { waitUntil: 'networkidle' });
      await page.waitForTimeout(400);
      const m = await page.evaluate(() => ({
        pink: getComputedStyle(document.documentElement).getPropertyValue('--pink').trim(),
        pinkDeep: getComputedStyle(document.documentElement).getPropertyValue('--pink-deep').trim(),
        accentBlock: !!document.getElementById('kbb-brand-accent'),
      }));
      await page.screenshot({ path: `${OUT}/accent-${LABEL}-${name}-${w}.png` });
      rows.push({ shot: `accent-${LABEL}-${name}-${w}`, ...m });
      console.log(JSON.stringify(rows[rows.length - 1]));
    }
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/accent-${LABEL}.json`, JSON.stringify(rows, null, 2));
  await browser.close();
})();
