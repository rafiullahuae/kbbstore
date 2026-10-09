// Lane QK4: the elements that end just above the footer and the space each
// leaves, walking up from the footer's previous sibling. Measurement only.
const { chromium } = require('playwright');
const BASE = process.env.QK4_BASE, U = process.env.QK4_U || '/checkout/';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const page = await (await b.newContext({ viewport: { width: w, height: 900 }, userAgent: UA })).newPage();
    await page.goto(BASE + '/product/1025-dokdo-toner/', { waitUntil: 'load' });
    await page.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 1, quantity: 1 }) }); });
    await page.goto(BASE + U, { waitUntil: 'load' });
    console.log(w, U, await page.evaluate(() => {
      const f = document.querySelector('footer.kft, footer.kbb-slimfoot'); const ft = f.getBoundingClientRect().top; const out = [];
      const desc = (e) => { const s = getComputedStyle(e), r = e.getBoundingClientRect(); return `${e.tagName}.${[...e.classList].join('.').slice(0, 40)} pb=${s.paddingBottom} mb=${s.marginBottom} pos=${s.position} disp=${s.display} toF=${Math.round(ft - r.bottom)} bg=${s.backgroundColor !== 'rgba(0, 0, 0, 0)' ? 'Y' : '-'}`; };
      out.push('parent ' + desc(f.parentElement));
      let e = f.previousElementSibling; let n = 0;
      while (e && n < 6) { out.push('prev ' + desc(e)); e = e.previousElementSibling; n++; }
      return '\n  ' + out.join('\n  ');
    }));
  }
  await b.close();
})();
