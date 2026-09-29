/*
 * What `body` actually computes to, on every storefront page.        (Lane BG)
 *
 *   BG_BASE=http://127.0.0.1:8931 node tools/bg-body-measure.cjs [label]
 *
 * kbb.css declares FOUR `body` rules and kbb-shop.css and kbb-product.css each
 * declare a fifth and sixth. Which declarations reach a shopper is not a
 * question to answer by reading them in order -- two of those sheets are pushed
 * onto @stack('styles') AFTER kbb.css, so the cascade depends on which page is
 * being served. This reads the answer off the rendered page instead, which is
 * how the split between a pink home page and a white /shop was found in the
 * first place.
 *
 * Run it before a change and after, with a label, and diff the two JSON files.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8931';
const OUT = process.env.BG_OUT || path.resolve(__dirname, '..', 'docs', 'bg-shots');
const LABEL = process.argv[2] || 'body';

const PAGES = [
  ['home', '/'],
  ['shop', '/shop/'],
  ['product', '/product/lanebg-1/'],
  ['cart', '/cart/'],
  ['checkout', '/checkout/'],
  ['journal', '/blog/'],
  ['article', '/blog/lanebg-double-cleansing/'],
  ['reviews', '/reviews/'],
  ['quiz', '/skin-quiz/'],
  ['wishlist', '/wishlist/'],
];

const PROPS = ['background-color', 'background-image', 'background-attachment', 'margin-top',
  'color', 'font-family', 'font-size', 'line-height', '-webkit-font-smoothing', 'padding-bottom'];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const rows = [];

  for (const [name, url] of PAGES) {
    const res = await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const status = res ? res.status() : 0;
    const m = await page.evaluate((props) => {
      const cs = getComputedStyle(document.body);
      const out = {};
      for (const p of props) out[p] = cs.getPropertyValue(p);
      // the first 42 characters of the image list is enough to tell "none"
      // from the botanical data URI without carrying 40 KB per row
      out['background-image'] = out['background-image'].slice(0, 42);
      return out;
    }, PROPS);
    rows.push({ page: name, url, status, ...m });
    console.log(JSON.stringify(rows[rows.length - 1]));
  }

  fs.writeFileSync(`${OUT}/body-${LABEL}.json`, JSON.stringify(rows, null, 2));
  await browser.close();
})();
