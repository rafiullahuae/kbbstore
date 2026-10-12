/* Lane APL (previews only): real phone screenshots of the live shop for the
   site-app landing page. Read-only: it browses and adds one item to a guest
   bag in its own throwaway browser; it never signs in and never orders.
   node tools/app-landing-shots.cjs  ->  docs/app-landing/shots/*.png */
const pw = require('playwright');
const fs = require('fs'), path = require('path');
const OUT = path.resolve(__dirname, '../docs/app-landing/shots');
fs.mkdirSync(OUT, { recursive: true });
const BASE = process.env.BASE || 'https://kbeautybliss.com';
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';
const PRODUCT = '/product/beauty-of-joseon-glow-serum-propolis-niacinamide/';

async function clean(pg) {
  await pg.evaluate(() => {
    // The floating chat button and anything fixed at the bottom would sit on every shot; an app screenshot leaves them out.
    document.querySelectorAll('body *').forEach((n) => {
      const cs = getComputedStyle(n);
      if (cs.position === 'fixed' && /wa|whats|chat|support/i.test((n.className || '') + ' ' + (n.id || ''))) n.style.display = 'none';
    });
  });
}

(async () => {
  const b = await pw.chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ userAgent: UA, viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
  const pg = await ctx.newPage();
  const shot = async (name) => { await clean(pg); await pg.waitForTimeout(500); await pg.screenshot({ path: `${OUT}/${name}.png` }); console.log('ok', name); };

  await pg.goto(BASE + '/', { waitUntil: 'networkidle', timeout: 90000 }); await shot('home');
  await pg.evaluate(() => window.scrollTo(0, 900)); await pg.waitForTimeout(600); await shot('home-scroll');
  await pg.goto(BASE + '/best-sellers/', { waitUntil: 'networkidle', timeout: 90000 }); await shot('bestsellers');
  await pg.goto(BASE + '/brands/', { waitUntil: 'networkidle', timeout: 90000 }); await shot('brands');
  await pg.goto(BASE + PRODUCT, { waitUntil: 'networkidle', timeout: 90000 }); await shot('product');
  await pg.evaluate(() => window.scrollTo(0, 620)); await pg.waitForTimeout(500); await shot('product-buy');

  // Search: type into the header field and photograph the instant panel.
  await pg.goto(BASE + '/', { waitUntil: 'networkidle', timeout: 90000 });
  const s = pg.locator('header input[type="search"]').first();
  await s.click(); await s.type('sun', { delay: 80 }); await pg.waitForTimeout(1800); await shot('search');

  // Bag: add the serum to a guest bag through the shop's own endpoint, then open the cart panel.
  await pg.goto(BASE + PRODUCT, { waitUntil: 'networkidle', timeout: 90000 });
  const pid = await pg.evaluate(() => { const f = document.querySelector('form.kbb-cart-form[data-product_id]') || document.querySelector('[data-product_id]'); return f ? +f.getAttribute('data-product_id') : 0; });
  await pg.evaluate(async (id) => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, qty: 1 }) }); }, pid);
  await pg.reload({ waitUntil: 'networkidle' });
  const cartBtn = pg.locator('header a[href*="/cart"], header [data-drawer="cart"], header .ib-cart, header button[aria-label*="ag"]').first();
  try { await cartBtn.click({ timeout: 5000 }); await pg.waitForTimeout(1200); await shot('cart-panel'); } catch (e) { console.log('cart panel skipped', String(e).slice(0, 80)); }
  await pg.goto(BASE + '/cart/', { waitUntil: 'networkidle', timeout: 90000 }); await shot('cart');
  await pg.goto(BASE + '/checkout/', { waitUntil: 'networkidle', timeout: 90000 }); await shot('checkout');
  await pg.evaluate(() => window.scrollTo(0, 1100)); await pg.waitForTimeout(500); await shot('checkout-pay');
  await pg.goto(BASE + '/track-my-order/', { waitUntil: 'networkidle', timeout: 90000 }); await shot('track');
  await pg.goto(BASE + '/my-account/', { waitUntil: 'networkidle', timeout: 90000 }); await shot('account');
  await b.close();
})();
