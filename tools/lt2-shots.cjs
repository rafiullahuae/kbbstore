// Lane LT2 proof pictures: translated product pages on /ar and a Shop by Skin
// collection with the newly assigned products, at 390 and 1280.
//   NODE_PATH=/home/user/kbbstore/node_modules node tools/lt2-shots.cjs http://127.0.0.1:8761
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const base = process.argv[2] || 'http://127.0.0.1:8761';
const out = path.join(__dirname, '..', 'docs', 'lt2-shots');
fs.mkdirSync(out, { recursive: true });

const pages = [
  ['pdp-arencia', '/ar/product/arencia-eraser-soothing-cream/'],
  ['pdp-celimax-noni', '/ar/product/celimax-the-real-noni-energy-repair-cream/'],
  ['pdp-abib-eye', '/ar/product/abib-retinal-eye-serum-lifting-roller/'],
  ['skin-redness', '/ar/collections/redness-sensitive-skin/'],
  ['skin-anti-aging-en', '/collections/anti-aging/'],
];

(async () => {
  let browser;
  try {
    browser = await chromium.launch();
  } catch (e) {
    browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  }
  const report = [];
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    for (const [name, url] of pages) {
      await page.goto(base + url, { waitUntil: 'networkidle' });
      const m = await page.evaluate(() => {
        const t = (s) => { const e = document.querySelector(s); return e ? e.textContent.trim().slice(0, 90) : null; };
        return {
          dir: document.documentElement.dir,
          scrollWidth: document.documentElement.scrollWidth,
          h1: t('h1'),
          short: t('.bb-desc'),
          desc: t('.dcontent'),
          cards: document.querySelectorAll('a[href*="/product/"]').length,
        };
      });
      await page.screenshot({ path: path.join(out, `${name}-${width}.png`), fullPage: width === 1280 ? false : false });
      if (name.startsWith('pdp-')) {
        // The description tab, where the long Arabic text lands.
        const d = page.locator('.dpanel, .macc').filter({ visible: true }).first();
        if (await d.count()) await d.screenshot({ path: path.join(out, `${name}-description-${width}.png`) });
      }
      report.push({ width, name, ...m });
    }
    report.push({ width, consoleErrors: errors });
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(out, 'measurements.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 1));
})();
