/*
 * 2.60.512: the coupon ticket (design A), photographed and measured.
 *
 *   sh tools/c512-preview.sh 11950
 *   node tools/c512-shots.cjs http://127.0.0.1:11950
 *
 * For English and /ar/, at 320 / 360 / 390 / 414 / 1280: the cart page (under
 * "Your Bag"), the cart panel and the checkout. Each ticket is measured for
 * ONE line (no ellipsis: the sentence's scrollWidth fits its box), the page's
 * scrollWidth, a REAL click on the code (copy on the cart page and panel,
 * apply on the checkout), the green tick after it, and the row's box before
 * and after (nothing moves); "Copied" shows in the row for a moment on the
 * cart page and panel, the green tick only on the checkout. Pictures at 390 and 1280 into docs/lane-c512-shots.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:11950';
const OUT = path.join(__dirname, '..', 'docs', 'lane-c512-shots');
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const WIDTHS = (process.env.C512_WIDTHS || '320,360,390,414,1280').split(',').map(Number);
const SHOOT = new Set([390, 1280]);

fs.mkdirSync(OUT, { recursive: true });

const measure = (page, sel, textSel) => page.evaluate(([s, t]) => {
  const el = document.querySelector(s);
  if (!el) return null;
  const r = el.getBoundingClientRect();
  const tx = el.querySelector(t);
  const pill = el.querySelector('.kc-cc, .co-cl-code');
  const pr = pill.getBoundingClientRect();
  const hit = document.elementFromPoint(pr.x + pr.width / 2, pr.y + pr.height / 2);
  const cs = getComputedStyle(el);
  return {
    x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height),
    fontSize: cs.fontSize,
    oneLine: tx ? tx.scrollWidth <= tx.clientWidth + 1 : null,
    textW: tx ? [tx.scrollWidth, tx.clientWidth] : null,
    text: el.innerText.replace(/\s+/g, ' ').trim(),
    pillHit: !!hit && (hit === pill || pill.contains(hit)),
    scrollWidth: document.documentElement.scrollWidth,
  };
}, [sel, textSel]);

async function context(browser, width, locale) {
  const mobile = width < 900;
  const ctx = await browser.newContext({
    viewport: { width, height: mobile ? 844 : 860 }, deviceScaleFactor: mobile ? 2 : 1,
    isMobile: mobile, hasTouch: mobile, userAgent: mobile ? IPHONE : MAC,
    permissions: ['clipboard-read', 'clipboard-write'],
  });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('response', (rs) => { if (rs.status() >= 400) errors.push(rs.status() + ' ' + new URL(rs.url()).pathname); });
  for (let i = 0; i < 4; i++) {
    await page.goto(BASE + '/shop/', { waitUntil: 'domcontentloaded' });
    const n = await page.evaluate(async () => {
      let ok = 0;
      for (const id of [2, 3, 4]) {
        const r = await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
        if (r.ok) ok++;
      }
      return ok;
    });
    if (n === 3) break;
    await ctx.clearCookies();
  }
  return { ctx, page, errors };
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};

  for (const locale of (process.env.C512_LOCALES || 'en,/ar').split(',').map((l) => (l === 'en' ? '' : l))) {
    const L = locale ? 'ar' : 'en';
    for (const width of WIDTHS) {
      const r = report[`${L}-${width}`] = {};
      const { ctx, page, errors } = await context(browser, width, locale);
      const shot = async (name, clip) => { if (SHOOT.has(width)) await page.screenshot({ path: path.join(OUT, `${L}-${width}-${name}.png`), clip }); };

      // ── the cart page, under "Your Bag" ──
      await page.goto(BASE + locale + '/cart/', { waitUntil: 'networkidle' });
      r.cart = await measure(page, '#cartInner > .kc-cch', '.kc-cch-t');
      const top = await page.evaluate(() => Math.max(0, document.querySelector('#cartLead').getBoundingClientRect().y + scrollY - 40));
      const vw = page.viewportSize();
      await shot('cart', { x: 0, y: top, width: vw.width, height: Math.min(360, vw.height) });
      await page.click('#cartInner > .kc-cch .kc-cc');
      await page.waitForTimeout(400);
      r.cartAfter = await measure(page, '#cartInner > .kc-cch', '.kc-cch-t');
      r.cartCopied = await page.evaluate(() => getComputedStyle(document.querySelector('#cartInner > .kc-cch'), '::after').content);
      r.clipboard = await page.evaluate(() => navigator.clipboard.readText().catch((e) => 'ERR ' + e));
      await shot('cart-copied', { x: 0, y: top, width: vw.width, height: Math.min(360, vw.height) });

      // ── the cart panel, above Subtotal ──
      await page.goto(BASE + locale + '/product/glow-deep-serum-rice-alpha-arbutin/', { waitUntil: 'networkidle' });
      await page.click('[data-kbb-open="cart"]');
      await page.waitForSelector('#kcCart .kc-cch', { state: 'visible' });
      await page.waitForTimeout(500);
      r.panel = await measure(page, '#kcCart .kc-cch', '.kc-cch-t');
      await shot('panel');
      await page.click('#kcCart .kc-cch .kc-cc');
      await page.waitForTimeout(400);
      r.panelAfter = await measure(page, '#kcCart .kc-cch', '.kc-cch-t');
      r.panelCopied = await page.evaluate(() => getComputedStyle(document.querySelector('#kcCart .kc-cch'), '::after').content);
      await shot('panel-copied');

      // ── the checkout: tap APPLIES ──
      await page.goto(BASE + locale + '/checkout/', { waitUntil: 'networkidle' });
      r.checkout = await measure(page, '#kbbCline', '.co-cl-t');
      const ctop = await page.evaluate(() => Math.max(0, document.querySelector('#kbbCline').getBoundingClientRect().y + scrollY - 20));
      await shot('checkout', { x: 0, y: ctop, width: vw.width, height: Math.min(420, vw.height) });
      await page.click('#kbbCline .co-cl-code');
      await page.waitForFunction(() => document.getElementById('kbbCline').classList.contains('is-done'), null, { timeout: 8000 }).catch(() => {});
      await page.waitForTimeout(300);
      r.checkoutAfter = await measure(page, '#kbbCline', '.co-cl-t');
      r.checkoutApplied = await page.evaluate(() => ({
        done: document.getElementById('kbbCline').classList.contains('is-done'),
        tick: getComputedStyle(document.querySelector('#kbbCline .co-cl-ok')).visibility,
        discountLine: !!document.querySelector('[data-kbb-coupon-remove]'),
      }));
      await shot('checkout-applied', { x: 0, y: ctop, width: vw.width, height: Math.min(420, vw.height) });

      r.errors = errors.filter((e) => !/ERR_TUNNEL|fonts\.g|net::ERR/.test(e));
      await ctx.close();
      console.log(L, width, JSON.stringify({ cart: r.cart && [r.cart.w, r.cart.h, r.cart.oneLine, r.cart.fontSize], panel: r.panel && [r.panel.w, r.panel.h, r.panel.oneLine, r.panel.fontSize], co: r.checkout && [r.checkout.w, r.checkout.h, r.checkout.oneLine, r.checkout.fontSize], applied: r.checkoutApplied, copied: [r.cartCopied, r.panelCopied], clip: r.clipboard, errs: r.errors.length }));
    }
  }

  fs.writeFileSync(path.join(OUT, process.env.C512_REPORT || 'report.json'), JSON.stringify(report, null, 2));
  await browser.close();
})();
