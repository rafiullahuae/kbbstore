/* Lane QK9 evidence: the payment card and the Place order block, merged into
   one card on the phone; the reviews card gone. EN and AR, 320 / 390 / 1280.
   BASE=http://127.0.0.1:PORT OUT=dir TAG=before|after node tools/qk9-shots.cjs
   getBoundingClientRect / getComputedStyle / elementFromPoint are this
   harness's instruments, never shipped to a shopper. */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT, TAG = process.env.TAG || 'after';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
async function add(page) {
  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  const id = await page.evaluate(() => Number(document.querySelector('[data-kbb-add]').getAttribute('data-kbb-add')));
  await page.evaluate(async (pid) => {
    const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)'); const t = m ? decodeURIComponent(m.pop()) : '';
    await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': t }, body: JSON.stringify({ product_id: pid, quantity: 1 }) });
  }, id);
}
const measure = () => {
  const vis = (e) => e && e.getClientRects().length > 0 && e.getBoundingClientRect().height > 0;
  const R = (e) => { if (!vis(e)) return null; const r = e.getBoundingClientRect(); return { top: +(r.top + scrollY).toFixed(1), bottom: +(r.bottom + scrollY).toFixed(1), left: +r.left.toFixed(1), right: +r.right.toFixed(1), w: +r.width.toFixed(1) }; };
  const S = (e) => { if (!e) return null; const s = getComputedStyle(e); return { bg: s.backgroundColor, border: s.borderTopWidth + ' ' + s.borderTopColor + ' / b ' + s.borderBottomWidth, radius: [s.borderTopLeftRadius, s.borderTopRightRadius, s.borderBottomRightRadius, s.borderBottomLeftRadius].join(' '), shadow: s.boxShadow.slice(0, 40) }; };
  const lis = [...document.querySelectorAll('#payment li.wc_payment_method')].filter(vis);
  const lastLi = lis[lis.length - 1];
  const places = [...document.querySelectorAll('.kbb-checkout .place')].filter(vis);
  const place = places[0];
  const form = document.querySelector('#customer_details');
  const mo = document.querySelector('.kbb-mobile-order');
  const sep = document.querySelector('.co-merge-sep, .kbb-mobile-order');
  return {
    reassure: R(document.querySelector('.kbb-reassure')), reassureText: (document.querySelector('.kbb-reassure') || {}).innerText || null,
    formbox: R(form), formboxStyle: S(form), mobileOrder: R(mo), mobileOrderStyle: S(mo),
    gapFormToOrder: vis(mo) ? +(mo.getBoundingClientRect().top - form.getBoundingClientRect().bottom).toFixed(1) : null,
    lastMethodBottom: R(lastLi) && R(lastLi).bottom, placeTop: R(place) && R(place).top, placeIn: place ? (place.closest('.kbb-mobile-order') ? 'mobile-order' : place.closest('.summary') ? 'summary' : '?') : null,
    METHOD_TO_PLACE: lastLi && place ? +(place.getBoundingClientRect().top - lastLi.getBoundingClientRect().bottom).toFixed(1) : null,
    trust: (document.querySelector('.kbb-mobile-order .trust') || {}).innerText || null,
    scrollWidth: document.documentElement.scrollWidth,
  };
};
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const lang of ['', '/ar']) for (const w of [320, 390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: w < 600, userAgent: w < 600 ? IPHONE : DESKTOP });
    const page = await ctx.newPage(); const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); }); page.on('pageerror', (e) => errors.push(String(e)));
    await add(page);
    const L = lang ? 'ar' : 'en';
    await page.goto(BASE + lang + '/checkout/', { waitUntil: 'networkidle' });
    const m = await page.evaluate(measure);
    out.push({ L, w, ...m, errors: [...errors] });
    // the region the owner photographed: from "4 Payment" down to the footer
    const y0 = await page.evaluate(() => document.querySelector('.sec.pay').getBoundingClientRect().top + scrollY - 12);
    const y1 = await page.evaluate(() => { const f = document.querySelector('.kbb-slimfoot, footer'); return f ? f.getBoundingClientRect().bottom + scrollY : document.documentElement.scrollHeight; });
    await page.screenshot({ path: `${OUT}/pay-to-foot-${L}-${w}-${TAG}.png`, fullPage: true, clip: { x: 0, y: y0, width: w, height: Math.min(y1 - y0, 2400) } });
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
