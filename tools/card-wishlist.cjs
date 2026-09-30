/*
 * LANE CARD — measure /my-wishlist WITH PRODUCTS ON IT.
 *
 * Round two recorded the wishlist as "none signed out" and moved on. That is
 * the false green this round is about: the page renders `wl-empty` for a guest
 * with no cookie, tools/card-measure.cjs prints "no product grid on this page",
 * and a surface that draws the shared card on a real shopper's visit was never
 * looked at.
 *
 * THE HEARTS ARE CLICKED RATHER THAN THE COOKIE FORGED. `kbb_wishlist` is NOT
 * in bootstrap/app.php's `encryptCookies(except:)` list, so a cookie written
 * from outside the app decrypts to nothing and the page renders empty anyway --
 * silently, which is the worst way for a harness to be wrong. Clicking the
 * heart runs the shop's own POST /wishlist/toggle and leaves the cookie the
 * shop itself would leave.
 *
 *   KBB_BASE=http://127.0.0.1:8930 node tools/card-wishlist.cjs [widths]
 */
const { chromium } = require('playwright');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8930';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const WIDTHS = (process.argv.slice(2).length ? process.argv.slice(2) : ['320', '390', '1280']).map(Number);
/* FIVE, so the grid is on a SECOND ROW at every width -- two columns at 320 and
   390, five at 1280. One row cannot tell "equal" from "equalised by its own
   row", which is the whole distinction this lane measures. */
const WANT = 5;

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();

  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });

  const hearts = await page.locator('.kbb-tile .heart').all();

  if (hearts.length < WANT) {
    console.log(`only ${hearts.length} hearts on /shop/ — is the wishlist module on?`);
    await browser.close();
    process.exit(1);
  }

  for (let i = 0; i < WANT; i++) {
    await hearts[i].click();
    await page.waitForTimeout(220);
  }

  await page.waitForTimeout(600);
  await page.close();

  for (const w of WIDTHS) {
    const p = await ctx.newPage();

    await p.setViewportSize({ width: w, height: 1000 });

    const res = await p.goto(BASE + '/my-wishlist', { waitUntil: 'networkidle' });

    const m = await p.evaluate(() => {
      const px = (n) => Math.round(n * 100) / 100;
      const tiles = [...document.querySelectorAll('.kbb-tile')].filter(
        (t) => t.getBoundingClientRect().height > 0
      );

      return {
        tiles: tiles.length,
        heights: [...new Set(tiles.map((t) => px(t.getBoundingClientRect().height)))].sort(
          (a, b) => a - b
        ),
        brand: tiles.filter((t) => t.querySelector('.kbb-card-brand')).length,
        cat: tiles.filter((t) => t.querySelector('.kbb-card-cat')).length,
        rate: tiles.filter((t) => t.querySelector('.kbb-card-rate')).length,
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      };
    });

    console.log(
      `/my-wishlist @${w}: HTTP ${res.status()}  tiles ${m.tiles}  ` +
        `heights ${m.heights.join(', ')}` +
        (m.tiles === 0
          ? '   ◀ NO TILES — the height claim here would be vacuous'
          : m.heights.length === 1
            ? '   ✓ one height'
            : '   ◀ NOT EQUAL') +
        `  brand rows ${m.brand}  eyebrow rows ${m.cat}  rating rows ${m.rate}` +
        `  scrollWidth ${m.scrollWidth}/${m.clientWidth}`
    );

    await p.screenshot({
      path: `${__dirname}/../docs/card-shots/card-wishlist-full-${w}.png`,
      fullPage: true,
    });
    await p.close();
  }

  await browser.close();
})();
