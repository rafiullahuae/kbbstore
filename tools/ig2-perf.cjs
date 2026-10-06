/*
 * Lane SG: what /kbeautybliss-spotted/ costs a shopper, measured in Chromium.
 *   node tools/ig2-perf.cjs <port>
 * Fresh context per width (no cache): bytes of every image fetched before any
 * scroll, how many covers were fetched vs on the page, CLS from the
 * layout-shift entries, the page's inline style and script sizes, and every
 * request to instagram.com (must be none before a tap).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const PORT = process.argv[2] || '10470';
const BASE = 'http://127.0.0.1:' + PORT;
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: w < 600 ? 3 : 1 });
    const page = await ctx.newPage();
    await page.addInitScript(() => {
      window.__cls = 0;
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
    });
    const imgs = [];
    const ig = [];
    page.on('request', (r) => { if (/instagram\.com/.test(r.url())) ig.push(r.url()); });
    page.on('response', async (r) => {
      if (r.request().resourceType() === 'image') {
        let len = 0;
        try { len = (await r.body()).length; } catch (e) {}
        imgs.push({ url: r.url().replace(BASE, ''), bytes: len });
      }
    });
    await page.route(/instagram\.com/, (r) => r.abort());
    await page.goto(BASE + '/kbeautybliss-spotted/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const m = await page.evaluate(() => {
      const styles = Array.from(document.querySelectorAll('style')).map((s) => s.textContent).filter((t) => t.includes('.sig-grid'));
      const scripts = Array.from(document.querySelectorAll('script:not([src])')).map((s) => s.textContent).filter((t) => t.includes('sigm'));
      return {
        cls: Math.round(window.__cls * 10000) / 10000,
        cards: document.querySelectorAll('.sig-card').length,
        eagerCovers: document.querySelectorAll('.sig-ph img:not([loading=lazy])').length,
        lazyCovers: document.querySelectorAll('.sig-ph img[loading=lazy]').length,
        asyncDecode: document.querySelectorAll('.sig-ph img[decoding=async]').length,
        withSrcset: document.querySelectorAll('.sig-ph img[srcset]').length,
        inlineCssBytes: styles.reduce((n, t) => n + new TextEncoder().encode(t).length, 0),
        inlineJsBytes: scripts.reduce((n, t) => n + new TextEncoder().encode(t).length, 0),
        jsUsesTimers: scripts.some((t) => /setTimeout|setInterval|requestAnimationFrame/.test(t)),
        jsReadsLayout: scripts.some((t) => /getBoundingClientRect|offsetWidth|offsetHeight|clientWidth|clientHeight|getComputedStyle|scrollHeight/.test(t)),
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: innerWidth,
      };
    });
    const covers = imgs.filter((i) => /uploads\/instagram|img-cache\/\d+\/uploads\/instagram/.test(i.url) && !/avatar/.test(i.url));
    out[w] = Object.assign(m, {
      coversFetchedBeforeScroll: covers.length,
      coverBytesBeforeScroll: covers.reduce((n, i) => n + i.bytes, 0),
      allImageBytesBeforeScroll: imgs.reduce((n, i) => n + i.bytes, 0),
      sampleCover: covers[0] ? covers[0].url : null,
      requestsToInstagram: ig.length,
    });
    await ctx.close();
  }
  await browser.close();
  const file = path.join(__dirname, '..', 'docs/lane-ig2-shots/measurements-perf.json');
  fs.writeFileSync(file, JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
