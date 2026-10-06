/*
 * Lane SX: per ref x profile x page -- server ms/queries, then in Chromium a
 * fresh-context page load (TTFB, FCP, LCP, CLS, main-thread style/layout/script
 * ms from CDP Performance.getMetrics, HTML and CSS bytes), then a click to the
 * first product link (click -> next FCP, served from prefetch or not).
 *
 *   node sx-measure.cjs N label1,label2,... > out.json
 */
const path = require('path'); const fs = require('fs');
const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const N = Number(process.argv[2] || 5);
const LABELS = (process.argv[3] || 'sxgood,sx416,sx417,sxhead').split(',');
const PAGES = (process.env.SX_PAGES || '/,/shop/,/collections/spd-dept-1/,/brands/anua/,/product/spd-product-500/').split(',');
const PROFILES = (process.env.SX_PROFILES || 'laptop,phone').split(',');
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const UAD = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const dir = (l) => path.join(APP, 'storage/framework/testing/lane-spd-' + l);
const base = (l) => 'http://127.0.0.1:' + fs.readFileSync(dir(l) + '/port', 'utf8').trim();
const median = (a) => { const s = a.filter((x) => x != null && !Number.isNaN(x)).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)] * 10) / 10 : null; };
const slow = (l, on) => { const f = dir(l) + '/slow.on'; on ? fs.writeFileSync(f, '1') : fs.rmSync(f, { force: true }); };

async function server(label, p) {
  const out = [];
  for (let i = 0; i < N + 1; i++) {
    const r = await fetch(base(label) + p, { headers: { 'User-Agent': UAD, 'Accept-Encoding': 'gzip' } });
    const body = await r.text();
    if (i === 0) continue; // the first is a warm-up
    out.push({ ms: +r.headers.get('x-spd-ms'), q: +r.headers.get('x-spd-q'), cache: +r.headers.get('x-spd-cache'), bytes: body.length, status: r.status, spec: /speculationrules/.test(body) });
  }
  return { ms: median(out.map((x) => x.ms)), q: median(out.map((x) => x.q)), cacheReads: median(out.map((x) => x.cache)), htmlBytes: out[0].bytes, status: out[0].status, speculation: out[0].spec };
}

async function load(browser, label, prof, p, click) {
  const phone = prof === 'phone';
  const ctx = await browser.newContext(phone
    ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, userAgent: UA, serviceWorkers: 'block' }
    : { viewport: { width: 1280, height: 800 }, userAgent: UAD, serviceWorkers: 'block' });
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, contentType: 'text/javascript', body: '' }));
  await ctx.addInitScript(() => {
    window.__p = { fcp: null, lcp: null, shifts: [] };
    try {
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (e.name === 'first-contentful-paint') window.__p.fcp = e.startTime; }).observe({ type: 'paint', buffered: true });
      new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__p.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__p.shifts.push({ v: e.value, t: Math.round(e.startTime), src: (e.sources || []).map((s) => { const n = s.node; return n && n.nodeType === 1 ? (n.tagName + (n.id ? '#' + n.id : '') + (n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\s+/).slice(0, 3).join('.') : '')) + ' ' + JSON.stringify([s.previousRect.y, s.previousRect.height, s.currentRect.y, s.currentRect.height].map(Math.round)) : '#text'; }) }); }).observe({ type: 'layout-shift', buffered: true });
    } catch (e) { /* */ }
  });
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Performance.enable');
  if (phone) await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  let cssBytes = 0; const errs = [];
  page.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));
  page.on('console', (c) => { if (c.type() === 'error') errs.push('console: ' + c.text().slice(0, 160)); });
  page.on('response', async (r) => { if (/\.css(\?|$)/.test(r.url())) { try { cssBytes += (await r.body()).length; } catch (e) { /* */ } } });
  const b = base(label);
  await page.goto(b + p, { waitUntil: 'load', timeout: 120000 });
  await page.waitForTimeout(phone ? 1500 : 800);
  const m = Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((x) => [x.name, x.value]));
  const r = await page.evaluate(() => {
    const n = performance.getEntriesByType('navigation')[0];
    return { fcp: window.__p.fcp, lcp: window.__p.lcp, ttfb: n.responseStart, dcl: n.domContentLoadedEventEnd, shifts: window.__p.shifts, scale: document.querySelector('.mbar') ? document.querySelector('.mbar').style.getPropertyValue('--nav-scale') : null, nodes: document.getElementsByTagName('*').length };
  });
  const res = { ttfb: r.ttfb, fcp: r.fcp, lcp: r.lcp, dcl: r.dcl, cls: r.shifts.reduce((s, e) => s + e.v, 0), shifts: r.shifts, styleMs: m.RecalcStyleDuration * 1000, layoutMs: m.LayoutDuration * 1000, scriptMs: m.ScriptDuration * 1000, taskMs: m.TaskDuration * 1000, layoutCount: m.LayoutCount, styleCount: m.RecalcStyleCount, cssBytes, nodes: r.nodes, scale: r.scale, errs };
  if (click) {
    const href = await page.evaluate(() => {
      const as = [...document.querySelectorAll('main a[href*="/product/"], a[href*="/product/"]')]
        .filter((a) => !a.search && a.pathname !== location.pathname && a.getBoundingClientRect().width > 20 && getComputedStyle(a).visibility !== 'hidden');
      return as.length ? as[0].getAttribute('href') : null;
    });
    if (href) {
      const link = page.locator(`a[href="${href}"]`).filter({ visible: true }).first();
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
      const want = new URL(href, b).pathname;
      await page.waitForURL((u) => u.pathname === want, { timeout: 60000 });
      await page.waitForLoadState('load', { timeout: 60000 });
      await page.waitForTimeout(phone ? 1200 : 400);
      const n = await page.evaluate(() => { const e = performance.getEntriesByType('navigation')[0]; return { origin: performance.timeOrigin, fcp: window.__p.fcp, lcp: window.__p.lcp, dt: e.deliveryType }; });
      res.clickFcp = n.origin + n.fcp - t0; res.clickLcp = n.origin + n.lcp - t0; res.prefetched = n.dt === 'navigational-prefetch';
    }
  }
  await ctx.close();
  return res;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const l of LABELS) {
    out[l] = { server: {} };
    for (const p of PAGES) out[l].server[p] = await server(l, p);
    process.stderr.write(l + ' server ' + JSON.stringify(out[l].server) + '\n');
  }
  for (const prof of PROFILES) {
    for (const p of PAGES) {
      const runs = Object.fromEntries(LABELS.map((l) => [l, []]));
      for (let i = 0; i < N; i++) {
        for (const l of LABELS) { slow(l, prof === 'phone'); runs[l].push(await load(browser, l, prof, p, true)); slow(l, false); }
      }
      for (const l of LABELS) {
        const rs = runs[l];
        const pick = (k) => median(rs.map((x) => x[k]));
        ((out[l][prof] ||= {})[p] = {
          ttfb: pick('ttfb'), fcp: pick('fcp'), lcp: pick('lcp'), dcl: pick('dcl'), cls: Math.max(...rs.map((x) => x.cls)),
          styleMs: pick('styleMs'), layoutMs: pick('layoutMs'), scriptMs: pick('scriptMs'), taskMs: pick('taskMs'), layoutCount: pick('layoutCount'), styleCount: pick('styleCount'),
          cssBytes: rs[0].cssBytes, nodes: rs[0].nodes, scale: rs[0].scale,
          clickFcp: pick('clickFcp'), clickLcp: pick('clickLcp'), prefetched: rs.filter((x) => x.prefetched).length + '/' + rs.length,
          errors: [...new Set(rs.flatMap((x) => x.errs))].slice(0, 5),
          lateShifts: rs.flatMap((x) => x.shifts.filter((s) => s.v > 0.0005)).slice(0, 6),
        });
        process.stderr.write(`${prof} ${p} ${l} ` + JSON.stringify(out[l][prof][p]) + '\n');
      }
    }
  }
  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
