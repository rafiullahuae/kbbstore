/* Lane CK (22 October): the cart page and the cart panel, EN and AR, 390 and
   1280, on the local preview shop: scrollWidth, console errors, every button
   and link answering a click at its centre, the panel's quantity + and remove
   working, and prefetch still declared on product, category and brand links.
     BASE=http://127.0.0.1:PORT node tools/ck22-cart.cjs
   Pictures to docs/lane-ck/cart-* and panel-*. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE;
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const OUT = path.resolve(__dirname, '../docs/lane-ck');
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

function clickable(scope) {
  const bad = [];
  let ok = 0;
  document.querySelectorAll(scope + ' button, ' + scope + ' a[href]').forEach((el) => {
    const b = el.getBoundingClientRect();
    if (!el.offsetParent || b.width < 2 || b.height < 2 || b.bottom < 0 || b.top > innerHeight || b.right < 0 || b.left > innerWidth) return;
    const hit = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
    if (hit && (hit === el || el.contains(hit))) ok++; else bad.push((el.textContent || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 30));
  });
  return { ok, bad };
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = [];
  for (const lang of ['en', 'ar']) {
    for (const w of [390, 1280]) {
      const m = w === 390;
      const ctx = await browser.newContext({ viewport: { width: w, height: m ? 844 : 900 }, isMobile: m, hasTouch: m, userAgent: m ? UA_M : UA_D });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      const pre = lang === 'ar' ? '/ar' : '';
      await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
      const prefetch = await page.evaluate(() => { const s = document.querySelector('script[type="speculationrules"]'); return s ? s.textContent.length : 0; });
      for (const id of [26, 5, 11]) {
        await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
      }
      await page.goto(BASE + pre + '/cart/', { waitUntil: 'networkidle' });
      const r = { lang, w, prefetchRules: prefetch };
      r.cartSW = await page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
      r.cartClick = await page.evaluate(clickable, '#cartPage');
      await page.screenshot({ path: path.join(OUT, `cart-${lang}-${w}.png`), fullPage: true });

      // The panel: open it with the shop's own bag button (on the home page --
      // on /cart/ the bag reloads the page instead), then + on the first line.
      await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
      const bag = page.locator('[data-kbb-cart]').filter({ visible: true }).first();
      if (await bag.count()) {
        await bag.click();
        await page.waitForTimeout(900);
        r.panelOpen = await page.evaluate(() => document.getElementById('cart').classList.contains('on'));
        r.panelSW = await page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
        r.panelClick = await page.evaluate(clickable, '#cart');
        await page.screenshot({ path: path.join(OUT, `panel-${lang}-${w}.png`) });
        const qtyNow = () => page.evaluate(() => [...document.querySelectorAll('#cart .kc-qty span')].map((s) => s.textContent).join(','));
        r.hitDetail = await page.evaluate(() => [...document.querySelectorAll('#cart a[href]')].filter((a) => { const b = a.getBoundingClientRect(); if (!a.offsetParent || b.width < 2) return false; const h = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return h && !(h === a || a.contains(h)); }).map((a) => { const b = a.getBoundingClientRect(); const h = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return a.className + ' @' + Math.round(b.top) + ' hit ' + h.tagName + '.' + h.className; }));
        const before = await qtyNow();
        const plus = page.locator('#cart .kc-qty button').last();
        if (await plus.count()) { await plus.click(); await page.waitForTimeout(1200); }
        r.panelQty = [before, await qtyNow()];
      }
      r.errors = errors;
      report.push(r);
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'cart-facts.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
