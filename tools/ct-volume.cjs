/*
 * Cart Tracking at volume (Lane CT): time the admin endpoints against
 * CT_SEED_CARTS=100000 sh tools/ct-preview.sh 9896, server-measured through
 * the real stack (login, session, capability check, JSON). Each URL is asked
 * three times; the median is reported, first (cold) and warm separately.
 *
 *   CT_BASE=http://127.0.0.1:9896 node tools/ct-volume.cjs
 */
const { chromium } = require('playwright');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.CT_BASE || 'http://127.0.0.1:9896';
(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const page = await (await browser.newContext()).newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  const urls = [
    '/admin-api/cart-tracking?period=7d',
    '/admin-api/cart-tracking?period=all',
    '/admin-api/cart-tracking?period=all&sort=value&dir=desc',
    '/admin-api/cart-tracking?period=all&page=1000',
    '/admin-api/cart-tracking?period=30d&bot=yes',
    '/admin-api/cart-tracking?period=all&country=SA',
    '/admin-api/cart-tracking?period=all&q=Snail',
    '/admin-api/cart-tracking?period=all&q=94.200.',
    '/admin-api/cart-tracking/carts/50000',
    '/admin-api/cart-tracking/products?kind=added&period=7d',
    '/admin-api/cart-tracking/products?kind=added&period=all',
    '/admin-api/cart-tracking/products?kind=removed&period=all',
  ];
  for (const u of urls) {
    const times = await page.evaluate(async (u) => {
      const out = [];
      for (let i = 0; i < 3; i++) {
        const t = performance.now();
        const r = await fetch(u, { headers: { Accept: 'application/json' } });
        const j = await r.json();
        out.push([Math.round(performance.now() - t), r.status, j.total ?? (j.rows ? j.rows.length : null)]);
      }
      return out;
    }, BASE + u);
    console.log(JSON.stringify({ url: u, cold_ms: times[0][0], warm_ms: times.slice(1).map((x) => x[0]), status: times[0][1], total: times[0][2] }));
  }
  await browser.close();
})();
