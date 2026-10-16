/*
 * Product page delivery box, photographed and measured on /ar and in English:
 * do "FAST"/"DELIVERY" sit clear of the truck, and which way does the arrow
 * point? Overlap = the words' box intersecting the truck body's box.
 *   node tools/ptd-shots.cjs <port> <slug> <outdir> <tag>
 */
const { chromium } = require('playwright');
(async () => {
  const [port, slug, out, tag] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const lang of ['ar', 'en']) for (const w of [390, 1280]) {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    const errs = []; p.on('pageerror', e => errs.push(String(e)));
    await p.goto(`http://127.0.0.1:${port}${lang === 'ar' ? '/ar' : ''}/product/${slug}/`, { waitUntil: 'load' });
    const box = p.locator('.pts-del').first();
    await box.scrollIntoViewIfNeeded();
    const m = await p.evaluate(() => {
      const svg = document.querySelector('.pts-del .pts-truck'); if (!svg) return { svg: false };
      const R = e => e.getBoundingClientRect();
      const [fast, del] = svg.querySelectorAll('text');
      const body = svg.querySelector('g rect:nth-of-type(4)'); // the truck's box
      const head = svg.querySelector('path[d^="M105.5"]');     // the arrow's head
      const rs = R(svg), rb = R(body), rf = R(fast), rd = R(del), rh = R(head);
      const ov = (a, c) => Math.max(0, Math.min(a.right, c.right) - Math.max(a.left, c.left)) * Math.max(0, Math.min(a.bottom, c.bottom) - Math.max(a.top, c.top));
      return { svg: true, overlapFastTruckPx2: Math.round(ov(rf, rb)), overlapDeliveryTruckPx2: Math.round(ov(rd, rb)),
        arrowPoints: (rh.left + rh.right) / 2 > (rs.left + rs.right) / 2 ? 'right' : 'left',
        wordsSide: (rf.left + rf.right) / 2 > (rb.left + rb.right) / 2 ? 'right of truck' : 'left of truck',
        page: document.documentElement.scrollWidth };
    });
    await box.screenshot({ path: `${out}/${tag}-${lang}-${w}-delivery.png` });
    console.log(tag, lang, w, JSON.stringify(m), errs.length ? errs : '');
    await p.close();
  }
  await b.close();
})();
