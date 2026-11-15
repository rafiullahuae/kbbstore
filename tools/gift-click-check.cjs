/* Clicks on the Free gifts box are counted (owner, 15 Nov: "make sure that we have tracking for all gifts and
   view all buttons"): View all, Hide and All gifts, on the cart page and in the cart panel, then the beacon
   is flushed by leaving the page and gift_clicks is read back from the preview database.
   GBX_X='{"mode":"live","pop":"off","crit":"3"}' FG_SEED=gbx-feature-seed.php sh tools/fg-preview.sh <port>
   BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> node tools/gift-click-check.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, PID = Number(process.env.PID);
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  const beacons = [];
  await ctx.addInitScript(() => { const o = navigator.sendBeacon.bind(navigator); navigator.sendBeacon = (u, b) => { try { const e = b && b.get ? b.get('e') : String(b); const a = JSON.parse(localStorage.getItem('__b') || '[]'); a.push(u + ' ' + e); localStorage.setItem('__b', JSON.stringify(a)); } catch (x) {} return o(u, b); }; });
  // The shop flushes its taps when the tab is hidden: show another tab, wait for the beacon, come back.
  const hide = async () => { const o = await ctx.newPage(); await o.bringToFront(); await page.waitForTimeout(1500); await o.close(); await page.bringToFront(); };
  page.on('request', (r) => { if (r.url().includes('/api/cart-trail')) beacons.push(r.postData() || ''); });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.evaluate(async (id) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) }), PID);

  // cart page: View all, Hide, All gifts (then close the popup)
  await page.goto(BASE + '/cart', { waitUntil: 'networkidle' });
  const f = page.locator('.fgb').filter({ visible: true }).first();
  await f.locator('.fgb-th .fgb-tg').first().click();
  await f.locator('.fgb-ls .fgb-tg').first().click();
  await f.locator('.gbx-cr').filter({ visible: true }).first().click();
  await page.keyboard.press('Escape');
  await hide();

  // cart panel: View all and All gifts
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.locator('[data-kbb-cart]').first().click();
  const p = page.locator('#cart .fgb').first();
  await p.waitFor({ state: 'visible' });
  await p.locator('.fgb-th .fgb-tg').first().click();
  await p.locator('.fgb-ls .fgb-tg').first().click();
  await p.locator('.gbx-cr').filter({ visible: true }).first().click();
  await page.keyboard.press('Escape');
  await hide();

  await page.goto(BASE + '/about-us/', { waitUntil: 'networkidle' }).catch(() => {});
  await page.waitForTimeout(800);
  const sent = await page.evaluate(() => JSON.parse(localStorage.getItem('__b') || '[]'));
  console.log(JSON.stringify({ beacons, sent, csrf: await page.evaluate(() => !!(window.KBB && window.KBB.csrf)) }, null, 1));
  await browser.close();
})();
