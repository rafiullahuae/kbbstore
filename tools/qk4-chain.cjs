// Lane QK4: the trailing-space chain under <main> (each last child down to the
// painted bottom), so the gap above the footer can be attributed. Measurement only.
const { chromium } = require('playwright');
const BASE = process.env.QK4_BASE;
const PAGES = (process.env.QK4_PAGES || '/,/shop/,/brands/anua/,/product/1025-dokdo-toner/,/blog/,/blog/double-cleansing-guide/,/about/,/my-account/').split(',');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await b.newContext({ viewport: { width: +(process.env.QK4_W || 1280), height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' })).newPage();
  for (const u of PAGES) {
    await page.goto(BASE + u, { waitUntil: 'load' });
    const r = await page.evaluate(() => {
      const f = document.querySelector('footer.kft, footer.kbb-slimfoot, body > footer'); if (!f) return 'NO FOOTER'; const ft = f.getBoundingClientRect().top;
      const rows = []; let el = document.querySelector('main#content');
      while (el) {
        const kids = [...el.children].filter(k => { const s = getComputedStyle(k); return s.display !== 'none' && k.getBoundingClientRect().height > 0 && s.position !== 'absolute' && s.position !== 'fixed'; });
        const s = getComputedStyle(el); const rc = el.getBoundingClientRect();
        rows.push(`${el.tagName}.${[...el.classList].join('.').slice(0,50)} pb=${s.paddingBottom} mb=${s.marginBottom} bg=${s.backgroundColor!=='rgba(0, 0, 0, 0)'||s.backgroundImage!=='none'?'Y':'-'} toFooter=${Math.round(ft-rc.bottom)}`);
        el = kids.pop();
      }
      return rows.join('\n   ');
    });
    console.log(u + '\n   ' + r);
  }
  await b.close();
})();
