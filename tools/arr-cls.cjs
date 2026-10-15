/*
 * Lane AR round 2 -- CLS and instant-nav prefetch on a running preview.
 *
 *   node tools/arr-cls.cjs <port> <tag>  ->  storage/ar-logs/cls-<tag>.json
 *
 * CLS: layout-shift entries (no recent input) summed over load + 1.5 s, at 390
 * and 1280, for home / category / product / cart / checkout in both languages.
 * Prefetch: on /ar and English, hover a product, a category and a brand link
 * (the speculation rules' moderate eagerness), wait, click, and read the new
 * document's navigation deliveryType -- "navigational-prefetch" means the
 * prefetch fired AND was used.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const [port, tag] = [process.argv[2], process.argv[3] || 'run'];
const B = `http://127.0.0.1:${port}`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const ids = JSON.parse(fs.readFileSync('storage/ar-logs/preview-ids.json', 'utf8'));
const PAGES = { home: '/', category: '/product-category/toners/', product: `/product/${ids.hero}/`, cart: '/cart/', checkout: '/checkout/' };

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = { cls: {}, prefetch: {} };
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, userAgent: UA });
    await ctx.addInitScript(() => {
      window.__cls = 0;
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
    });
    for (const lang of ['en', 'ar']) {
      for (const [k, p] of Object.entries(PAGES)) {
        const page = await ctx.newPage();
        await page.goto(B + (lang === 'ar' ? '/ar' : '') + p, { waitUntil: 'load' });
        await page.waitForTimeout(1500);
        out.cls[`${lang} ${k} ${w}`] = Math.round((await page.evaluate(() => window.__cls)) * 10000) / 10000;
        await page.close();
      }
    }
    await ctx.close();
  }
  // Prefetch fired and used, at 1280 (hover is a pointer gesture).
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA });
  for (const lang of ['en', 'ar']) {
    const pre = lang === 'ar' ? '/ar' : '';
    for (const [kind, from, sel] of [
      ['product', '/product-category/toners/', `a[href*="${pre}/product/"]`],
      ["category", "/", `a[href="${pre}/collections/toners/"]`],
      ['brand', '/brands/', `a[href*="${pre}/brands/"]:not([href$="/brands/"])`],
    ]) {
      const page = await ctx.newPage();
      await page.goto(B + pre + from, { waitUntil: 'load' });
      await page.waitForTimeout(600);
      const links = await page.$$(sel);
      let res = 'no link';
      for (const a of links) {
        if (!(await a.isVisible())) continue;
        const href = await a.getAttribute('href');
        await a.hover();
        await page.waitForTimeout(1200);
        await Promise.all([page.waitForNavigation({ waitUntil: 'load' }).catch(() => null), a.click()]);
        await page.waitForTimeout(300);
        const dt = await page.evaluate(() => (performance.getEntriesByType('navigation')[0] || {}).deliveryType || '(none)');
        res = { href, deliveryType: dt, used: dt === 'navigational-prefetch' };
        break;
      }
      out.prefetch[`${lang} ${kind}`] = res;
      console.log(lang, kind, JSON.stringify(res));
      await page.close();
    }
  }
  await browser.close();
  fs.writeFileSync(`storage/ar-logs/cls-${tag}.json`, JSON.stringify(out, null, 1));
  console.log(JSON.stringify(out.cls));
})();
