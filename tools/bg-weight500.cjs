/*
 * WHAT WEIGHT 500 COSTS, WHAT ITS ABSENCE LOOKED LIKE, AND WHETHER POPPINS IS
 * RENDERING AT ALL.                                                  (Lane BG)
 *
 *   BG_BASE=http://127.0.0.1:PORT node tools/bg-weight500.cjs
 *
 * Writes docs/bg-shots/state-<BG_TAG>.json and one screenshot per page per
 * width. BG_TAG defaults to `after`.
 *
 * ── THIS FILE REPLACES ONE THAT MEASURED THE WRONG THING ───────────────────
 *
 * The first version of this harness set its ruler in
 *
 *     font-family: Poppins, system-ui, sans-serif
 *
 * and reported 543.28px at weight 400 and 556.77px at 600 -- IDENTICALLY on all
 * eight pages, including four that link fonts.googleapis.com and therefore have
 * no Poppins at all in this container. Identical numbers on pages with and
 * without the family is the tell, and it was in the output for anyone who read
 * the columns instead of the conclusion: what it measured was the SYSTEM font
 * throughout, which happens to have two weights, so 300/400/500 agreed and
 * 600/700/800 agreed. Those numbers reached a docblock and a commit message
 * before anyone noticed.
 *
 * TWO FIXES, and both are the point of this file:
 *
 *   1. THE RULER IS SET IN `Poppins` ALONE, and a SECOND ruler is set in a
 *      family that cannot exist. Equal widths mean Poppins never rendered.
 *      There is no arrangement of one ruler that can tell you that.
 *
 *   2. EVERY WEIGHT IS EXPLICITLY LOADED FIRST. `document.fonts.ready` only
 *      covers the faces the page's own layout needed. These faces are
 *      `display:swap` and 500 is deliberately not preloaded, so a probe for a
 *      weight nothing on the page uses measures the FALLBACK and not the face.
 *      That race made /cart/ and /reviews/ read as outliers in the first
 *      harness's output and sent this lane looking for a cascade bug that was
 *      not there.
 *
 * WHAT IT ESTABLISHED, for the record:
 *
 *   - There is synthetic bold; there is no synthetic medium. With 400 and 600
 *     present and no 500, a target of 500 resolves to 400 OUTRIGHT: 497.20px
 *     against 497.20px, equal to the hundredth of a pixel, and 505.00px once
 *     the real face is there.
 *   - A target of 300 also resolves to 400, so the skin quiz's request for it
 *     could not have changed a pixel.
 *
 * ── AND A PAGE THAT CANNOT BE PHOTOGRAPHED ─────────────────────────────────
 *
 * /skin-quiz/ carries `animation:sheen 1.6s infinite`, so TWO CAPTURES OF THE
 * UNCHANGED PAGE differ by 38,315 pixels at 1280. Any pixel comparison of that
 * page is meaningless without capturing it twice in one state first. The
 * journal is deterministic (0 differing pixels, same md5), so its numbers mean
 * something. Note that PNG encoding here is not deterministic either: byte
 * IDENTITY proves two pictures are the same, byte DIFFERENCE proves nothing.
 */
const { chromium } = require('playwright');
const { probeFamily } = require('./font-probe.cjs');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8931';
const TAG = process.env.BG_TAG || 'after';
const OUT = process.env.BG_OUT || path.resolve(__dirname, '..', 'docs', 'bg-shots');

const PAGES = [
  ['home', '/'], ['shop', '/shop/'], ['product', '/product/lanebg-1/'], ['cart', '/cart/'],
  ['journal', '/blog/'], ['article', '/blog/lanebg-double-cleansing/'],
  ['reviews', '/reviews/'], ['quiz', '/skin-quiz/'],
];
const WEIGHTS = [300, 400, 500, 600, 700, 800];

/*
 * ── THE TWO RULERS AND THE EXPLICIT LOAD NOW LIVE IN tools/font-probe.cjs ──
 *                                                                  (Lane BG)
 * They were written inline here, which was fine while this was the only honest
 * font instrument in the repository and wrong the moment it was not:
 * tools/perf-fontcheck.cjs had the same job and a different, blind answer to
 * it. A mechanic that exists to stop a whole CLASS of mistake cannot live in
 * the one script that already knows about the mistake.
 *
 * What is left here is the part that is genuinely this script's own: which
 * pages to walk, the element census, and the page-level numbers the round
 * needed.
 */

/** The per-page work that is NOT about fonts: census, background, geometry. */
const PAGE_FACTS = (weights) => {
  /* How many elements actually ASK for each weight, and how many are visible. */
  const census = (target) => {
    let total = 0; let visible = 0;
    for (const el of document.querySelectorAll('body *')) {
      if (getComputedStyle(el).fontWeight !== String(target)) continue;
      if (!(el.textContent || '').trim()) continue;
      total++;
      const r = el.getBoundingClientRect();
      if (r.width > 0 && r.height > 0) visible++;
    }
    return { total, visible };
  };

  const bs = getComputedStyle(document.body);
  const out = { census: {} };

  for (const w of weights) out.census[w] = census(w);

  out.bg = {
    color: bs.backgroundColor,
    layers: bs.backgroundImage === 'none' ? 0 : bs.backgroundImage.split(/,(?![^(]*\))/).length,
    attachment: bs.backgroundAttachment,
  };
  out.scrollWidth = document.documentElement.scrollWidth;
  out.bodyHeight = Math.round(document.body.getBoundingClientRect().height);
  out.googleLinks = [...document.querySelectorAll('link[href*="googleapis"],link[href*="gstatic"]')]
    .map((l) => l.rel + ' ' + l.href.slice(0, 72));

  return out;
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const rows = [];

  for (const [name, url] of PAGES) {
    const row = { page: name, url };

    for (const [width, key] of [[1280, 'd'], [390, 'm']]) {
      /* A FRESH CONTEXT PER CAPTURE. One context reused across navigations
         shares a font cache, which is one more way a face can look present on
         a page that never asked for it. */
      const ctx = await browser.newContext({
        viewport: { width, height: width === 390 ? 844 : 900 },
        deviceScaleFactor: 1,
      });
      const page = await ctx.newPage();
      await page.goto(BASE + url, { waitUntil: 'load' });
      await page.evaluate(() => document.fonts.ready);

      const probe = await probeFamily(page, 'Poppins', WEIGHTS);
      const facts = await page.evaluate(PAGE_FACTS, WEIGHTS);

      row[key] = {
        poppins: probe.widths,
        control: probe.control,
        rendered: probe.rendered,
        faces: probe.faces,
        ...facts,
      };

      await page.screenshot({ path: `${OUT}/${TAG}-${name}-${width}.png` });
      await ctx.close();
    }

    rows.push(row);
    const d = row.d;
    console.log(`${name.padEnd(9)} bg=${d.bg.color.padEnd(19)} layers=${d.bg.layers}`
      + `  google=${d.googleLinks.length}  sw=${d.scrollWidth}/${row.m.scrollWidth}`
      + `  w500 ruler=${d.poppins[500]} ctrl=${d.control[500]}`
      + `  elements@500=${d.census[500].total} (${d.census[500].visible} visible)`
      + `  @300=${d.census[300].total}`);
  }

  fs.writeFileSync(`${OUT}/state-${TAG}.json`, JSON.stringify(rows, null, 2));
  console.log(`\nwrote ${OUT}/state-${TAG}.json`);
  await browser.close();
})();
