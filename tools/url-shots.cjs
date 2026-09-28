/*
 * Lane URL screenshots: the address scheme, photographed at its new addresses,
 * plus one shot proving an OLD address lands on the right page.
 *
 * WHAT MAKES THIS MORE THAN A PICTURE OF A PAGE. Every entry carries the URL it
 * was ASKED for and the URL the browser ENDED on, plus the number of redirects
 * in between, read off Playwright's own request chain. A screenshot of
 * /collections/skincare/toners/ proves the page renders; `hops: 1` on
 * /product-category/toners/ is the thing the lane actually promises, and it is
 * the thing a chained redirect would fail.
 *
 * The measured numbers per page: viewport width, document.documentElement
 * .scrollWidth, the horizontal overflow between them (the first thing a layout
 * change breaks), the <h1> text and its font size, and the canonical href — a
 * canonical still naming the old address is the one defect that is worse than
 * not moving at all, so it is measured rather than trusted.
 *
 * Usage: node tools/url-shots.cjs <width> <base-url> <out-dir>
 */
const { chromium } = require('playwright');
const fs = require('fs');

const WIDTH = +process.argv[2];
const BASE = process.argv[3];
const OUT = process.argv[4];
const CHROME = process.env.URL_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const PAGES = [
  ['collection', '/collections/skincare/toners/'],
  ['brand-directory', '/brands/'],
  ['brand-page', '/brands/round-lab/'],
  ['blog-index', '/blog/'],
  ['article', '/blog/heartleaf-extract-transforming-k-beauty-skincare/'],
  // The old address, photographed where it lands. Nothing else in this list is
  // a redirect, and this one is the whole second half of the lane.
  ['old-category-lands', '/product-category/toners/'],
];

async function measure(page, name, asked, hops) {
  return await page.evaluate(([n, a, h]) => {
    const h1 = document.querySelector('h1');
    const canonical = document.querySelector('link[rel="canonical"]');

    return {
      page: n,
      asked: a,
      landed: location.pathname,
      hops: h,
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      h1: h1 ? h1.textContent.trim().slice(0, 70) : null,
      h1FontSize: h1 ? getComputedStyle(h1).fontSize : null,
      canonical: canonical ? canonical.getAttribute('href') : null,
      // Any link on the page still pointing at an address the shop redirects
      // away from. Zero is the answer; anything else is an internal link the
      // scheme missed, which costs every visitor who clicks it a hop.
      retiredLinks: [...document.querySelectorAll('a[href]')]
        .map((el) => el.getAttribute('href'))
        .filter((href) => /\/(product-category|korean-skincare-brands|skincare-guide)\//.test(href)),
    };
  }, [name, asked, hops]);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: CHROME });
  const context = await browser.newContext({
    viewport: { width: WIDTH, height: WIDTH < 600 ? 844 : 900 },
    deviceScaleFactor: 2,
  });
  const page = await context.newPage();
  const out = [];

  for (const [name, path] of PAGES) {
    // The redirect chain, from the browser rather than from a claim. Every
    // response before the final one is a hop the visitor paid for.
    const response = await page.goto(BASE + path, { waitUntil: 'networkidle' });

    let hops = 0;
    for (let r = response.request().redirectedFrom(); r; r = r.redirectedFrom()) hops++;

    await page.waitForTimeout(250);
    out.push(await measure(page, name, path, hops));

    await page.screenshot({
      path: `${OUT}/${name}-${WIDTH}.png`,
      fullPage: WIDTH < 600,
    });
  }

  fs.writeFileSync(`${OUT}/measured-${WIDTH}.json`, JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 2));

  await browser.close();
})();
