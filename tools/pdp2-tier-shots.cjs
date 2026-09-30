/* THE BUNDLE PRICE BLOCK, BEFORE AND AFTER, WITH THE NUMBERS UNDER IT.
   (Lane PDP2, round 3)

   Shoots the buy column with each tier pressed, at 390 and at 1280, and writes
   the figures it read out of the page beside the picture. TAG says which build
   is being served -- `tier-before` is HEAD's pdp.js, `tier-after` is this
   lane's -- so the pair can be laid side by side.

   IT SHOOTS THE ELEMENT, NOT A CLIP RECTANGLE. Playwright measures `clip`
   against the VIEWPORT on an ordinary screenshot and against the page on a
   full-page one, and pressing a row scrolls -- so the first version of this
   script, which computed the rectangle in page coordinates, came back with a
   picture of the trust block twenty rows further down. `locator().screenshot()`
   scrolls the element into view and frames it, whichever it is.

   ▲ THE SITE HEADER AND THE STICKY BAR ARE MADE STATIC FOR THE SHOT. Both sit
     on top of the buy column once Playwright has scrolled it into view, and the
     second version photographed a title hidden behind the search field. The
     override names only those two, and the figures are read with textContent
     and with getComputedStyle on the price spans, neither of which it touches.
     */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const OUT = process.env.PDP_OUT || path.join(__dirname, '..', 'docs', 'lane-pdp-shots');
const TAG = process.env.PDP_TAG || 'tier';
const BASE = process.env.PDP_BASE || 'http://127.0.0.1:9950';

const CASES = [
  { key: 'bundle', slug: 'pdp-heartleaf-toner', rows: [0, 1, 2] },
  { key: 'variable', slug: 'pdp-variable-ampoule', rows: [1, 2] },
];

const read = (p) => p.evaluate(() => {
  const t = (s) => { const e = document.querySelector(s); return e ? e.textContent.replace(/\s+/g, ' ').trim() : null; };
  const vis = (s) => { const e = document.querySelector(s); return e ? getComputedStyle(e).display !== 'none' : false; };
  const row = document.querySelector('.variant.on');
  return {
    row: row ? row.querySelector('.vn').textContent.trim() : null,
    rowPrints: row ? row.querySelector('.vp').textContent.replace(/\s+/g, ' ').trim() : null,
    blockStruck: vis('#bbPrice s') ? t('#bbPrice s') : null,
    blockNow: t('#bbPrice .now'),
    blockBadge: vis('#bbPrice .off') ? t('#bbPrice .off') : null,
    stickyStruck: t('#stickyPrice s'),
    stickyNow: t('#stickyPrice .now'),
  };
});

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const measured = {};

  for (const width of [390, 1280]) {
    for (const c of CASES) {
      const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 900 } });
      await page.goto(`${BASE}/product/${c.slug}/`, { waitUntil: 'networkidle' });

      /* The site header is position:sticky and the add-to-cart bar is fixed,
         so both sit ON TOP of an element screenshot once Playwright has
         scrolled the buy column into view -- the first pass photographed a
         title hidden behind the search field. Neither is what this picture is
         about, and the figures are read from textContent, which does not care
         whether a bar is displayed. */
      await page.addStyleTag({
        content: '.site-header,.kbb-header,header{position:static !important}'
          + '.stickybar{display:none !important}',
      });

      for (const n of c.rows) {
        if (n > 0) {
          await page.click(`.variants .variant:nth-of-type(${n + 1})`);
          await page.waitForTimeout(250);
        }
        const key = `${TAG}-${c.key}-row${n}-${width}`;
        measured[key] = await read(page);

        await page.locator('.buybox').screenshot({ path: path.join(OUT, `${key}.png`) });
      }
      await page.close();
    }
  }

  await browser.close();
  const file = path.join(OUT, `MEASUREMENTS-${TAG.toUpperCase()}.json`);
  fs.writeFileSync(file, JSON.stringify(measured, null, 2) + '\n');
  console.log(JSON.stringify(measured, null, 2));
})();
