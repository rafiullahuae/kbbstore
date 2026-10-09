/* Lane QK8: resting floating-label centring, every place the component is used.
   BASE=http://127.0.0.1:PORT TAG=before|after OUT=dir node tools/qk8-fields.cjs
   For every EMPTY, UNFOCUSED field: label centre Y minus control centre Y, and
   the icon's centre minus the control's; plus the control height (must not move).
   getBoundingClientRect is this harness's instrument, never shipped. */
const { chromium } = require('playwright');
const BASE = process.env.BASE, TAG = process.env.TAG || 'x', OUT = process.env.OUT;
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const measure = () => [...document.querySelectorAll('.fld')].filter((f) => {
  const r = f.getBoundingClientRect(); return r.height > 0 && getComputedStyle(f).visibility !== 'hidden' && f.querySelector(':scope > label');
}).map((f) => {
  const c = f.querySelector('input:not([type=hidden]),select,textarea'); const l = f.querySelector(':scope > label'); const i = f.querySelector('.lead');
  if (!c) return null;
  const cr = c.getBoundingClientRect(), lr = l.getBoundingClientRect(), ir = i ? i.getBoundingClientRect() : null;
  const mid = (r) => r.top + r.height / 2;
  const ctx = f.closest('.ctc-form') ? 'contact' : f.closest('.kbb-cartpage') ? 'cart' : f.closest('.kbb-checkout') ? 'checkout' : 'account';
  return { ctx, id: c.id || c.name, tag: c.tagName.toLowerCase(), label: l.textContent.trim().slice(0, 28), h: +cr.height.toFixed(2), fldH: +f.getBoundingClientRect().height.toFixed(2),
    dLabel: +(mid(lr) - mid(cr)).toFixed(2), dIcon: ir ? +(mid(ir) - mid(cr)).toFixed(2) : null, empty: c.value === '' || (c.tagName === 'SELECT' && c.value === '') };
}).filter((x) => x && x.empty);
async function add(page) {
  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  const id = await page.evaluate(() => Number(document.querySelector('[data-kbb-add]').getAttribute('data-kbb-add')));
  await page.evaluate(async (pid) => {
    const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)'); const t = m ? decodeURIComponent(m.pop()) : '';
    await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': t }, body: JSON.stringify({ product_id: pid, quantity: 1 }) });
  }, id);
}
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2, isMobile: w === 390, hasTouch: w === 390, userAgent: w === 390 ? IPHONE : DESKTOP });
    const page = await ctx.newPage();
    await add(page);
    for (const u of ['/checkout/', '/cart/', '/contact-us/', '/my-account/']) {
      await page.goto(BASE + u, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.activeElement && document.activeElement.blur());
      for (const r of await page.evaluate(measure)) rows.push({ w, page: u, ...r });
      if (OUT && w === 390 && (u === '/checkout/' || u === '/contact-us/')) {
        const el = await page.$(u === '/checkout/' ? '#customer_details' : '.ctc-form');
        if (el) await el.screenshot({ path: `${OUT}/fields-${u.replace(/\W/g, '')}-${TAG}-390.png` });
      }
    }
    await ctx.close();
  }
  console.log(JSON.stringify(rows));
  await b.close();
})();
