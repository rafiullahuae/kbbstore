/*
 * LANE CARD — the pictures, and the numbers printed beside each one.
 *
 * Every shot is taken from ONE booted preview against ONE fixture, and the
 * state under test is written with tools/card-set.php between passes, so two
 * panels differ by the setting and by nothing else. A sheet assembled from
 * separately-booted previews could differ by the fixture as well and nobody
 * could tell which.
 *
 *   KBB_BASE=http://127.0.0.1:8990 node tools/card-shots.cjs <name> <path> [widths]
 *
 * NOTHING HERE RUNS ON THE SHOP: this is Playwright against a preview. The card
 * is sized with calc() and two tests forbid the element-measuring APIs by name.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8990';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
/* DERIVED FROM THIS FILE'S OWN LOCATION, NEVER HARDCODED. tools/card-preview.sh
   carries the same note and the same reason: the screenshots are a deliverable,
   so the thing that produces them has to travel with the branch. */
const OUT = process.env.KBB_SHOTS || path.join(__dirname, '..', 'docs', 'card-shots');

const NAME = process.argv[2] || 'shot';
const PATHNAME = process.argv[3] || '/collections/skincare-sets/';
const WIDTHS = (process.argv[4] || '390,1280').split(',').map(Number);
/* A clip of the GRID only, so the frame is the thing being judged rather than
   a page of chrome with a grid somewhere in it. `full` shoots the whole page. */
const MODE = process.argv[5] || 'grid';

fs.mkdirSync(OUT, { recursive: true });

async function settle(page) {
  await page.evaluate(async () => {
    const step = Math.floor(window.innerHeight * 0.8);

    for (let y = 0; y < document.body.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 60));
    }

    window.scrollTo(0, 0);
  });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(300);
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });

  for (const w of WIDTHS) {
    const page = await browser.newPage({ viewport: { width: w, height: 1100 } });
    const res = await page.goto(BASE + PATHNAME, { waitUntil: 'networkidle' });

    if (!res || res.status() !== 200) {
      console.log(`${NAME}-${w}: HTTP ${res ? res.status() : 'none'} — NOT SHOT`);
      await page.close();
      continue;
    }

    await settle(page);

    const info = await page.evaluate((mode) => {
      const px = (n) => Math.round(n * 100) / 100;
      /* ── `sel:<selector>` SHOOTS A BLOCK THAT IS NOT A .kbb-pgrid ──────────
         Two surfaces of this shop draw a product tile outside any grid: the
         Frequently Bought Together strip on the product page (.kbb-fbt) and a
         routine step on /routines/<concern> (.kbb-tile with no grid above it).
         Without this the script clipped to `.kbb-pgrid, #grid, .rel`, found
         the RELATED RAIL on the product page and photographed that instead --
         a picture of the wrong block, captioned with the right numbers, which
         is worse than no picture. The selector is named on the command line so
         the frame is never guessed. */
      const pick = mode.startsWith('sel:') ? mode.slice(4) : null;
      const grid = pick
        ? document.querySelector(pick)
        : [...document.querySelectorAll('.kbb-pgrid, #grid, .rel')].find((g) =>
            g.querySelector('.kbb-tile')
          );

      if (!grid) {
        return {
          clip: null,
          note: pick ? `nothing matches ${pick} on this page` : 'no product grid on this page',
        };
      }

      /* A block selected by name may hold .kbb-fbt-item rather than .kbb-tile,
         and reporting "tiles 0" for a strip with four products in it is the
         same false green this round exists to remove. */
      const tiles = [
        ...grid.querySelectorAll('.kbb-tile, .kbb-fbt-item'),
        ...(grid.matches('.kbb-tile') ? [grid] : []),
      ].filter((t) => t.getBoundingClientRect().height > 0);
      const heights = [...new Set(tiles.map((t) => px(t.getBoundingClientRect().height)))];
      const b = grid.getBoundingClientRect();
      const nameBoxes = [
        ...new Set(
          tiles.map((t) => px(t.querySelector('.kbb-card-nm')?.getBoundingClientRect().height ?? 0))
        ),
      ];
      const btn = tiles[0]?.querySelector('.kbb-card-cart')?.getBoundingClientRect();

      return {
        clip:
          mode === 'full'
            ? null
            : {
                x: Math.max(0, b.x - 10),
                y: Math.max(0, b.y + window.scrollY - 10),
                width: Math.min(document.documentElement.clientWidth, b.width + 20),
                /* CAPPED, because a 24-product /shop grid at 320 is 4,000px of
                   PNG and the first two rows are the whole of what the picture
                   says. */
                height: Math.min(b.height + 20, 1500),
              },
        heights,
        nameBoxes,
        tiles: tiles.length,
        withRating: tiles.filter((t) => t.querySelector('.kbb-card-rate')).length,
        withBrand: tiles.filter((t) => t.querySelector('.kbb-card-brand')).length,
        withCat: tiles.filter((t) => t.querySelector('.kbb-card-cat')).length,
        button: btn ? `${px(btn.width)}x${px(btn.height)}` : '-',
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      };
    }, MODE);

    const file = `${OUT}/${NAME}-${w}.png`;

    await page.screenshot(
      info.clip ? { path: file, fullPage: true, clip: info.clip } : { path: file, fullPage: true }
    );

    console.log(
      `${NAME}-${w}: ${info.note ?? ''}` +
        (info.clip === null && info.note ? '' : '') +
        ` tiles ${info.tiles ?? '-'}` +
        `  heights ${(info.heights ?? []).join('/')}` +
        `  nameBox ${(info.nameBoxes ?? []).join('/')}` +
        `  button ${info.button ?? '-'}` +
        `  rating rows ${info.withRating ?? '-'}` +
        `  brand ${info.withBrand ?? '-'}` +
        `  eyebrow ${info.withCat ?? '-'}` +
        `  scrollWidth ${info.scrollWidth}/${info.clientWidth}`
    );

    await page.close();
  }

  await browser.close();
})();
