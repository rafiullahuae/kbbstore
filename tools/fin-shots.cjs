/*
 * Lane FIN — the evidence for the two screens this lane changes.
 *
 *   sh tools/ad-preview.sh 8991 <BEFORE-REF>   # the tree before this lane
 *   sh tools/ad-preview.sh 8992                # the working tree
 *   BASE=http://127.0.0.1:8991 TAG=before node tools/fin-shots.cjs
 *   BASE=http://127.0.0.1:8992 TAG=after  node tools/fin-shots.cjs
 *
 * Two screens, at 390 and at 1280:
 *
 *   newsletter  Appearance → Newsletter → Messages. Two boxes that answer no
 *               shopper, one of which said it did.
 *   ecommerce   Store → Ecommerce → General → Catalogue layout. A "Grid
 *               columns" box that reached no storefront pixel.
 *
 * tools/ad-preview.sh is reused verbatim rather than copied, because its own
 * header solves the one problem a before/after pair of ADMIN screens has: a
 * preview built from an older commit would otherwise serve that commit's Blade
 * views and the WORKING TREE's PHP classes, and come out identical.
 *
 * The numbers are printed as well as drawn. A picture of a settings panel does
 * not say how many controls are on it.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8992';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-fin-shots';
const TAG = process.env.TAG || 'after';

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
}

const page1 = (page) => page.evaluate(() => ({
  scrollWidth: document.documentElement.scrollWidth,
  clientWidth: document.documentElement.clientWidth,
}));

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: 1400 } });
    const page = await ctx.newPage();
    await signIn(page);

    /* ------------------------------ Appearance → Newsletter → Messages */
    await page.evaluate(() => window.go('newsletter'));
    await page.waitForFunction(
      () => document.querySelectorAll('#content [data-nl]').length > 3,
      null, { timeout: 15000 },
    ).catch(() => {});
    await page.waitForTimeout(500);

    // The Messages tab. Clicked by its visible text, so this keeps working if
    // the tab keys are renamed.
    await page.evaluate(() => {
      const tab = document.querySelector('#content [data-nltab="messages"]');
      if (tab) tab.click();
    });
    await page.waitForTimeout(700);

    const nl = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('#content .mmrow')];
      return rows.map((r) => {
        const b = r.querySelector('.mmlbl b');
        const s = r.querySelector('.mmlbl span');
        return {
          label: b ? b.textContent.trim() : '',
          help: s ? s.textContent.trim() : '',
        };
      }).filter((r) => r.label);
    });

    await page.screenshot({ path: `${OUT}/newsletter-messages-${TAG}-${width}.png`, fullPage: true });
    console.log(`[${TAG} ${width}] newsletter Messages rows:`);
    for (const r of nl) console.log(`    ${JSON.stringify(r.label)}  help=${JSON.stringify(r.help)}`);
    console.log(`[${TAG} ${width}] newsletter page`, JSON.stringify(await page1(page)));

    /* ------------------- Store → Ecommerce → General → Catalogue layout */
    await page.evaluate(() => window.go('ecommerce'));
    await page.waitForFunction(
      () => document.querySelectorAll('#content input,#content select').length > 3,
      null, { timeout: 15000 },
    ).catch(() => {});
    await page.waitForTimeout(700);

    const ec = await page.evaluate(() => {
      const text = document.querySelector('#content').innerText;
      const labels = [...document.querySelectorAll('#content .mmlbl b')].map((n) => n.textContent.trim());
      return {
        controlCount: document.querySelectorAll('#content input,#content select,#content textarea').length,
        labels,
        mentionsGridColumns: /Grid columns/i.test(text),
        catalogueLayoutBlurb: (text.match(/Catalogue layout\s*\n?\s*([^\n]*)/) || [])[1] || '',
      };
    });

    await page.screenshot({ path: `${OUT}/ecommerce-general-${TAG}-${width}.png`, fullPage: true });
    console.log(`[${TAG} ${width}] ecommerce controls=${ec.controlCount} gridColumnsOnScreen=${ec.mentionsGridColumns}`);
    console.log(`[${TAG} ${width}] ecommerce Catalogue layout blurb: ${JSON.stringify(ec.catalogueLayoutBlurb)}`);
    console.log(`[${TAG} ${width}] ecommerce labels: ${JSON.stringify(ec.labels)}`);
    console.log(`[${TAG} ${width}] ecommerce page`, JSON.stringify(await page1(page)));

    await ctx.close();
  }

  await browser.close();
})();
