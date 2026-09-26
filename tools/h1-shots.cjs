/*
 * Lane H1 — the header, cropped, at the widths the report quotes.
 *
 *   node tools/h1-shots.cjs <base> <tag> <outdir>
 *
 * Writes <tag>-<page>-<width>.png for the top 260px of each page, which is the
 * header and the first line of content under it — enough to see whether the
 * logo lines up with the page's own heading, which is the whole of the owner's
 * "matched the width".
 *
 * deviceScaleFactor 1, not 2: these are committed to the repository beside the
 * report, and the claim they support is alignment and row count rather than
 * glyph rendering.
 *
 * ▲ THESE IMAGES ARE NOT A REGRESSION PIN AND MUST NOT BECOME ONE. Two runs of
 * the SAME build produce different bytes at 390 and 768 — the home page's hero
 * carousel advances and its images arrive lazily — so a byte comparison of them
 * reports a change that did not happen. tools/h1-header-styles.cjs is the
 * deterministic instrument; this one is for looking at.
 */
const { chromium } = require('playwright');

const PAGES = [['/shop/', 'shop'], ['/', 'home'], ['/ar/shop/', 'ar-shop']];
const WIDTHS = [390, 768, 1024, 1180, 1280, 1366, 1440, 1680, 1920];

(async () => {
  const [base, tag, dir] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  for (const [path, name] of PAGES) {
    for (const w of WIDTHS) {
      const page = await browser.newPage({ viewport: { width: w, height: 760 }, deviceScaleFactor: 1 });
      await page.goto(base + path, { waitUntil: 'networkidle' });
      await page.waitForTimeout(500);
      await page.screenshot({ path: `${dir}/${tag}-${name}-${w}.png`, clip: { x: 0, y: 0, width: w, height: 260 } });
      await page.close();
    }
  }

  await browser.close();
})();
