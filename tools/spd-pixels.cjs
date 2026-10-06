/* Lane SP: prove no analytics double count. Hover four product links (each is
 * fetched ahead), open only the last one, and count what fired.
 *   node tools/spd-pixels.cjs LABEL
 * Pixel scripts are answered locally; each request for one is one page that
 * RAN its pixels (routing disables the HTTP cache, so every page asks again).
 */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const D = path.join(path.dirname(__dirname), 'storage/framework/testing/lane-spd-' + process.argv[2]);
const base = 'http://127.0.0.1:' + fs.readFileSync(D + '/port', 'utf8').trim();
const log = () => fs.readFileSync(D + '/spd-after.log', 'utf8').trim().split('\n').map((l) => JSON.parse(l));
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
  const fired = { meta: 0, ga4: 0, tiktok: 0 };
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => {
    const u = r.request().url();
    if (u.includes('fbevents.js')) fired.meta++; else if (u.includes('gtag/js')) fired.ga4++; else if (u.includes('tiktok.com')) fired.tiktok++;
    r.fulfill({ status: 200, contentType: 'text/javascript', body: '' });
  });
  const page = await ctx.newPage();
  await page.goto(base + '/collections/spd-dept-1/', { waitUntil: 'load' });
  await page.waitForTimeout(800);
  const n0 = log().length;
  const hrefs = await page.$$eval('a[href^="/product/"]', (as) => [...new Set(as.filter((a) => a.getBoundingClientRect().width > 20).map((a) => a.getAttribute('href')))].slice(0, 4));
  for (const h of hrefs) { await page.hover(`a[href="${h}"] >> visible=true`); await page.waitForTimeout(500); }
  const firedBeforeOpen = { ...fired };
  const last = hrefs[hrefs.length - 1];
  await Promise.all([page.waitForURL((u) => u.pathname === last), page.click(`a[href="${last}"] >> visible=true`)]);
  await page.waitForLoadState('load'); await page.waitForTimeout(1500);
  const reqs = log().slice(n0);
  const viewed = (await ctx.cookies()).find((c) => c.name === 'kbb_viewed');
  console.log(JSON.stringify({
    hovered: hrefs, opened: last,
    serverProductRequests: reqs.filter((r) => r.uri.startsWith('/product/')).map((r) => r.uri + ' ' + (r.purpose || 'normal')),
    pixelScriptsAfterStartPage: firedBeforeOpen, pixelScriptsTotal: fired,
    productViewBeacons: reqs.filter((r) => r.uri === '/api/product-view').length,
    viewedPosts: reqs.filter((r) => r.uri === '/api/viewed').length, viewedCookieSet: !!viewed,
    deliveryType: await page.evaluate(() => performance.getEntriesByType('navigation')[0].deliveryType),
  }, null, 1));
  await b.close();
})();
