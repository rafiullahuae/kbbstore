/* Lane SP: does the speculation-rules prefetch fire, get used, and leave state alone?
 *   node tools/spd-nav-probe.cjs LABEL
 */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const APP = path.dirname(__dirname);
const L = process.argv[2];
const D = path.join(APP, 'storage/framework/testing/lane-spd-' + L);
const base = 'http://127.0.0.1:' + fs.readFileSync(D + '/port', 'utf8').trim();
const log = () => fs.readFileSync(D + '/spd-after.log', 'utf8').trim().split('\n').map((l) => JSON.parse(l));
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
  const page = await ctx.newPage();
  await page.goto(base + '/brands/anua/', { waitUntil: 'load' });
  const n0 = log().length;
  const href = await page.$eval('a[href*="/product/"]', (a) => a.getAttribute('href'));
  const rulesIn = await page.evaluate(() => document.querySelectorAll('script[type=speculationrules]').length);
  await page.hover(`a[href="${href}"]`);
  await page.waitForTimeout(1500);
  const after = log().slice(n0);
  const cookiesBefore = (await ctx.cookies()).map((c) => c.name);
  // B: an UNUSED prefetch -- was "Recently viewed" written?
  const viewed = (await ctx.cookies()).find((c) => c.name === 'kbb_viewed');
  await page.mouse.move(5, 5);
  // A: click -> served from the prefetch?
  await Promise.all([page.waitForLoadState('load'), page.click(`a[href="${href}"]`)]);
  await page.waitForLoadState('load');
  const nav = await page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return { url: location.pathname, deliveryType: n.deliveryType, responseStart: n.responseStart, activationStart: n.activationStart }; });
  const requestsForProduct = log().slice(n0).filter((r) => r.uri === href);
  await page.waitForTimeout(800);
  const viewedAfterOpen = (await ctx.cookies()).find((c) => c.name === 'kbb_viewed');
  const viewedPosts = log().slice(n0).filter((r) => r.uri === '/api/viewed').length;
  // C: prefetch page X, THEN add to cart, THEN open X: is the bag count current?
  await page.goto(base + '/brands/anua/', { waitUntil: 'load' });
  const n1 = log().length;
  const hrefs = await page.$$eval('a.kbb-card-img-link, a[href*="/product/"]', (as) => [...new Set(as.map((a) => a.getAttribute('href')).filter((h) => /^\/product\/[^?]+$/.test(h)))]);
  const x = hrefs[hrefs.length - 1];
  await page.hover(`a[href="${x}"]`); await page.waitForTimeout(1200);
  const prefetchedX = log().slice(n1).filter((r) => r.uri === x && r.purpose === 'prefetch').length;
  await page.click('[data-kbb-add]'); await page.waitForTimeout(1500);
  const badgeOnPage = await page.evaluate(() => document.getElementById('cartCt')?.textContent);
  await page.keyboard.press('Escape'); await page.waitForTimeout(500);
  if (await page.$('#ov.on')) await page.click('#ov', { force: true }).catch(() => {});
  await page.waitForTimeout(300);
  const n2 = log().length;
  await page.mouse.move(5, 5);
  await Promise.all([page.waitForLoadState('load'), page.click(`a[href="${x}"]`)]);
  await page.waitForTimeout(500);
  const cart = await page.evaluate(() => ({ badge: document.getElementById('cartCt')?.textContent, deliveryType: performance.getEntriesByType('navigation')[0].deliveryType }));
  const refetchedX = log().slice(n2).filter((r) => r.uri === x).map((r) => r.purpose || 'normal');
  console.log(JSON.stringify({ rulesIn, href, hoverRequests: after.map((r) => [r.uri, r.purpose]), viewedAfterHoverOnly: viewed ? viewed.value.length : null, nav, requestsForProduct: requestsForProduct.map((r) => r.purpose),
    viewedAfterOpen: viewedAfterOpen ? viewedAfterOpen.value.length : null, viewedPosts, cartTest: { x, prefetchedX, badgeOnPage, refetchedX, ...cart } }, null, 1));
  await browser.close();
})();
