/*
 * The control shot: a product page on a shop where the owner has written NO
 * tabs at all. (Lane PT)
 *
 * This is the one that matters most, and it is a separate run because it needs
 * the `product_tabs` table EMPTY -- which is the state the package applies in.
 * tools/pt-shoot.sh empties it, runs this, and puts the rows back.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PT_BASE || 'http://127.0.0.1:8977';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.PT_OUT || `${APP}/docs/lane-pt-shots`;
const SLUG = process.env.PT_SLUG || 'lanept-heartleaf-toner';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });

  for (const w of [390, 1280]) {
    await page.setViewportSize({ width: w, height: 1900 });
    await page.waitForTimeout(500);

    const m = await page.evaluate(() => ({
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,
      tabTitles: [...document.querySelectorAll('.dtab')].map((n) => n.textContent.trim()),
      accordionTitles: [...document.querySelectorAll('.macc-h')]
        .map((n) => n.textContent.replace(/[+−]\s*$/, '').trim()),
      detailsHtml: document.querySelector('#details')?.outerHTML.length ?? null,
    }));

    await page.screenshot({ path: `${OUT}/00-nothing-written-${w}.png`, fullPage: true });
    console.log(JSON.stringify({ shot: `00-nothing-written-${w}`, ...m }));
  }

  await browser.close();
})();
