/*
 * Lane CT (contrast) — the four interactions the colour change must not move:
 * add to cart, the mini cart, the menu, search. Same script on both previews.
 *
 *   node tools/ctc-interact.cjs <base>
 */
const { chromium } = require('playwright');

const BASE = process.argv[2];
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140 Safari/537.36';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });

    const badge = () => page.evaluate(() => (window.KBB && window.KBB.cartCount !== undefined)
      ? String((document.querySelector('[data-kbb-cart] i, [data-kbb-cart] b') || {}).textContent || window.KBB.cartCount).trim() : null);
    const before = await badge();
    await page.locator('a.kbb-card-cart.ajax_add_to_cart').first().click();
    await page.waitForTimeout(1500);
    const drawerOpen = await page.evaluate(() => [...document.querySelectorAll('.kc, #kbbCart, .drawer, [class*="cart-panel"], .kcp')]
      .some((d) => { const s = getComputedStyle(d); return s.visibility !== 'hidden' && s.display !== 'none' && +s.opacity > 0.5 && d.getBoundingClientRect().width > 200 && d.getBoundingClientRect().right > 0 && d.getBoundingClientRect().left < innerWidth; }));
    const after = await badge();
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);

    let menu = null;
    if (width === 390) {
      const burger = page.locator('.kbbmi, button[aria-label*="enu"]').first();
      if (await burger.count()) {
        await burger.click(); await page.waitForTimeout(700);
        menu = await page.evaluate(() => document.body.classList.contains('menu-open') && !!document.querySelector('nav.mmenu.on'));
        await page.keyboard.press('Escape'); await page.waitForTimeout(400);
        await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
      }
    }

    const input = page.locator('input[name="s"]:visible').first();
    await input.click();
    await input.fill('serum');
    await page.waitForTimeout(1500);
    const search = await page.evaluate(() => !!document.querySelector('.sugg.on')
      && [...document.querySelectorAll('.sugg.on a')].filter((a) => /serum/i.test(a.textContent)).length);

    out[width] = { cartBadge: [before, after], miniCartOpened: drawerOpen, menuOpened: menu, searchResults: search, jsErrors: errors.length };
    await ctx.close();
  }
  console.log(JSON.stringify(out));
  await browser.close();
})();
