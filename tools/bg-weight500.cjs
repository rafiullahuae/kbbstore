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

/* Runs in the page. Two rulers, every weight loaded first. */
const PROBE = async (weights) => {
  for (const w of weights) {
    try { await document.fonts.load(w + ' 40px Poppins'); } catch (e) { /* no such face */ }
  }
  await document.fonts.ready;

  const mk = (family) => {
    const s = document.createElement('span');
    s.style.cssText = 'position:absolute;left:-9999px;top:0;white-space:pre;'
      + 'font-size:40px;font-family:' + family;
    s.textContent = 'Hydrating Serum AED 149';
    document.body.appendChild(s);
    return s;
  };

  const real = mk("'Poppins'");
  const control = mk("'KbbNoSuchFamily12345'");
  const poppins = {}; const ctrl = {}; const rendered = {};

  for (const w of weights) {
    real.style.fontWeight = String(w);
    control.style.fontWeight = String(w);
    const a = Math.round(real.getBoundingClientRect().width * 100) / 100;
    const b = Math.round(control.getBoundingClientRect().width * 100) / 100;
    poppins[w] = a; ctrl[w] = b; rendered[w] = a !== b;
  }

  real.remove(); control.remove();

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

  return {
    poppins,
    control: ctrl,
    rendered,
    census: { 300: census(300), 500: census(500) },
    bg: {
      color: bs.backgroundColor,
      layers: bs.backgroundImage === 'none' ? 0 : bs.backgroundImage.split(/,(?![^(]*\))/).length,
      attachment: bs.backgroundAttachment,
    },
    scrollWidth: document.documentElement.scrollWidth,
    bodyHeight: Math.round(document.body.getBoundingClientRect().height),
    googleLinks: [...document.querySelectorAll('link[href*="googleapis"],link[href*="gstatic"]')]
      .map((l) => l.rel + ' ' + l.href.slice(0, 72)),
  };
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
      row[key] = await page.evaluate(PROBE, WEIGHTS);
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
