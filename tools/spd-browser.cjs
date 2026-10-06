/*
 * Lane SP (speed): browser-side numbers per page type, old / HEAD / fix.
 *
 *   node tools/spd-browser.cjs LABEL[,LABEL...] [N] > out.json
 *
 * Each load is a FRESH context (empty HTTP cache), interleaved across the
 * previews. Two profiles:
 *   phone   390x844 DPR 3, touch, Slow-4G-ish (150 ms RTT, 1.6 Mbps down,
 *           750 kbps up) and 4x CPU -- Lighthouse's mobile settings
 *   laptop  1280x800, no throttling
 * Recorded: TTFB, DOMContentLoaded, load, LCP, CLS, requests, bytes on the
 * wire (transferSize), render-blocking CSS bytes, images without srcset.
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const APP = path.dirname(__dirname);
const labels = process.argv[2].split(',');
const N = Number(process.argv[3] || 5);
const PAGES = [
  ['home', '/'], ['product', '/product/spd-product-500/'], ['category big', '/collections/spd-dept-1/'],
  ['category small', '/collections/spd-dept-1/spd-shelf-1-2/'], ['brand', '/brands/anua/'], ['super-sale', '/super-sale/'],
  ['shop', '/shop/'], ['search', '/shop/?s=glow'], ['blog post', '/blog/spd-post-12/'],
].filter(([n]) => !process.env.SPD_PAGES || process.env.SPD_PAGES.split(',').includes(n));
const PROFILES = {
  phone: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, net: { latency: 150, downloadThroughput: 1.6e6 / 8, uploadThroughput: 750e3 / 8 }, cpu: 4 },
  laptop: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1, isMobile: false, hasTouch: false, net: null, cpu: 1 },
};
const port = (l) => fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-spd-' + l, 'port'), 'utf8').trim();
const median = (a) => { const s = a.filter((x) => x != null).sort((x, y) => x - y); return s.length ? Math.round(s[Math.floor(s.length / 2)] * 10) / 10 : null; };

async function measure(browser, prof, url) {
  const ctx = await browser.newContext({ viewport: prof.viewport, deviceScaleFactor: prof.deviceScaleFactor, isMobile: prof.isMobile, hasTouch: prof.hasTouch });
  await ctx.addInitScript(() => {
    window.__spd = { lcp: 0, cls: 0 };
    new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__spd.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__spd.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  });
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  if (prof.net) { await cdp.send('Network.enable'); await cdp.send('Network.emulateNetworkConditions', { offline: false, ...prof.net }); }
  if (prof.cpu > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: prof.cpu });
  await page.goto(url, { waitUntil: 'load', timeout: 120000 });
  await page.waitForTimeout(prof.net ? 1500 : 500);
  const r = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const res = performance.getEntriesByType('resource');
    const blockingCss = res.filter((x) => x.initiatorType === 'link' && /\.css(\?|$)/.test(x.name) && x.renderBlockingStatus === 'blocking');
    const imgs = [...document.images].filter((i) => i.getBoundingClientRect().width > 0);
    return {
      ttfb: nav.responseStart, dcl: nav.domContentLoadedEventEnd, load: nav.loadEventEnd, lcp: window.__spd.lcp, cls: window.__spd.cls,
      requests: res.length + 1, kb: (nav.transferSize + res.reduce((s, x) => s + (x.transferSize || 0), 0)) / 1024,
      cssKb: blockingCss.reduce((s, x) => s + (x.transferSize || 0), 0) / 1024, cssN: blockingCss.length,
      imgsNoSrcset: imgs.filter((i) => !i.srcset && !/\.svg/.test(i.currentSrc)).length, imgs: imgs.length,
      sw: document.documentElement.scrollWidth,
    };
  });
  await ctx.close();
  return r;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const [name, p] of PAGES) {
    for (const [pn, prof] of Object.entries(PROFILES)) {
      const runs = Object.fromEntries(labels.map((l) => [l, []]));
      for (let i = 0; i < N; i++) for (const l of labels) runs[l].push(await measure(browser, prof, `http://127.0.0.1:${port(l)}${p}`));
      for (const l of labels) {
        const m = {};
        for (const k of Object.keys(runs[l][0])) m[k] = median(runs[l].map((x) => x[k]));
        ((out[name] ||= {})[pn] ||= {})[l] = m;
      }
      process.stderr.write(`${name} ${pn} ` + labels.map((l) => `${l}: lcp ${out[name][pn][l].lcp} load ${out[name][pn][l].load} req ${out[name][pn][l].requests} kb ${out[name][pn][l].kb}`).join(' | ') + '\n');
    }
  }
  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
