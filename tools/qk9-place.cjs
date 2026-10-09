/* Lane QK9: a real tap on Place order in the merged card. Empty fields -> it
   scrolls to the first missing field; a payment box tap still selects; filled
   in with cash on delivery -> the order places. EN and AR at 320 / 390, and
   the merged card's geometry at a 768 tablet. Also a quantity change: the
   totals re-render inside the merged card. Harness instruments only.
   BASE=http://127.0.0.1:PORT OUT=dir node tools/qk9-place.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT;
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
async function add(page, n) {
  await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  const id = await page.evaluate(() => Number(document.querySelector('[data-kbb-add]').getAttribute('data-kbb-add')));
  await page.evaluate(async ([pid, n]) => {
    const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)'); const t = m ? decodeURIComponent(m.pop()) : '';
    await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': t }, body: JSON.stringify({ product_id: pid, quantity: n }) });
  }, [id, n || 1]);
}
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const lang of ['', '/ar']) for (const w of [320, 390, 768]) {
    const L = lang ? 'ar' : 'en';
    const ctx = await b.newContext({ viewport: { width: w, height: 844 }, deviceScaleFactor: 2, isMobile: w < 600, hasTouch: true, userAgent: IPHONE });
    const page = await ctx.newPage(); const errors = [];
    page.on('console', (m) => { if (m.type() === 'error' && !/CERT|404/.test(m.text())) errors.push(m.text()); }); page.on('pageerror', (e) => errors.push(String(e)));
    await add(page);
    await page.goto(BASE + lang + '/checkout/', { waitUntil: 'networkidle' });
    const r = { L, w };
    r.geom = await page.evaluate(() => { const f = document.querySelector('#customer_details').getBoundingClientRect(), m = document.querySelector('.kbb-mobile-order').getBoundingClientRect(); return { formL: f.left, formR: f.right, moL: m.left, moR: m.right, joinGap: +(m.top - f.bottom).toFixed(2) }; });
    // payment box tap (COD) selects
    const cod = page.locator('label[for="payment_method_cod"]');
    await cod.scrollIntoViewIfNeeded(); await cod.tap(); await page.waitForTimeout(200);
    r.codSelected = await page.evaluate(() => document.querySelector('#payment_method_cod').checked);
    // empty fields: a real tap on the merged card's Place order
    const btn = page.locator('.kbb-mobile-order button.place[data-place]');
    await btn.scrollIntoViewIfNeeded();
    const bb = await btn.boundingBox();
    r.placeHitTest = await page.evaluate(([x, y]) => !!document.elementFromPoint(x, y)?.closest('.kbb-mobile-order .place'), [bb.x + bb.width / 2, bb.y + bb.height / 2]);
    await btn.tap(); await page.waitForTimeout(900);
    r.emptyTap = await page.evaluate(() => { const a = document.activeElement; const rr = a.getBoundingClientRect(); return { url: location.pathname, focused: a.id || a.name || a.tagName, focusedInView: rr.top >= 0 && rr.bottom <= innerHeight, scrollY: Math.round(scrollY) }; });
    if (w === 390) await page.screenshot({ path: `${OUT}/empty-tap-${L}-390.png` });
    // quantity +1 re-renders totals inside the merged card
    const before = await page.evaluate(() => document.querySelector('.kbb-mobile-order .js-total, .kbb-mobile-order .sumrow.tot')?.textContent.replace(/\s+/g, ' ').trim());
    if (await page.locator('#kbbSumRow:visible').count()) { await page.locator('#kbbSumRow').scrollIntoViewIfNeeded(); await page.locator('#kbbSumRow').tap(); await page.waitForTimeout(400); }
    const plus = page.locator('.co-q[data-d="1"]:visible').first(); r.plusFound = await plus.count();
    if (await plus.count()) { await plus.scrollIntoViewIfNeeded(); await plus.tap(); await page.waitForTimeout(1500); }
    r.totalsLive = { before, after: await page.evaluate(() => document.querySelector('.kbb-mobile-order .js-total, .kbb-mobile-order .sumrow.tot')?.textContent.replace(/\s+/g, ' ').trim()),
      stillMerged: await page.evaluate(() => { const f = document.querySelector('#customer_details').getBoundingClientRect(), m = document.querySelector('.kbb-mobile-order').getBoundingClientRect(); return +(m.top - f.bottom).toFixed(2) === 0 && f.left === m.left && f.right === m.right; }) };
    // fill and place, cash on delivery
    await page.fill('#billing_email', `qk9${L}${w}@example.test`);
    await page.fill('#billing_first_name', 'Mariam Saleh');
    await page.fill('#billing_phone', '0501234567');
    await page.fill('#billing_address_1', 'Marina Gate 2, Apt 1804');
    if (await page.$('#billing_address_2')) await page.fill('#billing_address_2', 'Dubai Marina');
    if (await page.$('select#billing_state')) await page.selectOption('#billing_state', { index: 1 }); else if (await page.$('#billing_state')) await page.fill('#billing_state', 'Dubai');
    await page.locator('label[for="payment_method_cod"]').tap();
    await btn.scrollIntoViewIfNeeded();
    page.on('response', async (res) => { if (res.url().includes('/checkout/place') && res.status() >= 400) { try { r.placeRefusal = (await res.text()).slice(0, 300); } catch (e) {} } });
    await Promise.all([page.waitForURL(/order-received|thank|success/, { timeout: 20000 }).catch(() => null), btn.tap()]);
    r.placed = new URL(page.url()).pathname;
    if (w === 390) await page.screenshot({ path: `${OUT}/placed-${L}-390.png` });
    r.errors = errors;
    out.push(r);
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
