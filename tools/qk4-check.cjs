// Lane QK4: CLS, console errors, and first-try clicks on the last card's button
// and the footer's first buttons, per page type at 390 and 1280. Measurement only.
const { chromium } = require('playwright');
const BASE = process.env.QK4_BASE;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const PAGES = ['/', '/product-category/cleansing-oils/', '/brands/anua/', '/product/1025-dokdo-toner/', '/blog/', '/about/'];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 900 }, userAgent: UA });
    await ctx.addInitScript(() => { window.__cls = 0; new PerformanceObserver(l => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); });
    const page = await ctx.newPage(); const errs = [];
    page.on('pageerror', e => errs.push(String(e))); page.on('console', m => { if (m.type() === 'error' && !/google|gtag|facebook/.test(m.text())) errs.push(m.text()); });
    for (const u of PAGES) {
      errs.length = 0;
      await page.goto(BASE + u, { waitUntil: 'networkidle' });
      await page.evaluate(() => window.scrollTo(0, document.querySelector('footer').getBoundingClientRect().top + scrollY - 450));
      await page.waitForTimeout(500);
      const r = await page.evaluate(() => {
        const hit = (el) => { if (!el) return null; el.scrollIntoView({ block: 'center' }); const rc = el.getBoundingClientRect(); const h = document.elementFromPoint(rc.left + rc.width / 2, rc.top + rc.height / 2); return !!h && (h === el || el.contains(h)); };
        const f = document.querySelector('footer');
        const cards = [...document.querySelectorAll('#content a, #content button')].filter(a => a.getBoundingClientRect().height > 0 && getComputedStyle(a).visibility !== 'hidden');
        const last = cards.filter(a => a.getBoundingClientRect().bottom + scrollY <= f.getBoundingClientRect().top + scrollY).sort((a, b2) => b2.getBoundingClientRect().bottom - a.getBoundingClientRect().bottom)[0];
        return { cls: Math.round(window.__cls * 1000) / 1000, lastLink: hit(last), footerFirst: hit(f.querySelector('a, button')), sw: document.documentElement.scrollWidth };
      });
      console.log(JSON.stringify({ w, u, ...r, errors: errs.length }));
    }
    await ctx.close();
  }
  await b.close();
})();
