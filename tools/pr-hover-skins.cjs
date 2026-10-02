/*
 * Lane PR — does a TAP still move a card on a phone, on any card design?
 *
 *   PR_BASE=http://127.0.0.1:8961 node tools/pr-hover-skins.cjs <skin> [path]
 *
 * tools/pr-hover-skins.sh walks every skin in App\Support\GridSkins::ALL, sets
 * it with tools/pr-set.sh, and calls this once per skin. Each run puts the
 * pointer on the first card with a photograph at 390 (touch: `hover:none`) and
 * at 1280 (a mouse), and compares every property any hover rule in the two skin
 * sheets touches — on the card, its text column, its photo frame and the
 * frame's ::after, the photograph and the button — before and after.
 *
 * The claim it checks: at 390 NOTHING moves (except the `actions` skin's
 * button, which is that skin's only way to reach Add to cart and is left on
 * purpose); at 1280 the hover is what it always was.
 *
 * NOTHING HERE RUNS ON THE SHOP.
 */
const { chromium } = require('playwright');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.PR_BASE || 'http://127.0.0.1:8961';
const SKIN = process.argv[2] || '?';
const PATHNAME = process.argv[3] || '/collections/skincare-sets/';

const PROPS = ['transform', 'boxShadow', 'backgroundColor', 'backgroundImage', 'borderColor', 'borderRadius', 'opacity', 'color'];

async function probe(browser, w) {
  const phone = w < 700;
  const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, isMobile: phone, hasTouch: phone });
  const page = await ctx.newPage();
  await page.goto(BASE + PATHNAME, { waitUntil: 'networkidle' });
  const skinOnPage = await page.locator('.kbb-pgrid').first().getAttribute('data-skin');
  const tile = page.locator('.kbb-tile:has(img)').first();

  const read = () => tile.evaluate((el, props) => {
    const pick = (node, pseudo) => {
      if (!node) return null;
      const s = getComputedStyle(node, pseudo || null);
      return Object.fromEntries(props.map((p) => [p, s[p]]));
    };
    const thumb = el.querySelector('.kbb-card-thumb');

    return {
      card: pick(el),
      cb: pick(el.querySelector('.cb')),
      thumb: pick(thumb),
      thumbAfter: pick(thumb, '::after'),
      img: pick(el.querySelector('.kbb-card-thumb img')),
      cart: pick(el.querySelector('.kbb-card-cart')),
    };
  }, PROPS);

  await page.mouse.move(1, 1);
  await page.waitForTimeout(250);
  const before = await read();
  await tile.hover({ position: { x: 30, y: 30 } });
  await page.waitForTimeout(800);
  const after = await read();
  await ctx.close();

  const moved = [];

  for (const part of Object.keys(before)) {
    if (!before[part]) continue;

    for (const p of PROPS) {
      if (before[part][p] !== after[part][p]) moved.push(`${part}.${p}: ${before[part][p]} -> ${after[part][p]}`);
    }
  }

  return { skinOnPage, moved };
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const phone = await probe(browser, 390);
  const desk = await probe(browser, 1280);
  await browser.close();

  const expectedOnPhone = SKIN === 'actions' ? phone.moved.filter((m) => !m.startsWith('cart.transform')) : phone.moved;

  console.log(JSON.stringify({
    skin: SKIN,
    rendered: phone.skinOnPage,
    phoneMoves: phone.moved,
    desktopMoves: desk.moved.length,
    verdict: phone.skinOnPage !== SKIN ? 'WRONG SKIN ON PAGE' : (expectedOnPhone.length === 0 ? 'ok' : 'PHONE HOVER STILL MOVES'),
  }));
})();
