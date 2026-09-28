/*
 * Lane SF evidence. Chromium at 390 and 1280, and the measured numbers under
 * every shot.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below — `npx playwright install` is forbidden
 * here.
 *
 * ── WHAT IS PHOTOGRAPHED ───────────────────────────────────────────────────
 *
 *   before-set        a set's page WITH the bundle strip and the contents grid
 *                     near the foot — the branch point, shot by
 *                     tools/sf-shoot.sh with this lane's two changes backed out
 *   after-set         the same page with the list in the strip's place
 *   after-set-12      the twelve-member box, where the fold does its work
 *   after-set-12-open the same with the disclosure opened
 *   after-set-rtl     the three-member box mirrored
 *   unpriced-set      a set with no price, which must not claim a saving
 *   plain-product     an ordinary product, whose strip must be untouched
 *
 * ── EVERY NUMBER UNDER EVERY SHOT IS READ FROM THE PAGE ────────────────────
 *
 * viewport, scrollWidth, whether the document overflows sideways, how many
 * member rows are drawn, how many are LINKS and how many are not, how many are
 * folded, the list's own height and its distance from the top of the buy
 * column, whether the Add to cart button is still above the fold on a phone,
 * and the footing's own words — which is what makes "must not claim a saving"
 * checkable from the log rather than by squinting at a picture.
 *
 * Those two heights are MEASURED IN THE EVIDENCE, not in the page. Nothing in
 * resources/views sizes anything from a script; this file is a camera.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SF_BASE || 'http://127.0.0.1:8994';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SF_OUT || `${APP}/docs/lane-sf-shots`;
const ONLY = process.env.SF_ONLY || '';
const TAG = process.env.SF_TAG || 'after';

const SET3 = 'lanesf-glow-starter-set';
const SET12 = 'lanesf-full-routine-set';
const UNPRICED = 'lanesf-unpriced-set';
const PLAIN = 'lanesf-plain-moisturiser';

const log = [];

async function shoot(page, name, w, h, extra) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(400);

  const m = await page.evaluate(() => {
    const box = (el) => (el ? el.getBoundingClientRect() : null);
    const list = document.querySelector('.ksl');
    const buy = document.querySelector('.buybox');
    const add = document.querySelector('.kbb-cart-form button[type=submit], .kbb-cart-form .addbtn, #addToCart');
    const foot = document.querySelector('.ksl-foot');
    const details = document.querySelector('details.ksl-more');

    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      /* THE NUMBER THAT MATTERS AT 390. scrollWidth greater than clientWidth is
         a page with a horizontal scrollbar, which is the defect every width
         measurement in this repository is really about. */
      overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      buyColumn: buy ? Math.round(box(buy).width) : null,
      rows: document.querySelectorAll('.ksl-r').length,
      rowsStanding: document.querySelectorAll('.ksl-rows .ksl-r').length,
      rowsFolded: document.querySelectorAll('details.ksl-more .ksl-r').length,
      foldOpen: details ? details.open : null,
      linked: document.querySelectorAll('a.ksl-nm').length,
      unlinked: document.querySelectorAll('span.ksl-nm').length,
      listHeight: list ? Math.round(box(list).height) : null,
      rowHeight: document.querySelector('.ksl-r') ? Math.round(box(document.querySelector('.ksl-r')).height) : null,
      // How far down the document the Add to cart button sits. On a phone this
      // is the number the fold exists to keep small.
      addToCartTop: add ? Math.round(box(add).top + window.scrollY) : null,
      footing: foot ? foot.innerText.replace(/\n/g, ' | ') : null,
      // The quantity-bundle strip, by the words it actually prints.
      bundleStrip: document.body.innerText.includes('2-pack bundle')
        || document.body.innerText.includes('3-pack bundle'),
      // Where the contents block sits relative to the buy column: INSIDE is
      // the whole point of this release.
      listInsideBuyColumn: !!(list && buy && buy.contains(list)),
    };
  });

  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });

  const row = { shot: `${name}-${w}`, ...m, ...(extra || {}) };
  log.push(row);
  console.log(JSON.stringify(row));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());

  if (ONLY === '' || ONLY === 'set') {
    await page.goto(`${BASE}/product/${SET3}/`, { waitUntil: 'networkidle' });
    for (const w of [390, 1280]) await shoot(page, `${TAG}-set`, w, 2000);

    await page.goto(`${BASE}/product/${SET12}/`, { waitUntil: 'networkidle' });
    for (const w of [390, 1280]) await shoot(page, `${TAG}-set-12`, w, 2200);

    /* AND THE DISCLOSURE OPENED, because a fold nobody can see past is a fold
       that hides the box. <details> is opened by clicking its summary — the
       browser's own behaviour, no script of ours. */
    const summary = await page.$('details.ksl-more > summary');
    if (summary) {
      await summary.click();
      await page.waitForTimeout(250);
      for (const w of [390, 1280]) await shoot(page, `${TAG}-set-12-open`, w, 2600);
    }

    /* AN UNPRICED SET. `footing` in the log is the assertion: it must read
       "Bought separately ... | Set price AED 0" and nothing about saving. */
    await page.goto(`${BASE}/product/${UNPRICED}/`, { waitUntil: 'networkidle' });
    for (const w of [390, 1280]) await shoot(page, `${TAG}-unpriced-set`, w, 1800);

    /* ARABIC. The storefront is bilingual and a list that only holds up one way
       round is not finished. */
    await page.goto(`${BASE}/ar/product/${SET3}/`, { waitUntil: 'networkidle' }).catch(() => {});
    const isRtl = await page.evaluate(() => document.documentElement.getAttribute('dir') === 'rtl');
    if (isRtl) {
      for (const w of [390, 1280]) await shoot(page, `${TAG}-set-rtl`, w, 2000, { rtl: true });
    }
  }

  if (ONLY === '' || ONLY === 'plain') {
    /* THE CONTROL. An ordinary product's page must be exactly where it was —
       strip included. This is the shot that says so. */
    await page.goto(`${BASE}/product/${PLAIN}/`, { waitUntil: 'networkidle' });
    for (const w of [390, 1280]) await shoot(page, `${TAG}-plain-product`, w, 1800);
  }

  fs.writeFileSync(`${OUT}/measurements-${TAG}${ONLY ? '-' + ONLY : ''}.json`, JSON.stringify(log, null, 2));

  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
