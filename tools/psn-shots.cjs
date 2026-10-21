/*
 * Lane PS — product review snippets. For each page type at 390 and 1280:
 * a screenshot, document.documentElement.scrollWidth, console errors, the
 * JSON-LD blocks (saved as JSON), and the page HTML with the JSON-LD blocks
 * cut out (saved, so before and after can be diffed byte for byte).
 *
 *   BASE=http://127.0.0.1:10760 PHASE=before node tools/psn-shots.cjs
 *   BASE=http://127.0.0.1:10760 PHASE=after  node tools/psn-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10760';
const PHASE = process.env.PHASE || 'after';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ps';
const HTML = process.env.HTML || 'storage/psn-logs/html';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const PAGES = {
  'product-reviewed': '/product/ps-heartleaf-toner/',
  'product-none': '/product/ps-snail-essence/',
  'product-sale': '/product/ps-birch-sun/',
  'product-soldout': '/product/ps-mugwort-mask/',
  'product-ar': '/ar/product/ps-heartleaf-toner/',
  category: '/collections/toners/',
  brand: '/brands/anua/',
  shop: '/shop/',
};
const LD = /<script type="application\/ld\+json">([\s\S]*?)<\/script>/g;

(async () => {
  fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync(HTML, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const summary = {};

  for (const [name, path] of Object.entries(PAGES)) {
    for (const width of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: width < 700 ? IPHONE : undefined });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      const res = await page.goto(BASE + path, { waitUntil: 'networkidle' });
      await page.waitForTimeout(300);
      const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
        h: document.documentElement.scrollHeight }));
      const html = await res.text();
      const blocks = [...html.matchAll(LD)].map((x) => JSON.parse(x[1]));
      // Tokens that change on every request are not page content.
      const visible = html.replace(LD, '<!--ld-->')
        .replace(/name="_token" value="[^"]*"/g, 'name="_token"')
        .replace(/<meta name="csrf-token" content="[^"]*">/g, '')
        .replace(/nonce="[^"]*"/g, '');
      if (width === 1280) {
        fs.writeFileSync(`${OUT}/jsonld-${PHASE}-${name}.json`, JSON.stringify(blocks, null, 2) + '\n');
      }
      fs.writeFileSync(`${HTML}/${PHASE}-${name}-${width}.html`, visible);
      if (name.startsWith('product-reviewed') || name === 'category') {
        await page.screenshot({ path: `${OUT}/${PHASE}-${name}-${width}.png`, fullPage: true });
      }
      summary[`${name}@${width}`] = { status: res.status(), scrollWidth: m.sw, clientWidth: m.cw, height: m.h, errors, ldBlocks: blocks.length };
      await ctx.close();
    }
  }

  fs.writeFileSync(`${OUT}/measure-${PHASE}.json`, JSON.stringify(summary, null, 2) + '\n');
  process.stdout.write(JSON.stringify(summary, null, 1) + '\n');
  await browser.close();
})();
