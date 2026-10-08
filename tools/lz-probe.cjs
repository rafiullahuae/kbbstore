/*
 * Lane LZ: which pictures are in the FIRST VIEWPORT of a page, and how each is
 * marked (loading / fetchpriority). Harness-only; nothing here ships.
 *   node tools/lz-probe.cjs PORT PATH[,PATH] [390,1280]
 */
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const [port, paths, widths = '390,1280'] = process.argv.slice(2);
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of widths.split(',').map(Number)) {
    const mobile = w < 600;
    const ctx = await b.newContext({ viewport: { width: w, height: mobile ? 844 : 860 }, deviceScaleFactor: mobile ? 3 : 1, isMobile: mobile, hasTouch: mobile, serviceWorkers: 'block' });
    for (const p of paths.split(',')) {
      const page = await ctx.newPage();
      await page.goto('http://127.0.0.1:' + port + p, { waitUntil: 'load' });
      const r = await page.evaluate(() => {
        const vh = innerHeight, vw = innerWidth, out = [];
        for (const img of document.images) {
          const r0 = img.getBoundingClientRect();
          let t = r0.top, l = r0.left, bo = r0.bottom, ri = r0.right;
          for (let el = img.parentElement; el && el !== document.body; el = el.parentElement) {
            const cs = getComputedStyle(el);
            if (cs.display !== 'contents' && (cs.overflowX !== 'visible' || cs.overflowY !== 'visible')) { const c = el.getBoundingClientRect(); t = Math.max(t, c.top); l = Math.max(l, c.left); bo = Math.min(bo, c.bottom); ri = Math.min(ri, c.right); }
          }
          const b = { top: t, left: l, bottom: bo, right: ri, width: Math.max(0, ri - l), height: Math.max(0, bo - t) };
          if (r0.width < 8 || r0.height < 8) continue;
          let el = img, vis = true;
          while (el && el.nodeType === 1) { const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || +cs.opacity === 0) { vis = false; break; } el = el.parentElement; }
          const inV = b.top < vh && b.bottom > 0 && b.left < vw && b.right > 0;
          out.push({ inV, vis, top: Math.round(b.top), w: Math.round(b.width), loading: img.getAttribute('loading'), fp: img.getAttribute('fetchpriority'), cls: (img.className || img.parentElement.className || '').toString().slice(0, 30), src: (img.currentSrc || img.src).replace(location.origin, '').slice(0, 70) });
        }
        return { n: document.images.length, lazy: document.querySelectorAll('img[loading=lazy]').length, imgs: out };
      });
      const above = r.imgs.filter((x) => x.inV && x.vis);
      console.log(`\n== ${w} ${p}  imgs=${r.n} lazyAttr=${r.lazy}  firstViewport=${above.length} lazyInFirst=${above.filter((x) => x.loading === 'lazy').length}`);
      for (const x of above) console.log('  ', x.top, x.w, x.loading || '-', x.fp || '-', x.cls, x.src);
      const next = r.imgs.filter((x) => !x.inV && x.vis && x.top < (w < 600 ? 844 : 860) * 1.6 && x.top > 0);
      if (next.length) console.log('   just below:', next.map((x) => x.top + ':' + (x.loading || '-')).join(' '));
      await page.close();
    }
    await ctx.close();
  }
  await b.close();
})();
