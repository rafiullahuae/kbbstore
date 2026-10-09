/*
 * Lane LG2: in Chromium, per page and device -- CLS, the LCP element and time,
 * render-blocking CSS bytes (the <head> stylesheets), console errors, and a
 * real click on the first product link (did it open from the prefetch?).
 *
 *   NODE_PATH=/opt/node22/lib/node_modules node tools/lg2-perf.cjs http://127.0.0.1:10760 [runs]
 */
const { chromium } = require('playwright');
const zlib = require('zlib');
const BASE = process.argv[2] || 'http://127.0.0.1:10760';
const N = Number(process.argv[3] || 3);
const PAGES = (process.env.CB_PAGES || '/,/collections/cbbanner/,/brands/anua/,/product/relief-sun-rice-probiotics-spf50/').split(',');
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
const med = (a) => { const s = a.filter((x) => x != null).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)]) : null; };

async function load(browser, w, p) {
  const phone = w < 600;
  const ctx = await browser.newContext(phone ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, userAgent: UA } : { viewport: { width: 1280, height: 800 } });
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
  await ctx.addInitScript(() => {
    window.__p = { lcp: null, el: '', cls: 0 };
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { window.__p.lcp = e.startTime; window.__p.el = e.element ? (e.element.tagName + '.' + String(e.element.className).split(' ')[0]) : ''; } }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__p.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  });
  const page = await ctx.newPage();
  const errs = [];
  const prefetches = [];
  page.on('pageerror', (e) => errs.push(String(e.message).slice(0, 120)));
  page.on('console', (c) => { if (c.type() === 'error') errs.push(c.text().slice(0, 120)); });
  page.on('request', (r) => { const h = r.headers(); if ((h['sec-purpose'] || '').includes('prefetch')) prefetches.push(new URL(r.url()).pathname); });
  await page.goto(BASE + p, { waitUntil: 'load' });
  await page.waitForTimeout(900);
  const r = await page.evaluate(async () => {
    const links = [...document.querySelectorAll('head link[rel=stylesheet]')].map((l) => l.href);
    let css = 0; const bodies = [];
    for (const h of links) { try { const t = await (await fetch(h)).text(); css += t.length; bodies.push(t); } catch (e) { /* */ } }
    return { bodies, html: document.documentElement.outerHTML.length, scripts: document.scripts.length, lcp: window.__p.lcp, el: window.__p.el, cls: window.__p.cls, css, sheets: links.map((h) => h.split('/').pop().replace(/-[A-Za-z0-9_]{8}\.css$/, '.css')).join(' '), sw: document.documentElement.scrollWidth };
  });
  // Click the first product link like a person: move, wait, press.
  const href = await page.evaluate(() => { const a = [...document.querySelectorAll('a[href*="/product/"]')].find((x) => x.getBoundingClientRect().width > 20 && x.pathname !== location.pathname && !x.search); return a ? a.getAttribute('href') : null; });
  let prefetched = null;
  if (href) {
    const link = page.locator(`a[href="${href}"]`).filter({ visible: true }).first();
    await link.scrollIntoViewIfNeeded();
    const box = await link.boundingBox();
    const x = box.x + box.width / 2; const y = box.y + Math.min(box.height / 2, 40);
    const hit = await page.evaluate(([x, y, h]) => { const e = document.elementFromPoint(x, y); const a = e && e.closest('a'); return !!a && a.getAttribute('href') === h; }, [x, y, href]);
    if (phone) { await page.touchscreen.tap(x, y); } else { await page.mouse.move(x, y); await page.waitForTimeout(350); await page.mouse.click(x, y); }
    await page.waitForURL((u) => u.pathname === new URL(href, BASE).pathname, { timeout: 30000 });
    await page.waitForLoadState('load');
    const dt = await page.evaluate(() => performance.getEntriesByType('navigation')[0].deliveryType);
    prefetched = `${hit ? 'hit' : 'MISSED'} ${dt === 'navigational-prefetch' ? 'from-prefetch' : 'network'} (${prefetches.length} prefetch req)`;
  }
  await ctx.close();
  return { ...r, errs, prefetched };
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    for (const p of PAGES) {
      const rs = [];
      for (let i = 0; i < N; i++) rs.push(await load(browser, w, p));
      const cls = Math.max(...rs.map((x) => x.cls));
      console.log(`${w} ${p} | LCP ${med(rs.map((x) => x.lcp))}ms ${rs[0].el} | CLS ${cls.toFixed(4)} | head CSS ${rs[0].css} B / gzip ${zlib.gzipSync(rs[0].bodies.join('')).length} B [${rs[0].sheets}] | sw ${rs[0].sw} | scripts ${rs[0].scripts} | click ${rs.map((x) => x.prefetched).join(', ')} | errors ${[...new Set(rs.flatMap((x) => x.errs))].join(' ; ') || 0}`);
    }
  }
  await browser.close();
})();
