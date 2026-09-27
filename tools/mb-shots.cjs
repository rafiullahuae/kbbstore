/*
 * Lane MB — photograph the Media Library with imported files in it, at 390 and
 * 1280, and measure what rule 2 asks for.
 *
 * Expects the preview server up on 127.0.0.1:8962 and tools/mb-seed-preview.php
 * already run. See that file's header for the whole sequence.
 *
 *   NODE_PATH=/home/user/kbbstore/node_modules node tools/mb-shots.cjs docs/mb-media-shots
 *
 * WHAT IS MEASURED AND WHY EACH NUMBER IS HERE. `scrollWidth` against
 * `clientWidth` on the document is the horizontal-overflow test rule 2 names by
 * name; the tile geometry is read so the 390 grid can be shown not to be one
 * column of stretched images; and the COUNT of imported tiles is the claim this
 * whole lane makes — a screenshot of a grid proves nothing if the tiles in it
 * are the shop's own uploads.
 */
const { chromium } = require('playwright');

const BASE = 'http://127.0.0.1:8962';
const WIDTHS = [[390, 844], [1280, 900]];
const out = process.argv[2] || 'docs/mb-media-shots';

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    args: ['--no-sandbox'],
  });

  const report = {};

  for (const [w, h] of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type=submit], input[type=submit]'),
    ]);

    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('media'));
    // The grid fetches its rows and then its thumbnails; both have to land
    // before a picture of it means anything.
    await page.waitForTimeout(3500);

    report[`media-${w}`] = await page.evaluate(() => {
      const tiles = [...document.querySelectorAll('img')].filter(
        (i) => (i.currentSrc || i.src || '').includes('/uploads/')
      );
      const imported = tiles.filter((i) => (i.currentSrc || i.src || '').includes('/wp-content/uploads/'));
      const first = tiles[0]?.getBoundingClientRect();

      return {
        viewport: document.documentElement.clientWidth,
        pageScrollWidth: document.documentElement.scrollWidth,
        horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        title: document.querySelector('#ptitle')?.textContent?.trim() ?? null,
        crumb: document.querySelector('#crumb')?.textContent?.trim() ?? null,
        tilesDrawn: tiles.length,
        importedTilesDrawn: imported.length,
        brokenThumbnails: tiles.filter((i) => i.complete && i.naturalWidth === 0).length,
        firstTile: first ? { w: Math.round(first.width), h: Math.round(first.height) } : null,
        // The actual src of one imported tile, so the picture can be checked
        // against the path the library stored rather than taken on trust.
        anImportedSrc: imported[0]?.getAttribute('src') ?? null,
      };
    });

    await page.screenshot({ path: `${out}/media-library-${w}.png`, fullPage: false });

    /*
     * AND THE GRID ITSELF, scrolled to. The header shot proves the screen loads;
     * the grid shot is the one that proves the TILES are the import's, with the
     * filename, the real dimensions read off the file, and the usage badge that
     * the delete guard reads. An imported photograph a product points at must
     * say who uses it — reading "unused" there is what invites an operator to
     * delete a picture that is on a live product page.
     */
    const grid = await page.$('img[src*="/wp-content/uploads/"]');

    if (grid) {
      await grid.scrollIntoViewIfNeeded();
      await page.waitForTimeout(700);
      await page.screenshot({ path: `${out}/media-grid-${w}.png`, fullPage: false });
    }

    /*
     * AND THE IMPORTED PHOTOGRAPHS A PRODUCT ACTUALLY POINTS AT. The grid is
     * newest-first, so the files the sideloader fetched and re-pointed sit at
     * the end of it; searching by name brings them up. This is the shot that
     * shows an imported row carrying "Used by" rather than "Not used yet" —
     * i.e. that MediaUsage's index, and therefore the delete guard that reads
     * it, recognises a wp-content path. Without that the owner's whole imported
     * library reads as safe to delete.
     */
    await page.fill('input[placeholder="filename or alt text"]', 'ginseng');
    await page.waitForTimeout(2200);
    await page.screenshot({ path: `${out}/media-used-${w}.png`, fullPage: false });

    report[`media-${w}`].searchGinseng = await page.evaluate(() => {
      const text = document.querySelector('#content')?.textContent ?? '';

      /*
       * THE BADGE READS "product" / "brand" / "category", NOT "Used by" — the
       * owner-type is what the tile prints, and "Not used yet" is the only
       * literal for the other side. Counted off the badge element rather than
       * off a guessed phrase, because the first version of this counted a
       * string the screen never renders and reported 1 where the truth is 3.
       */
      const badges = [...document.querySelectorAll('#content .mtile .mbadge, #content [class*="badge"]')]
        .map((b) => (b.textContent || '').trim().toLowerCase());

      return {
        tiles: [...document.querySelectorAll('img')].filter(
          (i) => (i.currentSrc || i.src || '').includes('/wp-content/uploads/')
        ).length,
        usedBadges: badges.filter((b) => b !== '' && b !== 'unused').length,
        unusedBadges: badges.filter((b) => b === 'unused').length,
        notUsedYetText: (text.match(/Not used yet/g) ?? []).length,
      };
    });

    await page.fill('input[placeholder="filename or alt text"]', '');
    await page.waitForTimeout(1600);

    await ctx.close();
  }

  await browser.close();

  require('fs').writeFileSync(`${out}/mb-measurements.json`, JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
