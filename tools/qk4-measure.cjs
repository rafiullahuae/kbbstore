/*
 * Lane QK4: the visible gap between a page's last painted content and the top
 * of its footer, per page type, at 390 and 1280. Measurement only (Chromium).
 * "Painted" = a text line, an image/svg/control, or a box with a background
 * or border; fixed and absolutely positioned layers are skipped.
 *   QK4_BASE=http://127.0.0.1:10842 [QK4_PAGES=/,/shop/] node tools/qk4-measure.cjs
 */
const { chromium } = require('playwright');
const BASE = process.env.QK4_BASE;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const PAGES = (process.env.QK4_PAGES || '/,/shop/,/product-category/cleansing-oils/,/brands/,/brands/anua/,/product/1025-dokdo-toner/,/blog/,/blog/double-cleansing-guide/,/about/,/my-account/,/search/?q=toner,/no-such-page/,/checkout/').split(',');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: 900 }, userAgent: UA });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text().slice(0, 120)); });
    page.on('pageerror', e => errors.push(String(e).slice(0, 120)));
    // one product in the cart, so /checkout/ draws its page and its slim footer
    await page.goto(BASE + '/product/1025-dokdo-toner/', { waitUntil: 'load' });
    await page.evaluate(async () => {
      const id = document.querySelector('[data-product-id]')?.getAttribute('data-product-id') || 1;
      await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
        body: JSON.stringify({ product_id: +id, quantity: 1 }) });
    });
    for (const u of PAGES) {
      errors.length = 0;
      await page.goto(BASE + u, { waitUntil: 'load' });
      await page.waitForTimeout(250);
      const r = await page.evaluate(() => {
        const f = document.querySelector('footer.kft, footer.kbb-slimfoot, body > footer');
        if (!f) return { footer: null };
        const ft = f.getBoundingClientRect().top + scrollY;
        const skip = (el) => { for (let e = el; e && e !== document.body; e = e.parentElement) { const s = getComputedStyle(e); if (s.position === 'fixed' || s.position === 'absolute' || s.display === 'none' || s.visibility === 'hidden' || s.opacity === '0') return true; if (e === f || f.contains(e)) return true; } return false; };
        let low = -1e9, what = '';
        // a line or box inside an overflow-clipped ancestor is only painted down to that ancestor's edge
        const clip = (el, bottom) => { for (let e = el.parentElement; e && e !== document.body; e = e.parentElement) { const s = getComputedStyle(e); if (s.overflowY !== 'visible') bottom = Math.min(bottom, e.getBoundingClientRect().bottom + scrollY); } return bottom; };
        const consider = (bottom0, el) => { const bottom = clip(el, bottom0); if (bottom <= ft + 0.5 && bottom > low) { low = bottom; what = (el.tagName + '.' + [...(el.classList || [])].join('.')).slice(0, 48); } };
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
        for (let n = walker.nextNode(); n; n = walker.nextNode()) {
          if (n.nodeType === 3) {
            if (!n.textContent.trim() || skip(n.parentElement)) continue;
            const rg = document.createRange(); rg.selectNodeContents(n);
            for (const rc of rg.getClientRects()) if (rc.height > 0) consider(rc.bottom + scrollY, n.parentElement);
            continue;
          }
          const s = getComputedStyle(n); const rc = n.getBoundingClientRect();
          if (rc.height === 0 || rc.width === 0) continue;
          const painted = /^(IMG|SVG|VIDEO|PICTURE|INPUT|SELECT|TEXTAREA|CANVAS|IFRAME|svg)$/.test(n.tagName) || s.backgroundColor !== 'rgba(0, 0, 0, 0)' || s.backgroundImage !== 'none' || parseFloat(s.borderBottomWidth) > 0;
          if (!painted || n === document.body || n.tagName === 'MAIN' || skip(n)) continue;
          consider(rc.bottom + scrollY, n);
        }
        return { footer: (f.className || 'footer').split(' ')[0], mt: getComputedStyle(f).marginTop, gap: Math.round((ft - low) * 10) / 10, above: what, sw: document.documentElement.scrollWidth };
      });
      rows.push({ w, u, ...r, errors: errors.length ? errors : undefined });
    }
    await ctx.close();
  }
  await b.close();
  for (const r of rows) console.log(JSON.stringify(r));
})();
