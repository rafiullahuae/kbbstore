/* Lane QK7: where the switch sits. Appearance -> Checkout page -> Delivery labels
   -> "Free-delivery bar on the cart and checkout pages"; the Cart page screen's
   Summary preview naming it; Store -> Modules' corrected row. 390 and 1280.
   BASE=http://127.0.0.1:PORT OUT=docs/lane-qk7-shots node tools/qk7-admin-shots.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const w of [390, 1280]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, userAgent: UA })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

    await page.evaluate(() => window.go('checkoutpage'));
    await page.waitForTimeout(1500);
    await page.click('[data-chp-tab="delivery"]');
    await page.waitForTimeout(600);
    const row = page.locator('text=Free-delivery bar on the cart and checkout pages').first();
    await row.scrollIntoViewIfNeeded();
    await page.waitForTimeout(300);
    out.push({ w, screen: 'checkoutpage/delivery', found: await row.count(), sw: await page.evaluate(() => document.documentElement.scrollWidth) });
    await page.screenshot({ path: `${OUT}/admin-checkout-delivery-${w}.png` });

    await page.evaluate(() => window.go('cartpage'));
    await page.waitForTimeout(1500);
    const tab = page.locator('text=Summary & trust').first();
    if (await tab.count()) { await tab.click(); await page.waitForTimeout(600); }
    const note = page.locator('text=Free-delivery bar: off').first();
    if (await note.count()) await note.scrollIntoViewIfNeeded();
    out.push({ w, screen: 'cartpage/summary preview', found: await note.count() });
    await page.screenshot({ path: `${OUT}/admin-cartpage-summary-${w}.png` });

    await page.evaluate(() => window.go('modules'));
    await page.waitForTimeout(1500);
    const mod = page.locator('text=Free-shipping progress bar').first();
    if (await mod.count()) await mod.scrollIntoViewIfNeeded();
    out.push({ w, screen: 'modules row', found: await mod.count(), errors });
    await page.screenshot({ path: `${OUT}/admin-modules-${w}.png` });
  }
  console.log(JSON.stringify(out));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
