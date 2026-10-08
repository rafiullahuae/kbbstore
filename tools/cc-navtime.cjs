/*
 * Lane CC copy of tools/spd-navtime.cjs: previews lane-spd-cchead ('head') and lane-spd-ccnew ('on'),
 * both at the shipped settings (no setFeature), plus three pairs that END on the homepage.
 *   SPD_VARIANTS=head,on node tools/cc-navtime.cjs [N]
 *
 * Lane SP: click-to-next-page-painted, with and without instant page changes.
 *
 *   node tools/spd-navtime.cjs [N] > out.json
 *
 * Variants (same machine, interleaved):
 *   head   the HEAD tree (2.60.408): no feature, the old server speed
 *   off    this lane's tree, Page speed switches OFF
 *   on     this lane's tree, both switches ON (as shipped)
 * Profiles:
 *   phone   390x844, touch, 4x CPU, and a Slow-4G network simulated ON THE
 *           SERVER (tools/spd-router.php: 150 ms a request + 1.6 Mbps), so
 *           Chrome's own prefetches are slowed exactly like the clicks are
 *   laptop  1280x800, no throttling
 * A real gesture each time: the laptop rests the pointer on the link for
 * 300 ms then clicks; the phone holds a finger down for 90 ms (CDP touch).
 * Every run starts in a fresh browser profile, opens the start page, waits for
 * the Site App service worker to control it (as on a second page view), then
 * moves on. Third-party pixel scripts are answered locally with an empty
 * script so the internet is not part of the number.
 */
const path = require('path'); const fs = require('fs'); const { execSync } = require('child_process');
const { chromium } = require(require('path').join(__dirname, '..', 'node_modules', 'playwright'));
const APP = path.dirname(__dirname);
const N = Number(process.argv[2] || 5);
// SPD_SW=block: the same runs with service workers refused, to cost the Site App worker.
const SW = process.env.SPD_SW === 'block' ? 'block' : 'allow';
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const UAD = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const dir = (l) => path.join(APP, 'storage/framework/testing/lane-spd-' + ({ head: 'cchead', nav: 'ccnew' }[l] || l));
const base = (l) => 'http://127.0.0.1:' + fs.readFileSync(dir(l) + '/port', 'utf8').trim();
const PAIRS_ALL = [
  ['product→product', '/product/spd-product-500/'],
  ['category→product', '/collections/spd-dept-1/'],
  ['brand→product', '/brands/anua/'],
  ['home→product', '/'],
  ['product→home', '/product/spd-product-500/', '/'],
  ['category→home', '/collections/spd-dept-1/', '/'],
];
const PAIRS = PAIRS_ALL.filter(([n]) => !process.env.SPD_PAIRS || process.env.SPD_PAIRS.split(',').includes(n));
function setFeature(on, fade = on) { return; // Lane CC: both previews run the shipped settings (instant nav on, fade off)
  const v = on ? 1 : 0; const f = fade ? 1 : 0;
  execSync(`mysql -u root kbb_spd_nav -e "DELETE FROM settings WHERE \\\`key\\\` IN ('layout_nav_instant','layout_nav_fade'); INSERT INTO settings (\\\`key\\\`,value,autoload,created_at,updated_at) VALUES ('layout_nav_instant','${v}',1,NOW(),NOW()),('layout_nav_fade','${f}',1,NOW(),NOW());"`);
  fs.rmSync(dir('nav') + '/app/storage/framework/cache/data', { recursive: true, force: true });
  fs.mkdirSync(dir('nav') + '/app/storage/framework/cache/data', { recursive: true });
}
function slow(on) {
  for (const l of ['cchead', 'ccnew']) { const f = dir(l) + '/slow.on'; on ? fs.writeFileSync(f, '1') : fs.rmSync(f, { force: true }); }
}
const median = (a) => { const s = a.filter((x) => x != null).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)]) : null; };

async function run(browser, label, prof, start, target) {
  const phone = prof === 'phone';
  const ctx = await browser.newContext(phone
    ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, userAgent: UA, serviceWorkers: SW }
    : { viewport: { width: 1280, height: 800 }, userAgent: UAD, serviceWorkers: SW });
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
  await ctx.addInitScript(() => {
    window.__p = { fcp: null, lcp: null };
    try {
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (e.name === 'first-contentful-paint') window.__p.fcp = e.startTime; }).observe({ type: 'paint', buffered: true });
      new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__p.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) { /* */ }
  });
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  if (phone) await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  const b = base(label);
  await page.goto(b + start, { waitUntil: 'load', timeout: 120000 });
  if (SW === 'allow') await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller, null, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(400);
  const href = target || await page.evaluate((cur) => {
    const as = [...document.querySelectorAll('main a[href^="/product/"], .kbb-grid a[href^="/product/"], a.kbb-card-shot[href^="/product/"], a[href^="/product/"]')]
      .filter((a) => !a.search && a.getAttribute('href') !== cur && a.getBoundingClientRect().width > 20 && getComputedStyle(a).visibility !== 'hidden');
    return as.length ? as[0].getAttribute('href') : null;
  }, start);
  const link = page.locator(target ? `header a[href="${href}"], a[href="${href}"]` : `a[href="${href}"]`).filter({ visible: true }).first();
  await link.scrollIntoViewIfNeeded();
  await page.waitForTimeout(300);
  const box = await link.boundingBox();
  const x = box.x + box.width / 2; const y = box.y + Math.min(box.height / 2, 40);
  let t0;
  if (phone) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await page.waitForTimeout(90);
    t0 = await page.evaluate(() => performance.timeOrigin + performance.now());
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  } else {
    await page.mouse.move(x, y);
    await page.waitForTimeout(300);
    t0 = await page.evaluate(() => performance.timeOrigin + performance.now());
    await page.mouse.down(); await page.waitForTimeout(60); await page.mouse.up();
  }
  await page.waitForURL((u) => u.pathname === href, { timeout: 60000 });
  await page.waitForLoadState('load', { timeout: 60000 });
  await page.waitForTimeout(phone ? 1200 : 400);
  const r = await page.evaluate(() => {
    const n = performance.getEntriesByType('navigation')[0];
    const ls = performance.getEntriesByType('layout-shift').reduce((s, e) => s + (e.hadRecentInput ? 0 : e.value), 0);
    return { origin: performance.timeOrigin, fcp: window.__p.fcp, lcp: window.__p.lcp, deliveryType: n.deliveryType, responseStart: n.responseStart, cls: ls };
  });
  await ctx.close();
  // t0 is the click: the button going down (laptop), the finger lifting (phone).
  return { click: Math.round(r.origin + r.fcp - t0), clickLcp: Math.round(r.origin + r.lcp - t0), ttfb: Math.round(r.responseStart), prefetched: r.deliveryType === 'navigational-prefetch', cls: Math.round(r.cls * 1000) / 1000, href };
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const prof of (process.env.SPD_PROFILES || 'laptop,phone').split(',')) {
    slow(prof === 'phone');
    for (const [pair, start, target] of PAIRS) {
      const VARIANTS = (process.env.SPD_VARIANTS || 'head,off,on').split(',');
      const SET = { off: [false, false], on: [true, true], prefetch: [true, false], fade: [false, true] };
      const runs = Object.fromEntries(VARIANTS.map((v) => [v, []]));
      for (let i = 0; i < N; i++) {
        for (const v of VARIANTS) {
          if (v === 'head') { runs.head.push(await run(browser, 'head', prof, start, target)); continue; }
          setFeature(...SET[v]); runs[v].push(await run(browser, 'nav', prof, start, target));
        }
      }
      for (const [v, rs] of Object.entries(runs)) {
        ((out[pair] ||= {})[prof] ||= {})[v] = {
          clickToFcp: median(rs.map((x) => x.click)), clickToLcp: median(rs.map((x) => x.clickLcp)), ttfb: median(rs.map((x) => x.ttfb)),
          prefetched: rs.filter((x) => x.prefetched).length + '/' + rs.length, cls: Math.max(...rs.map((x) => x.cls)), sample: rs[0].href,
        };
      }
      process.stderr.write(`${pair} ${prof} ` + JSON.stringify(out[pair][prof]) + '\n');
    }
  }
  slow(false);
  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
