/*
 * The shop-page cart drawer's two footer buttons, photographed and measured.
 *
 *   sh tools/cp-preview.sh 8971
 *   node tools/od-drawer-shots.cjs docs/od-drawer-shots 1280 900
 *   node tools/od-drawer-shots.cjs docs/od-drawer-shots 390 844
 *
 * WHY THE SHOP PAGE AND NOT THE CART PANEL PREVIEW. The drawer is on every
 * page, but only /shop/ loads kbb-shop.css -- and that sheet is where
 * `.cobtn{height:50px}` and `.btn-ghost{height:44px}` live. The 6px the owner
 * saw exists exactly where that sheet does.
 *
 * Every number is read with getComputedStyle IN THE BROWSER, so it is a
 * measurement of the finished page rather than of the source. CLAUDE.md forbids
 * the SHOP from measuring its own layout in JavaScript; a camera may.
 */
const { chromium } = require('playwright');

const OUT = process.argv[2] || 'docs/od-drawer-shots';
const W = parseInt(process.argv[3] || '1280', 10);
const H = parseInt(process.argv[4] || '900', 10);
const BASE = process.env.KBB_OD_URL || 'http://127.0.0.1:8971';

(async () => {
  /*
   * executablePath, because the project's pinned Playwright asks for a
   * headless-shell build number this container does not carry and answers
   * "run npx playwright install" -- which the environment forbids. The
   * Chromium that IS here is the one tools/px-progress-shots.mjs names.
   */
  const browser = await chromium.launch({
    executablePath: process.env.KBB_OD_CHROME
      || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: W, height: H } });
  const page = await ctx.newPage();

  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });

  // A real add to cart, through the card's own control.
  const add = page.locator('[data-kbb-add]').first();
  await add.waitFor({ state: 'visible', timeout: 15000 });
  await add.click();
  await page.waitForTimeout(1200);

  // Open the drawer the way a shopper does.
  const opener = page.locator('[data-kbb-cart-open], #cartOpen, .kc-open, [href="#kcCart"]').first();
  if (await opener.count()) { await opener.click().catch(() => {}); }
  await page.waitForTimeout(800);

  // Whatever the opener is called, make the panel visible for the measurement.
  await page.evaluate(() => {
    const p = document.querySelector('#kcCart');
    if (p) { p.classList.add('on', 'open'); p.removeAttribute('hidden'); }
    document.body.classList.add('kc-open');
  });
  await page.waitForTimeout(600);

  const m = await page.evaluate(() => {
    const cart = document.querySelector('#kcCart .btn-ghost');
    const co = document.querySelector('#kcCart .cobtn');
    const read = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return {
        text: (el.textContent || '').trim(),
        height: Math.round(r.height * 100) / 100,
        width: Math.round(r.width * 100) / 100,
        cssHeight: cs.height,
        cssMinHeight: cs.minHeight,
        padding: cs.paddingTop + ' / ' + cs.paddingBottom,
        fontSize: cs.fontSize,
      };
    };
    return {
      cart: read(cart),
      checkout: read(co),
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    };
  });

  console.log(JSON.stringify({ viewport: W + 'x' + H, ...m }, null, 2));

  const panel = page.locator('#kcCart');
  if (await panel.count()) {
    await panel.screenshot({ path: `${OUT}/drawer-${W}.png` }).catch(async () => {
      await page.screenshot({ path: `${OUT}/drawer-${W}.png` });
    });
  } else {
    await page.screenshot({ path: `${OUT}/drawer-${W}.png` });
  }

  await browser.close();
})();
