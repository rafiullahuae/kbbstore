/* Lane CJ (2.60.494): a shopper's journey on the preview shop, then what Cart
   Tracking and the owner app show for it. Also counts requests: a visitor with
   no basket must send no /api/cart-trail at all.
   BASE=http://127.0.0.1:PORT OA=/<owner-app-path>/ PID=<in-stock product id> node tools/cj-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = process.env.BASE, OA = process.env.OA, PID = process.env.PID;
const OUT = path.resolve(__dirname, '../docs/lane-cj-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const UA_DESK = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};

  // 1. A visitor with NO basket: browse two pages, leave. No /api/cart-trail may be sent.
  {
    const ctx = await b.newContext({ userAgent: UA_PHONE, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const pg = await ctx.newPage();
    let trail = 0; const errors = [];
    pg.on('request', (r) => { if (r.url().includes('/api/cart-trail')) trail++; });
    pg.on('pageerror', (e) => errors.push(String(e)));
    await pg.goto(BASE + '/', { waitUntil: 'networkidle' });
    await pg.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    await pg.close({ runBeforeUnload: true }); await wait(500);
    out.noBasket = { cartTrailRequests: trail, errors };
    await ctx.close();
  }

  // 2. A shopper with a basket: product, bag, cart page, checkout, a stopped Place order, leaves.
  {
    const ctx = await b.newContext({ userAgent: UA_PHONE, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const pg = await ctx.newPage();
    let trail = 0; const errors = [];
    pg.on('request', (r) => { if (r.url().includes('/api/cart-trail')) trail++; });
    pg.on('pageerror', (e) => errors.push(String(e)));
    pg.on('console', (m) => { if (m.type() === 'error' && !/404|Failed to load resource/.test(m.text())) errors.push(m.text()); });
    await pg.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    await pg.evaluate(async (pid) => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: +pid, qty: 1 }) }); }, PID);
    const href = await pg.evaluate((pid) => { const a = [...document.querySelectorAll('a[href*="/product/"]')][0]; return a ? a.getAttribute('href') : '/'; }, PID);
    await pg.goto(BASE + href, { waitUntil: 'networkidle' }); await wait(400);
    await pg.click('header [data-kbb-open="cart"]'); await wait(900);
    await pg.click('#cart [data-kbb-close]'); await wait(500);
    await pg.goto(BASE + '/cart/', { waitUntil: 'networkidle' }); await wait(400);
    await Promise.all([pg.waitForNavigation({ waitUntil: 'networkidle' }), pg.click('a.cobtn[href*="/checkout"] >> nth=0')]);
    await wait(600);
    await pg.fill('[name=billing_email]', 'shopper@example.com'); await pg.click('h2'); await wait(200);
    const pay = pg.locator('input[name=payment_method]').first();
    if (await pay.count()) { await pay.check({ force: true }); }
    await pg.locator('[data-place]:visible').first().click(); await wait(1300);
    await pg.close({ runBeforeUnload: true }); await wait(800);
    out.withBasket = { cartTrailRequests: trail, errors };
    await ctx.close();
  }

  // 3. The console's Cart Tracking at 1280: the list, the funnel, and the cart's journey.
  {
    const ctx = await b.newContext({ userAgent: UA_DESK, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 });
    const pg = await ctx.newPage();
    await pg.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await pg.fill('input[name=email]', 'owner@example.com');
    await pg.fill('input[name=password]', 'preview-password');
    await Promise.all([pg.waitForNavigation({ waitUntil: 'networkidle' }), pg.click('button[type=submit]')]);
    await pg.evaluate(() => window.go && window.go('carttracking')); await wait(2500);
    await pg.screenshot({ path: `${OUT}/console-list-1280.png`, fullPage: false });
    const tiles = await pg.locator('.ctk-tiles').boundingBox();
    if (tiles) await pg.screenshot({ path: `${OUT}/console-funnel-1280.png`, clip: { x: 0, y: Math.max(0, tiles.y + tiles.height - 420), width: 1280, height: 440 } });
    await pg.locator('tr[data-ctk-open]').first().click(); await wait(1500);
    await pg.screenshot({ path: `${OUT}/console-journey-1280.png` });
    out.console = await pg.evaluate(() => ({ left: (document.querySelector('.ctk-left') || {}).innerText || null, journeyItems: document.querySelectorAll('#ctkPanelBody .ctk-dot.jp, #ctkPanelBody .ctk-dot.jbag, #ctkPanelBody .ctk-dot.jcart, #ctkPanelBody .ctk-dot.jco, #ctkPanelBody .ctk-dot.jleft').length }));
    await ctx.close();
  }

  // 4. The owner app at 390: Cart tracking list and the cart's journey sheet.
  {
    const ctx = await b.newContext({ userAgent: UA_PHONE, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    const pg = await ctx.newPage();
    await pg.goto(BASE + OA, { waitUntil: 'networkidle' }); await wait(800);
    if (await pg.locator('[data-enrol]').count()) {
      await pg.fill('[data-enrol] [name=email]', 'owner@example.com');
      await pg.fill('[data-enrol] [name=pin]', '482615');
      await pg.click('[data-enrol] button[type=submit]'); await wait(2500);
    }
    await pg.goto(BASE + OA + '#/carts', { waitUntil: 'networkidle' }); await wait(2500);
    await pg.screenshot({ path: `${OUT}/ownerapp-list-390.png` });
    await pg.locator('[data-cto]').first().click(); await wait(2000);
    await pg.screenshot({ path: `${OUT}/ownerapp-journey-390.png` });
    out.ownerApp = await pg.evaluate(() => ({ steps: document.querySelectorAll('.ct-jr li').length, left: (document.querySelector('.ct-left') || {}).innerText || null }));
    await ctx.close();
  }

  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
