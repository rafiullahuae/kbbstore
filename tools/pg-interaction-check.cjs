/* Lane PG — the tile's three interactions, in a real browser. Harness only. */
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  const base = 'http://127.0.0.1:8341';

  // 1. Clicking the tile's white space follows the product link.
  await p.goto(base + '/shop/', { waitUntil: 'networkidle' });
  const box = await p.locator('#grid > .kbb-tile').first().boundingBox();
  // Empty space inside the text block, below the name and clear of every
  // control: the stretched link is the only thing that can answer here.
  await Promise.all([
    p.waitForURL(/\/product\//, { timeout: 5000 }).catch(() => {}),
    p.mouse.click(box.x + box.width - 8, box.y + box.height * 0.72),
  ]);
  await p.waitForTimeout(500);
  console.log('white-space click ->', new URL(p.url()).pathname);

  // 2. Quick view opens from the tile.
  await p.goto(base + '/shop/', { waitUntil: 'networkidle' });
  await p.locator('#grid > .kbb-tile').first().hover();
  await p.locator('#grid > .kbb-tile').first().locator('.qv-btn').click();
  await p.waitForTimeout(900);
  console.log('quick view visible ->', await p.locator('.qv-back').isVisible());

  // 3. Add to cart opens the drawer rather than navigating.
  await p.goto(base + '/shop/', { waitUntil: 'networkidle' });
  const addable = p.locator('#grid > .kbb-tile a[data-kbb-add]').first();
  await addable.click();
  await p.waitForTimeout(1200);
  console.log('after add, path  ->', new URL(p.url()).pathname);
  console.log('cart drawer open ->', await p.locator('#cartDrawer, .drawer.on, [data-kbb-drawer="cart"]').first().isVisible().catch(() => 'n/a'));

  await b.close();
})();
