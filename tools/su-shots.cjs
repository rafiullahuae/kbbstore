/*
 * The scheme gate, photographed. Two widths, and the numbers that matter read
 * off the live DOM rather than asserted.
 *
 * The claim this has to support is not "an attack was blocked" — it is BOTH
 * halves: the hostile row is neutralised AND the honest rows next to it are
 * byte-identical. So every capture carries an ordinary sibling in frame.
 *
 * `injected.png` is counted on the NETWORK, not in the markup: the whole point
 * of an offsite <img src> is that the browser fetches it before any script
 * runs, so a page that "does not contain" the address but still requests it
 * would pass a source scan and fail the shopper.
 */
const { chromium } = require('playwright');
const fs = require('fs');

/*
 * The pinned binary, not whatever Playwright's package.json currently wants.
 * The bundled revision moves with an npm update and the container carries
 * chromium-1194; launching without this fails with "Executable doesn't exist"
 * and an instruction to run `npx playwright install`, which this environment
 * deliberately does not do (PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1).
 */
const CHROME = process.env.SU_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.SU_BASE || 'http://127.0.0.1:8993';
const OUT = process.env.SU_OUT || 'docs/su-shots';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

  for (const [tag, width, height] of [['1280', 1280, 900], ['390', 390, 844]]) {
    const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();

    const requested = [];
    page.on('request', (r) => requested.push(r.url()));

    const dialogs = [];
    page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });

    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.screenshot({ path: `${OUT}/home-${tag}.png`, fullPage: false });

    const header = await page.evaluate(() => {
      const links = [...document.querySelectorAll('.navlink')].map((a) => ({
        label: a.textContent.trim().split('\n')[0].trim(),
        href: a.getAttribute('href'),
        style: a.getAttribute('style'),
      }));
      const social = [...document.querySelectorAll('.fsoc a')].map((a) => ({
        label: a.getAttribute('aria-label'),
        href: a.getAttribute('href'),
      }));
      const ld = [...document.querySelectorAll('script[type="application/ld+json"]')]
        .map((s) => s.textContent)
        .filter((t) => t.includes('sameAs'));
      let sameAs = null;
      try { sameAs = ld.length ? JSON.parse(ld[0]).sameAs : null; } catch (e) { sameAs = 'unparseable'; }
      return { links, social, sameAs, scrollWidth: document.documentElement.scrollWidth };
    });

    // The mobile drawer holds its own copy of every menu row, from the same
    // tree — so at 390 it is the thing to photograph, not the bar.
    if (tag === '390') {
      const opener = await page.$('[data-mm-open], .mm-open, #mmOpen, .burger');
      if (opener) {
        await opener.click();
        await page.waitForTimeout(400);
        await page.screenshot({ path: `${OUT}/drawer-390.png` });
      }
      header.drawer = await page.evaluate(() =>
        [...document.querySelectorAll('.mm-it, .mm-si')].map((a) => ({
          label: a.textContent.trim().split('\n')[0].trim(),
          href: a.getAttribute('href'),
        }))
      );
    }

    await page.goto(BASE + '/product/scheme-gate-serum/', { waitUntil: 'networkidle' });
    const strip = await page.$('.sr-grid');
    if (strip) {
      await strip.scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
    }
    await page.screenshot({ path: `${OUT}/reviews-${tag}.png` });

    header.reviewPhotos = await page.evaluate(() =>
      [...document.querySelectorAll('.sr-grid img')].map((i) => i.getAttribute('src'))
    );
    header.injectedRequests = requested.filter((u) => u.includes('injected.png')).length;
    header.dialogs = dialogs.length;

    report[tag] = header;
    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
})();
