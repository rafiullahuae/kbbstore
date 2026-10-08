/*
 * Lane HB3: the banner frame's height, the LCP picture and its bytes, the text
 * box, a real click, CLS and prefetch -- measured in Chromium (harness only).
 *   HB_BASE=http://127.0.0.1:10660 HB_TAG=after node tools/hb3-measure.cjs
 */
const { chromium } = require('playwright');
const BASE = process.env.HB_BASE, TAG = process.env.HB_TAG || 'x';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of (process.env.HB_WIDTHS || '360,390,430,1280').split(',').map(Number)) {
    const ctx = await b.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    await page.addInitScript(() => {
      window.__cls = 0; window.__lcp = null;
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
      new PerformanceObserver((l) => { const e = l.getEntries().pop(); window.__lcp = e.element ? (e.element.currentSrc || e.element.tagName) : e.url; }).observe({ type: 'largest-contentful-paint', buffered: true });
    });
    const bytes = {}; const errors = []; let prefetched = 0;
    page.on('response', async (r) => { if (/\.(jpe?g|png|webp)(\?|$)/.test(r.url())) { try { bytes[new URL(r.url()).pathname] = (await r.body()).length; } catch (e) {} } });
    page.on('request', (r) => { if ((r.headers()['sec-purpose'] || '').includes('prefetch')) prefetched++; });
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    const m = await page.evaluate(() => {
      const vp = document.querySelector('.kbbs-vp').getBoundingClientRect();
      const box = document.querySelector('.kbbs-s .hb-box');
      const shown = box && box.offsetParent !== null;
      let boxInfo = null;
      if (shown) {
        const r = box.getBoundingClientRect();
        const btn = box.querySelector('.hb-btn'); let hit = null;
        if (btn) { const q = btn.getBoundingClientRect(); const e = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2); hit = !!(e && btn.contains(e)); }
        boxInfo = { h: Math.round(r.height), top: Math.round(r.top - vp.top), bottom: Math.round(vp.bottom - r.bottom), inside: r.top >= vp.top - 1 && r.bottom <= vp.bottom + 1, buttonHit: hit };
      }
      const img = document.querySelector('.kbbs-s img');
      return { frame: Math.round(vp.width) + 'x' + Math.round(vp.height), img: (img.currentSrc || '').replace(location.origin, ''), fit: getComputedStyle(img).objectFit, box: boxInfo, cls: +window.__cls.toFixed(4), lcp: (window.__lcp || '').replace(location.origin, ''), scrollWidth: document.documentElement.scrollWidth, scripts: document.querySelectorAll('script').length };
    });
    const link = await page.$('a[href*="/product/"]:visible'); if (link) { await link.hover(); await page.waitForTimeout(700); }
    const lcpBytes = bytes[m.lcp.split('?')[0]] ?? null;
    console.log(JSON.stringify({ tag: TAG, width, ...m, lcpBytes, imageBytes: Object.values(bytes).reduce((a, c) => a + c, 0), prefetched, errors }));
    await ctx.close();
  }
  await b.close();
})();
