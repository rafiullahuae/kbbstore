/*
 * Lane PW: the worker changes nothing about buying. The same shopper journey
 * (add from the listing, open the bag, open checkout) with service workers
 * BLOCKED and then ALLOWED, compared. Against the wired preview.
 *   PW_BASE=http://127.0.0.1:8731 node tools/pwa-checkout-same.cjs
 */
const { chromium } = require('playwright');
const BASE = process.env.PW_BASE || 'http://127.0.0.1:8731';
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const sw of ['block', 'allow']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, userAgent: UA, isMobile: true, hasTouch: true, serviceWorkers: sw });
    const p = await ctx.newPage();
    await p.goto(BASE + '/', { waitUntil: 'load' });
    if (sw === 'allow') await p.waitForFunction(() => navigator.serviceWorker.controller, null, { timeout: 15000 });
    await p.goto(BASE + '/shop/', { waitUntil: 'load' });
    const add = p.waitForResponse((r) => r.url().includes('/api/cart/add'));
    await p.click('a[data-kbb-add]');
    const addRes = await add;
    const cart = await p.goto(BASE + '/cart/', { waitUntil: 'load' });
    const items = await p.evaluate(() => +((document.body.textContent.match(/\((\d+) items?\)/) || [])[1] || 0));
    const co = await p.goto(BASE + '/checkout/', { waitUntil: 'load' });
    out[sw] = {
      controlled: await p.evaluate(() => !!navigator.serviceWorker.controller),
      add: { status: addRes.status(), viaWorker: addRes.fromServiceWorker() },
      cart: { status: cart.status(), viaWorker: cart.fromServiceWorker(), items },
      checkout: { status: co.status(), viaWorker: co.fromServiceWorker(), form: await p.evaluate(() => !!document.querySelector('#kbbCheckoutForm')) },
      cachedPages: await p.evaluate(async () => { const n = []; for (const k of await caches.keys()) for (const r of await (await caches.open(k)).keys()) { const u = new URL(r.url).pathname; if (!/\.(js|css|woff2|png|jpe?g|webp|svg)$/.test(u)) n.push(k + ' ' + u); } return n; }),
    };
    if (sw === 'allow') await p.screenshot({ path: __dirname + '/../docs/pwa-shots/checkout-with-worker-390.png' });
    await ctx.close();
  }
  await b.close();
  console.log(JSON.stringify(out, null, 1));
})();
