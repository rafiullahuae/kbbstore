/* Lane QK8 evidence: the checkout's payment boxes (no tint, grey until chosen),
   the "← Checkout" heading at the top with its back arrow, the WhatsApp button
   gone from cart and checkout, the slim footer's Privacy policy, the Delivery
   note and the Remember line. EN and AR, 320 / 390 / 1280.
   BASE=http://127.0.0.1:PORT OUT=dir TAG=after node tools/qk8-shots.cjs
   getBoundingClientRect / getComputedStyle / elementFromPoint are this
   harness's instruments, never shipped to a shopper. */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT, TAG = process.env.TAG || 'after';
const ONLY = process.env.ONLY || '';
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
const boxes = () => [...document.querySelectorAll('#payment li.wc_payment_method')].map((li) => {
  const s = getComputedStyle(li);
  return { id: li.className.match(/payment_method_(\w+)/)[1], checked: li.querySelector('input').checked, bg: s.backgroundColor, bgImage: s.backgroundImage.slice(0, 60), border: s.borderTopColor, bw: s.borderTopWidth, radius: s.borderTopLeftRadius };
});
const top = () => {
  const r = (e) => e && e.getBoundingClientRect();
  const head = r(document.querySelector('.co-head')), tb = r(document.querySelector('.co-titlebar')), h1 = r(document.querySelector('h1.co-h'));
  const back = document.querySelector('.co-back'), lead = r(document.querySelector('.co-lead'));
  const br = r(back); const order = [...document.querySelectorAll('.co-grid > *')].filter((e) => r(e).height > 0).sort((a, b) => r(a).top - r(b).top).map((e) => e.className.split(' ')[0] || e.tagName).slice(0, 4);
  const hit = br ? document.elementFromPoint(br.left + br.width / 2, br.top + br.height / 2) : null;
  const bs = back ? getComputedStyle(back) : null;
  return { headBottom: head.bottom, gapHeaderToTitle: +(Math.min(tb.top, br ? br.top : 1e9, h1.top) - head.bottom).toFixed(2), titlebarTop: +(tb.top - head.bottom).toFixed(2),
    back: br ? { x: +br.left.toFixed(1), w: br.width, h: br.height, centreY: +(br.top + br.height / 2).toFixed(2), bg: bs.backgroundColor, border: bs.borderTopColor + ' ' + bs.borderTopWidth, href: back.getAttribute('href'), aria: back.getAttribute('aria-label'), hitIsBack: !!hit && back.contains(hit) } : null,
    h1: { x: +h1.left.toFixed(1), right: +h1.right.toFixed(1), centreY: +(h1.top + h1.height / 2).toFixed(2) }, leadX: lead ? +lead.left.toFixed(1) : null, leadRight: lead ? +lead.right.toFixed(1) : null,
    gapBackToH1: br ? +((document.dir === 'rtl' ? br.left - h1.right : h1.left - br.right)).toFixed(1) : null,
    order, scrollWidth: document.documentElement.scrollWidth,
    wa: document.querySelectorAll('#kbbWa,.kbw,.kbt-z,#kba-wa,style#kbb-wa').length,
    dnote: (document.querySelector('#kbbDeliveryNote') || {}).textContent || null,
    remember: ((document.querySelector('.kbb-remember, #kbbRemember, [for=kbb_remember]') || {}).textContent || '').trim().replace(/\s+/g, ' ') || null,
    foot: [...document.querySelectorAll('.kbb-slimfoot a')].map((a) => a.textContent.trim() + ' -> ' + a.getAttribute('href')) };
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
    const t = await page.evaluate(top);
    out.push({ L, w, at: 'checkout top', ...t, errors: [...errors] });
    await page.screenshot({ path: `${OUT}/top-${L}-${w}-${TAG}.png`, clip: { x: 0, y: 0, width: w, height: w < 600 ? 330 : 300 } });
    if (w === 390) {
      const br = await page.$('.co-back');
      if (br) { const bb = await br.boundingBox(); await page.screenshot({ path: `${OUT}/back-closeup-${L}-390-${TAG}.png`, clip: { x: 0, y: Math.max(0, bb.y - 20), width: 260, height: bb.height + 40 } }); }
    }
    if (!ONLY) {
      // payment boxes: card, then Tabby, then COD
      for (const id of ['stripe', 'tabby', 'cod']) {
        const lab = page.locator(`label[for="payment_method_${id}"]`);
        await lab.scrollIntoViewIfNeeded();
        const bb = await lab.boundingBox();
        const hit = await page.evaluate(([x, y, id]) => { const e = document.elementFromPoint(x, y); return !!e && !!e.closest('li.payment_method_' + id); }, [bb.x + 30, bb.y + bb.height / 2, id]);
        await lab.click();
        await page.waitForTimeout(250);
        const st = await page.evaluate(boxes);
        out.push({ L, w, at: 'payment ' + id + ' selected', firstTapHit: hit, selectedNow: st.find((x) => x.checked)?.id, boxes: st });
        const pay = await page.$('#payment');
        await pay.screenshot({ path: `${OUT}/pay-${id}-${L}-${w}-${TAG}.png` });
      }
      // hover + keyboard focus on an unselected box
      if (w === 1280 && !lang) {
        await page.locator('label[for="payment_method_tamara"]').hover(); await page.waitForTimeout(200);
        out.push({ L, w, at: 'hover tamara', boxes: (await page.evaluate(boxes)).filter((x) => x.id === 'tamara') });
        await page.focus('#payment_method_cod'); await page.keyboard.press('ArrowUp'); await page.waitForTimeout(200);
        out.push({ L, w, at: 'keyboard ArrowUp from cod', focusOutline: await page.evaluate(() => { const li = document.activeElement.closest('li'); const s = getComputedStyle(li); return { id: li.className.match(/payment_method_(\w+)/)[1], outline: s.outlineStyle + ' ' + s.outlineWidth + ' ' + s.outlineColor }; }) });
        await (await page.$('#payment')).screenshot({ path: `${OUT}/pay-focus-${L}-${w}-${TAG}.png` });
      }
      // full page + footer
      await page.screenshot({ path: `${OUT}/checkout-full-${L}-${w}-${TAG}.png`, fullPage: true });
      if (w === 390) { const f = await page.$('.kbb-slimfoot'); if (f) await f.screenshot({ path: `${OUT}/footer-checkout-${L}-390-${TAG}.png` }); }
      // the back arrow navigates on the first tap
      if (await page.$('.co-back')) {
        await page.evaluate(() => scrollTo(0, 0));
        await Promise.all([page.waitForURL('**/cart/', { timeout: 8000 }).catch(() => null), w < 600 ? page.tap('.co-back') : page.click('.co-back')]);
        out.push({ L, w, at: 'after one tap on the back arrow', url: page.url().replace(BASE, '') });
      }
      // cart
      await page.goto(BASE + lang + '/cart/', { waitUntil: 'networkidle' });
      out.push({ L, w, at: 'cart', wa: await page.evaluate(() => document.querySelectorAll('#kbbWa,.kbw,.kbt-z,style#kbb-wa').length), foot: await page.evaluate(() => [...document.querySelectorAll('.kbb-slimfoot a')].map((a) => a.textContent.trim() + ' -> ' + a.getAttribute('href'))), scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) });
      await page.screenshot({ path: `${OUT}/cart-${L}-${w}-${TAG}.png`, fullPage: w < 600 });
      // home + a product still have it
      await page.goto(BASE + lang + '/', { waitUntil: 'networkidle' });
      out.push({ L, w, at: 'home', wa: await page.evaluate(() => document.querySelectorAll('#kbbWa').length) });
    }
    out.push({ L, w, at: 'console errors', errors });
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
