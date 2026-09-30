/*
 * Lane GAL — the pictures and the numbers.
 *
 *   "also i can not see the product gallery thumnails, add some demo thumnails
 *    so i can see in action."
 *
 * Three things, at 390 and at 1280:
 *
 *   1. The product page with the strip present.
 *   2. A THUMBNAIL REALLY CLICKED, and the main frame's src before and after —
 *      "clicking works" is otherwise satisfied by markup that does nothing.
 *   3. The geometry 2.60.332 fixed: on desktop the strip and the photograph
 *      share one left edge; on a phone both bleed to the viewport. Read here,
 *      out of the browser, and never by a script the shop serves — CLAUDE.md
 *      rule 4 forbids JavaScript that measures layout IN THE PRODUCT.
 *      Measuring the product from outside is how the claim gets checked.
 *
 * Usage:  GAL_BASE=http://127.0.0.1:8961 node tools/gal-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.GAL_BASE || 'http://127.0.0.1:8961';
const OUT = process.env.GAL_OUT || path.join(__dirname, '..', 'docs', 'lane-gal-shots');
const EXE = process.env.GAL_CHROME || '/opt/pw-browsers/chromium';
const SLUG = process.env.GAL_SLUG || 'heartleaf-77-soothing-toner';

fs.mkdirSync(OUT, { recursive: true });

const px = (v) => Math.round(v * 100) / 100;

/* Every number this lane claims. */
const GEOMETRY = () => {
  const r = (el) => {
    if (!el) return null;
    const b = el.getBoundingClientRect();
    return {
      x: Math.round(b.x * 100) / 100,
      y: Math.round(b.y * 100) / 100,
      w: Math.round(b.width * 100) / 100,
      h: Math.round(b.height * 100) / 100,
    };
  };

  const strip = document.getElementById('gthumbs');
  const main = document.getElementById('gmain');
  const thumbs = [...document.querySelectorAll('.gthumb')];

  return {
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    gmain: r(main),
    gmainImg: r(document.getElementById('gmainImg')),
    gthumbs: r(strip),
    thumbCount: thumbs.length,
    active: thumbs.findIndex((t) => t.classList.contains('on')),
    thumbBoxes: thumbs.map(r),
    thumbSrcs: thumbs.map((t) => t.querySelector('img')?.getAttribute('src') || null),
    thumbData: thumbs.map((t) => t.dataset.image || null),
    mainSrc: document.getElementById('gmainImg')?.getAttribute('src') || null,
    caption: document.getElementById('gcap')?.textContent?.trim() || null,
    // The natural size of what the browser really downloaded, so a broken URL
    // cannot be photographed as a picture.
    mainNatural: (() => {
      const i = document.getElementById('gmainImg');
      return i ? { w: i.naturalWidth, h: i.naturalHeight } : null;
    })(),
    thumbNatural: thumbs.map((t) => {
      const i = t.querySelector('img');
      return i ? { w: i.naturalWidth, h: i.naturalHeight } : null;
    }),
  };
};

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};

  for (const [tag, width] of [['390', 390], ['1280', 1280]]) {
    const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 900 } });
    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });

    // Everything below the gallery is irrelevant to this lane; scroll the
    // strip into view so a lazy thumbnail is really decoded before measuring.
    await page.locator('#gthumbs').scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);

    const before = await page.evaluate(GEOMETRY);
    await page.locator('#gmain').scrollIntoViewIfNeeded();
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(OUT, `gallery-${tag}.png`) });

    // THE CLICK. The fourth thumbnail ("On skin"), which is neither the one
    // already showing nor the one beside it.
    await page.locator('.gthumb[data-i="3"]').click();
    await page.waitForTimeout(500);
    const after = await page.evaluate(GEOMETRY);
    await page.screenshot({ path: path.join(OUT, `gallery-clicked-${tag}.png`) });

    // And a close crop of the strip alone, at both widths, so the five shots
    // can be told apart on the page rather than only in a contact sheet.
    await page.locator('.gallery').screenshot({ path: path.join(OUT, `strip-${tag}.png`) });

    report[tag] = {
      before,
      after,
      changed: before.mainSrc !== after.mainSrc,
      activeMoved: `${before.active} -> ${after.active}`,
      // The 2.60.332 alignment: one left edge on desktop, both bled on a phone.
      leftEdges: { gmain: before.gmain.x, gthumbs: before.gthumbs.x, firstThumb: before.thumbBoxes[0].x },
      alignment: px(before.gthumbs.x - before.gmain.x),
    };

    await page.close();
  }

  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
})();
