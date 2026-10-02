/*
 * Lane RF — the laptop product page's section rectangles, measured three ways
 * at 1000, 1280 and 1440 (and the phone at 390):
 *
 *   before   the page with the stylesheet as it was BEFORE this lane (the
 *            committed build is swapped in by request interception);
 *   after    the page as this lane ships it, default order;
 *   forced   the default order with the order machinery switched ON by hand
 *            (`pds-on`, the four `--pds-o-*` and `pds-ar-related` written onto
 *            .pdp-page by this harness) — proof the flex column draws the page
 *            exactly as normal flow did, so a reorder moves blocks and nothing
 *            else.
 *
 *   sh tools/rf-preview.sh 9860
 *   RF_BASE=http://127.0.0.1:9860 RF_BEFORE_CSS=storage/rf-logs/kbb-product-before.css node tools/rf-measure.cjs
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, NEVER BY A SCRIPT THE SHOP SERVES.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RF_BASE || 'http://127.0.0.1:9860';
const CHROME = process.env.RF_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BEFORE = process.env.RF_BEFORE_CSS || '';
const SLUGS = (process.env.RF_SLUGS || 'pdp-heartleaf-toner,pdp-glow-ritual-set,pdp-variable-ampoule,pdp-sold-out-serum').split(',');
const OUT = process.env.RF_OUT || path.join(__dirname, '..', 'docs', 'rf-shots', 'MEASUREMENTS-default.json');

const SEL = {
  crumb: '.pdp-page > .crumb', columns: '.pdp-page > .pdp', gallery: '.pdp-page .gallery', buybox: '.pdp-page .buybox',
  buytogether: '.pdp-page > .kbb-fbt', details: '.pdp-page > .pm-details', reviews: '.pdp-page > .sr', related: '.pdp-page > .ymal',
  footer: 'footer',
};

async function measure(page) {
  return page.evaluate((sel) => {
    const out = {};
    for (const [k, s] of Object.entries(sel)) {
      const e = document.querySelector(s);
      if (!e || getComputedStyle(e).display === 'none') { out[k] = null; continue; }
      const b = e.getBoundingClientRect();
      out[k] = [Math.round((b.left + scrollX) * 100) / 100, Math.round((b.top + scrollY) * 100) / 100, Math.round(b.width * 100) / 100, Math.round(b.height * 100) / 100];
    }
    out.scrollWidth = document.documentElement.scrollWidth;
    out.scrollHeight = document.documentElement.scrollHeight;
    return out;
  }, SEL);
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const res = {};
  let diffs = 0;

  for (const slug of SLUGS) {
    for (const w of [1000, 1280, 1440, 390]) {
      const runs = {};
      for (const mode of ['before', 'after', 'forced']) {
        if (mode === 'before' && !BEFORE) continue;
        const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1 });
        const page = await ctx.newPage();
        if (mode === 'before') {
          const css = fs.readFileSync(BEFORE, 'utf8');
          await page.route(/\/build\/assets\/kbb-product-[^/]+\.css$/, (r) => r.fulfill({ status: 200, contentType: 'text/css', body: css }));
        }
        await page.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
        if (mode === 'forced') {
          await page.evaluate(() => {
            const pg = document.querySelector('.pdp-page');
            pg.classList.add('pds-on', 'pds-ar-related');
            ['buytogether', 'details', 'reviews', 'related'].forEach((k, i) => pg.style.setProperty('--pds-o-' + k, String(i + 1)));
          });
        }
        await page.waitForTimeout(250);
        runs[mode] = await measure(page);
        if (mode === 'forced') runs.forcedDisplay = await page.evaluate(() => getComputedStyle(document.querySelector('.pdp-page')).display);
        await ctx.close();
      }
      const ref = JSON.stringify(runs.after);
      const same = { before: runs.before ? JSON.stringify(runs.before) === ref : null, forced: JSON.stringify(runs.forced) === ref };
      if (same.before === false || (w > 880 && !same.forced)) {
        diffs++;
        for (const k of Object.keys(runs.after)) {
          for (const m of ['before', 'forced']) {
            if (runs[m] && JSON.stringify(runs[m][k]) !== JSON.stringify(runs.after[k])) console.log(`DIFF ${slug} @${w} ${m} ${k}: ${JSON.stringify(runs[m][k])} vs after ${JSON.stringify(runs.after[k])}`);
          }
        }
      }
      res[`${slug}@${w}`] = { identical: same, after: runs.after, forcedDisplay: runs.forcedDisplay };
      console.log(`${slug} @${w}: before==after ${same.before}  forced==after ${same.forced} (forced display ${runs.forcedDisplay})  scrollWidth ${runs.after.scrollWidth}`);
    }
  }

  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, JSON.stringify(res, null, 1));
  await browser.close();
  console.log(diffs === 0 ? 'ALL IDENTICAL' : `${diffs} DIFFERING`);
  process.exit(diffs === 0 ? 0 : 1);
})();
