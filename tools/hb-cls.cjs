/*
 * Lane HB: CLS, the LCP element and prefetch on the homepage, before and after.
 *   HB_BASES=http://127.0.0.1:10480,http://127.0.0.1:10460 node tools/hb-cls.cjs
 */
const { chromium } = require('playwright');
const BASES = (process.env.HB_BASES || '').split(',');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const base of BASES) for (const width of [390, 1280]) for (const path of ['/', '/collections/toners/', '/brands/anua/', '/product/glow-deep-serum-rice-alpha-arbutin/']) {
    const ctx = await browser.newContext({ viewport: { width, height: 844 } });
    const page = await ctx.newPage();
    await page.addInitScript(() => {
      window.__cls = 0; window.__lcp = null;
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
      new PerformanceObserver((l) => { const e = l.getEntries().pop(); window.__lcp = e.element ? (e.element.tagName + ' ' + (e.element.currentSrc || e.element.className || '').split('/').pop()) : e.url; }).observe({ type: 'largest-contentful-paint', buffered: true });
    });
    const prefetched = [];
    page.on('request', (r) => { const h = r.headers(); if ((h['sec-purpose'] || '').includes('prefetch')) prefetched.push(new URL(r.url()).pathname); });
    const reqs = [];
    page.on('request', (r) => reqs.push(r.url()));
    await page.goto(base + path, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    // Hover a product link: instant navigation prefetches on intent.
    const link = await page.$('a[href*="/product/"]:visible');
    if (link) { await link.hover(); await page.waitForTimeout(900); }
    const r = await page.evaluate(() => ({ cls: +window.__cls.toFixed(4), lcp: window.__lcp, spec: document.querySelectorAll('script[type=speculationrules]').length, scripts: document.querySelectorAll('script').length }));
    console.log(JSON.stringify({ base: base.slice(-5), width, path, ...r, requests: reqs.length, prefetched: prefetched.length }));
    await ctx.close();
  }
  await browser.close();
})();
