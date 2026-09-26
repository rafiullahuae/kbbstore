/*
 * Lane H1 — every computed style the header renders, at a list of widths.
 *
 *   node tools/h1-header-styles.cjs <base> <widths,comma> > out.json
 *
 * This is the instrument for "the dedicated mobile header is untouched". A
 * screenshot cannot make that claim on this shop: the home page's hero
 * carousel and its lazily-loaded images mean two runs of the SAME build
 * produce different PNG bytes at 390 and 768, which was measured before this
 * file existed — after-390 and a second after-390 differ, with nothing
 * changed between them. Computed styles do not move, so a diff of them is a
 * claim that can actually be checked.
 *
 * It reads the whole computed style of every element the header is made of,
 * plus its rendered rect, and writes them as JSON to be diffed between two
 * builds.
 */
const { chromium } = require('playwright');

const SELECTORS = [
  'header', 'header > .wrap', 'header .hin', 'header .logo', 'header .sbox',
  'header .sbox .search-in', 'header .sbox input', 'header .hact', 'header .ib',
  'header .ib svg', 'header .kbbmi', 'header .hinfo', 'header .hi', 'header .hi .ic',
  'header .hi .tx b', 'header .trend', 'header .trend a', 'header .mbar',
  'header .mbar .wrap', 'header .navitem', 'header .navlink', 'header .navlink .ind',
  '.mmenu', '.tabbar', '.mscrim',
];

(async () => {
  const base = process.argv[2];
  const widths = (process.argv[3] || '390').split(',').map(Number);
  const pages = (process.argv[4] || '/shop/,/,/ar/shop/').split(',');
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};

  for (const path of pages) {
    for (const w of widths) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 } });
      await page.goto(base + path, { waitUntil: 'networkidle' });
      await page.waitForTimeout(500);
      out[path + '@' + w] = await page.evaluate((sels) => {
        const res = {};
        for (const sel of sels) {
          const el = document.querySelector(sel);
          if (!el) { res[sel] = null; continue; }
          const cs = getComputedStyle(el);
          const bag = {};
          for (let i = 0; i < cs.length; i++) {
            const p = cs.item(i);
            // Custom properties are the mechanism under test, not the result,
            // and a few of them legitimately differ between the two builds
            // while rendering the same. The RENDERED properties below are the
            // claim.
            if (p.startsWith('--')) continue;
            bag[p] = cs.getPropertyValue(p);
          }
          const r = el.getBoundingClientRect();
          bag['@rect'] = [r.x, r.y, r.width, r.height].map(n => Math.round(n * 100) / 100).join(',');
          res[sel] = bag;
        }
        res['@doc'] = document.documentElement.clientWidth + '/' + document.documentElement.scrollWidth;
        return res;
      }, SELECTORS);
      await page.close();
    }
  }

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
