/*
 * Lane SX: the top menu as a shopper uses it at 1280 -- rest on "Brands",
 * move down into the panel, rest on a brand, click. Records whether the
 * link under the pointer is really the link (elementFromPoint), whether the
 * brand page came from the prefetch cache, and click -> next FCP.
 *   node sx-menu-nav.cjs N label,label
 */
const path = require('path'); const fs = require('fs');
const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const N = Number(process.argv[2] || 3);
const LABELS = (process.argv[3] || 'sxgood,sxhead').split(',');
const ITEM = process.env.SX_ITEM || 'Brands';
const dir = (l) => path.join(APP, 'storage/framework/testing/lane-spd-' + l);
const base = (l) => 'http://127.0.0.1:' + fs.readFileSync(dir(l) + '/port', 'utf8').trim();
const median = (a) => { const s = a.filter((x) => x != null).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)]) : null; };

async function one(browser, l, start) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, serviceWorkers: 'block' });
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
  await ctx.addInitScript(() => { window.__fcp = null; try { new PerformanceObserver((li) => { for (const e of li.getEntries()) if (e.name === 'first-contentful-paint') window.__fcp = e.startTime; }).observe({ type: 'paint', buffered: true }); } catch (e) { /* */ } });
  const page = await ctx.newPage();
  const purposes = [];
  page.on('request', (r) => { const h = r.headers(); if (h['sec-purpose']) purposes.push(new URL(r.url()).pathname); });
  await page.goto(base(l) + start, { waitUntil: 'load' });
  await page.waitForTimeout(600);
  const item = page.locator('.mbar .navitem', { has: page.locator(`.navlink:text-is("${ITEM}")`) }).first();
  const nl = item.locator('.navlink').first();
  const nb = await nl.boundingBox();
  await page.mouse.move(nb.x + nb.width / 2, nb.y + nb.height / 2, { steps: 4 });
  await page.waitForTimeout(350);
  const links = item.locator('.drop a');
  const cnt = await links.count();
  const target = links.nth(Math.min(cnt - 1, Math.floor(cnt * 0.6)));
  const tb = await target.boundingBox();
  const x = tb.x + tb.width / 2; const y = tb.y + tb.height / 2;
  await page.mouse.move(x, y, { steps: 12 });
  await page.waitForTimeout(350);
  const hit = await page.evaluate(([px, py]) => { const e = document.elementFromPoint(px, py); const a = e && e.closest('a'); return { tag: e ? e.tagName + '.' + e.className : null, href: a ? a.getAttribute('href') : null }; }, [x, y]);
  const href = await target.getAttribute('href');
  const t0 = await page.evaluate(() => performance.timeOrigin + performance.now());
  await page.mouse.down(); await page.waitForTimeout(60); await page.mouse.up();
  const want = new URL(href, base(l)).pathname;
  await page.waitForURL((u) => u.pathname === want, { timeout: 30000 }).catch(() => {});
  await page.waitForLoadState('load');
  await page.waitForTimeout(400);
  const n = await page.evaluate(() => { const e = performance.getEntriesByType('navigation')[0]; return { origin: performance.timeOrigin, fcp: window.__fcp, dt: e.deliveryType, path: location.pathname }; });
  await ctx.close();
  return { click: Math.round(n.origin + n.fcp - t0), prefetched: n.dt === 'navigational-prefetch', arrived: n.path === want, hitIsLink: hit.href === href, hit: hit.tag, prefetchedPaths: purposes.length, href };
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const start of ['/brands/anua/', '/product/spd-product-500/']) {
    for (const l of LABELS) {
      const rs = [];
      for (let i = 0; i < N; i++) rs.push(await one(browser, l, start));
      out[start + ' ' + l] = { clickFcp: median(rs.map((r) => r.click)), prefetched: rs.filter((r) => r.prefetched).length + '/' + rs.length, arrived: rs.filter((r) => r.arrived).length, hitIsLink: rs.filter((r) => r.hitIsLink).length, hit: rs[0].hit, href: rs[0].href };
      process.stderr.write(start + ' ' + l + ' ' + JSON.stringify(out[start + ' ' + l]) + '\n');
    }
  }
  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
