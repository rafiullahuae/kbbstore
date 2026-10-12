/* Lane CRUMB: every page type with a breadcrumb, at 390 and 1280 -- one line,
   clickable on the first try, no sideways scroll, no console errors.
   BASE=http://127.0.0.1:PORT TAG=... node tools/crumb-pages.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, TAG = process.env.TAG || 'pages';
const OUT = path.resolve(__dirname, '../docs/lane-crumb-shots');
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const PAGES = { product: '/product/mac-boj/', category: '/product-category/cleansers/', shop: '/shop/', brand: '/brand/anua/' };
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];
  for (const w of [390, 1280]) {
    for (const [name, url] of Object.entries(PAGES)) {
      const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 } });
      const page = await ctx.newPage();
      const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
      page.on('console', (m) => { if (m.type() === 'error' && !/404/.test(m.text())) errors.push(m.text()); });
      const resp = await page.goto(BASE + url, { waitUntil: 'networkidle' });
      const m = await page.evaluate(() => {
        const c = document.querySelector('.crumb, .brw-crumb, .rtn-crumb');
        if (!c || !c.getBoundingClientRect().height) return { shown: false, docScrollWidth: document.documentElement.scrollWidth };
        const lines = new Set([...c.querySelectorAll('a')].map((a) => Math.round(a.getBoundingClientRect().top)));
        const links = [...c.querySelectorAll('a')].map((a) => { const b = a.getBoundingClientRect(); const hit = document.elementFromPoint(b.left + Math.min(b.width / 2, 15), b.top + b.height / 2); return !!hit && (hit === a || a.contains(hit)); });
        return { shown: true, height: Math.round(c.getBoundingClientRect().height), linkRows: lines.size, allClickable: links.every(Boolean), links: links.length, clipped: c.scrollWidth > c.clientWidth, docScrollWidth: document.documentElement.scrollWidth };
      });
      rows.push({ w, name, status: resp.status(), ...m, errors });
      if (name !== 'product') await page.screenshot({ path: `${OUT}/${TAG}-${name}-${w}.png`, clip: { x: 0, y: 0, width: w, height: 330 } });
      await ctx.close();
    }
  }
  for (const r of rows) console.log(JSON.stringify(r));
  await browser.close();
})();
