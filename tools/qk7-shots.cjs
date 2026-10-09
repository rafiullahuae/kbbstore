/* Lane QK7: the free-delivery bar on the cart page and the checkout, before
   (switch on -- what the live shop draws today) and after (off, as asked).
   BASE=http://127.0.0.1:PORT OUT=docs/lane-qk7-shots TAG=before|after node tools/qk7-shots.cjs
   One COSRX essence (AED 69): 2 of them is AED 138, under the AED 199 line;
   the "+" stepper takes it to 4, AED 276 (bundles or not), over it. */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE, OUT = process.env.OUT, TAG = process.env.TAG;
// The cart and checkout refuse a HeadlessChrome user agent ("This looks like an
// automated request"), so the shots wear the browsers the owner uses.
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

const measure = (scope) => (sel) => {
  const root = document.querySelector(sel) || document;
  const vis = (e) => { const s = getComputedStyle(e); const r = e.getBoundingClientRect(); return s.display !== 'none' && s.visibility !== 'hidden' && r.height > 0; };
  const bars = [...root.querySelectorAll('.freebar, .ship, .kc-ship')].filter((e) => !e.closest('#cart'));
  const txt = (root.innerText || '');
  return {
    scope: sel,
    barsInMarkup: bars.length,
    barsVisible: bars.filter(vis).length,
    saysUnlocked: /unlocked free delivery/i.test(txt),
    saysAway: /away from free delivery/i.test(txt),
    deliveryRow: [...document.querySelectorAll('.js-shipping')].filter(vis).map((e) => e.textContent.trim()).slice(0, 1)[0] || null,
    scrollWidth: document.documentElement.scrollWidth,
  };
};

async function addCosrx(page, qty) {
  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  const id = await page.evaluate(() => {
    const b = [...document.querySelectorAll('[data-kbb-add]')].find((x) => /Snail/i.test((x.closest('article, .card, li, .pc, div') || x).textContent));
    return b ? Number(b.getAttribute('data-kbb-add')) : null;
  });
  if (!id) throw new Error('no COSRX card on /shop/');
  const r = await page.evaluate(async ([pid, q]) => {
    const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)');
    const token = m ? decodeURIComponent(m.pop()) : '';
    const res = await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token }, body: JSON.stringify({ product_id: pid, quantity: q }) });
    return res.json();
  }, [id, qty]);
  return r.subtotal;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1, isMobile: w === 390, hasTouch: w === 390, userAgent: w === 390 ? IPHONE : DESKTOP });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));

    // ── CART PAGE: under, then "+" twice in the page (cart.js re-render) ──
    const sub = await addCosrx(page, 2);
    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    rows.push({ TAG, w, at: 'cart, 2 x AED 69 (under)', subtotal: sub, ...(await page.evaluate(measure(), '#cartInner')) });
    await page.screenshot({ path: `${OUT}/cart-${TAG}-${w}-under.png`, fullPage: true });
    for (let i = 0; i < 2; i++) {
      await Promise.all([
        page.waitForResponse((r) => r.url().includes('/api/cart/update')),
        page.locator('#cartInner button[data-kcpq][data-d="1"]').first().click(),
      ]);
      await page.waitForTimeout(400);
    }
    rows.push({ TAG, w, at: 'cart, after "+" x2 -> 4 (over)', ...(await page.evaluate(measure(), '#cartInner')) });
    await page.screenshot({ path: `${OUT}/cart-${TAG}-${w}-over.png`, fullPage: true });

    // ── CHECKOUT: over (4), summary opened; then "−" twice -> under (2) ──
    await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    if (await page.locator('#kbbSumRow').isVisible().catch(() => false)) { await page.click('#kbbSumRow'); await page.waitForTimeout(300); }
    rows.push({ TAG, w, at: 'checkout, 4 (over), summary open', ...(await page.evaluate(measure(), '.kbb-checkout')) });
    await page.screenshot({ path: `${OUT}/checkout-${TAG}-${w}-over.png`, fullPage: true });
    for (let i = 0; i < 2; i++) {
      await Promise.all([
        page.waitForResponse((r) => r.url().includes('/checkout/line')),
        page.locator('.co-q[data-d="-1"]:visible').first().click(),
      ]);
      await page.waitForTimeout(400);
    }
    rows.push({ TAG, w, at: 'checkout, after "−" x2 -> 2 (under)', ...(await page.evaluate(measure(), '.kbb-checkout')) });
    await page.screenshot({ path: `${OUT}/checkout-${TAG}-${w}-under.png`, fullPage: true });
    // ── THE DRAWER: unaffected, its bar still there. AFTER the checkout, because the panel's bar reads the basket's delivery country and a basket that has never seen the checkout has none (the same with the switch on or off) ──
    await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    await page.locator('[data-kbb-cart]:visible').first().click();
    await page.waitForTimeout(700);
    // The panel as the page first draws it comes from CartDrawerComposer, whose
    // totals carry no country and so no threshold -- pre-existing, and the same
    // with the switch on or off. Its own "+" re-renders it from /api/cart, which
    // does: that is the bar a shopper sees after touching the basket.
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/cart/update')),
      page.locator('#cart button[data-kcq][data-d="1"]').first().click(),
    ]);
    await page.waitForSelector('#cart .kc-ship', { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(700);
    const drawer = await page.evaluate(() => { const e = document.querySelector('#cart .kc-ship'); if (!e) return { drawerBar: false }; const r = e.getBoundingClientRect(); return { drawerBar: r.height > 0, drawerText: e.innerText.trim(), drawerFill: document.querySelector('#cart .kc-fill')?.style.width }; });
    rows.push({ TAG, w, at: 'cart panel (drawer), after its own "+": 3 in the bag', ...drawer });
    await page.screenshot({ path: `${OUT}/drawer-${TAG}-${w}.png` });

    rows.push({ TAG, w, consoleErrors: errors });
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/report-${TAG}.json`, JSON.stringify(rows, null, 2));
  console.log(JSON.stringify(rows, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
