/*
 * Lane SORT screenshots: the shop grid sorted and filtered by price, with the
 * rendered ORDER and the rendered PRICES read out of the same page.
 *
 * Run once per width, against one of the two previews:
 *
 *   tools/sort-preview.sh 8993 after
 *   tools/sort-preview.sh 8994 before
 *   SORT_BASE=http://127.0.0.1:8993 SORT_LABEL=after \
 *     SORT_CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
 *     node tools/sort-shots.cjs 390
 *
 * EVERYTHING IT REPORTS IS MEASURED IN THE PAGE. The order is the DOM order of
 * the tiles, the prices are the strings inside them, and the two are printed
 * side by side -- which is the whole claim of this lane, so reading them
 * separately would prove nothing. document.documentElement.scrollWidth against
 * clientWidth is there because a grid is the easiest thing in this shop to give
 * horizontal scroll to, and this lane must not have given it any.
 *
 * Measuring HERE is not what CLAUDE.md rule 4 forbids. The rule is about
 * JavaScript THE SHOP SHIPS; this is the instrument that checks the shipped CSS
 * and the shipped ORDER BY, and an instrument that does not measure is a claim.
 */
const fs = require('fs');
const { chromium } = require('playwright');

const BASE = process.env.SORT_BASE || 'http://127.0.0.1:8993';
const LABEL = process.env.SORT_LABEL || 'after';
const OUT = process.env.SORT_OUT || (__dirname + '/../docs/lane-sort-shots');

const PAGES = [
  ['plow', '/shop/?orderby=plow', 'Price, low to high'],
  ['phigh', '/shop/?orderby=phigh', 'Price, high to low'],
  ['band-54-150', '/shop/?price=54-150', 'Price band AED 54 - 150'],
  ['band-150-300', '/shop/?price=150-300', 'Price band AED 150 - 300'],
  ['onsale', '/shop/?sale=1', 'On sale'],
];

(async () => {
  const width = +process.argv[2];
  const browser = await chromium.launch({ executablePath: process.env.SORT_CHROME });
  const ctx = await browser.newContext({ viewport: { width, height: 1000 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();

  fs.mkdirSync(OUT, { recursive: true });

  const report = { label: LABEL, width, base: BASE, pages: {} };

  for (const [slug, url, title] of PAGES) {
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    await page.waitForTimeout(250);

    const m = await page.evaluate(() => {
      const doc = document.documentElement;
      const tiles = [...document.querySelectorAll('.kbb-tile')];
      const text = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : null);

      return {
        viewport: doc.clientWidth,
        scrollWidth: doc.scrollWidth,
        horizontalScroll: doc.scrollWidth > doc.clientWidth,
        tiles: tiles.length,
        rows: tiles.map((t) => {
          const box = t.getBoundingClientRect();
          const price = t.querySelector('.kbb-card-price');
          const cs = price ? getComputedStyle(price) : null;

          return {
            name: text(t.querySelector('.kbb-card-nm')),
            price: text(price),
            // `.kbb-card-reg` is the struck-through compare-at the card draws
            // beside the charged price when Product::isOnSale() is true. It is
            // read here because "the tile says -11%" and "the tile strikes out
            // AED 162" are the same claim the On sale facet has to agree with.
            was: text(t.querySelector('.kbb-card-reg')),
            badges: [...t.querySelectorAll('.kbb-badge')].map((b) => b.textContent.trim()),
            tile: { w: +box.width.toFixed(1), h: +box.height.toFixed(1) },
            priceFontPx: cs ? cs.fontSize : null,
          };
        }),
      };
    });

    /* The order the shopper sees, beside the numbers the shopper sees. A sort
       is only correct if these two lists agree, so they are printed as one. */
    m.printed = m.rows.map((r) => r.name + '  ' + (r.was ? r.was + ' -> ' : '') + r.price
      + (r.badges.length ? '  [' + r.badges.join(' ') + ']' : ''));

    report.pages[slug] = m;

    const file = `${OUT}/${slug}-${LABEL}-${width}.png`;
    await page.screenshot({ path: file, fullPage: true });
    console.log(`${file}  ${title}`);
    console.log('  viewport ' + m.viewport + '  scrollWidth ' + m.scrollWidth
      + '  horizontalScroll ' + m.horizontalScroll + '  tiles ' + m.tiles);
    for (const line of m.printed) console.log('    ' + line);
  }

  fs.writeFileSync(`${OUT}/measured-${LABEL}-${width}.json`, JSON.stringify(report, null, 2) + '\n');
  await browser.close();
})();
