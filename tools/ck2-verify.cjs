/* Lane CK2 (23 October; from Lane CK's tools/ck22-verify.cjs): the checkout's
   fields in Chromium, EN and AR, 390 and 1280, with the CSS-only "*".
     BASE=http://127.0.0.1:PORT node tools/ck2-verify.cjs
   For each: no star at rest; the star after a field is merely visited and
   left (afterBlur), after it is typed in and cleared (afterTypeClear -- what
   :user-invalid answers), and after Place order finds fields empty
   (afterSubmit); every visible button and link in the form answers
   elementFromPoint; scrollWidth = width; console errors. Then a
   cash-on-delivery order with Area / Street EMPTY is placed (the preview
   shop's own database; no gateway is called). Pictures to docs/lane-ck2/.
   Local preview only. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE;
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const OUT = path.resolve(__dirname, '../docs/lane-ck2');
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

async function basket(page, pre) {
  await page.goto(BASE + pre + '/cart/', { waitUntil: 'networkidle' });
  const ids = await (await page.request.get(BASE + '/ck-ids.json')).json();
  for (const id of ids.slice(0, 3)) {
    await page.evaluate(async (pid) => (await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) })).status, id);
  }
}

const star = (page, name) => page.evaluate((n) => {
  const l = document.querySelector(`label[for="${n}"]`); const s = l && l.querySelector('.required');
  return { star: s ? getComputedStyle(s).display : 'none', color: l ? getComputedStyle(l).color : null };
}, name);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = [];
  for (const lang of ['en', 'ar']) {
    for (const w of [390, 1280]) {
      const m = w === 390;
      const ctx = await browser.newContext({ viewport: { width: w, height: m ? 844 : 900 }, deviceScaleFactor: 1, isMobile: m, hasTouch: m, userAgent: m ? UA_M : UA_D });
      const page = await ctx.newPage();
      const errors = [];
      page.on('console', (msg) => { if (msg.type() === 'error') errors.push(msg.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      const pre = lang === 'ar' ? '/ar' : '';
      await basket(page, pre);
      await page.goto(BASE + pre + '/checkout/', { waitUntil: 'networkidle' });
      const r = { lang, w };

      // At rest: no star anywhere.
      r.restStars = await page.evaluate(() => [...document.querySelectorAll('#kbbCheckoutForm .required')].filter((s) => s.offsetParent && getComputedStyle(s).display !== 'none').length);

      // Every visible text field takes focus and a keystroke.
      r.typable = await page.evaluate(() => {
        const out = { ok: 0, bad: [] };
        document.querySelectorAll('#kbbCheckoutForm input:not([type=hidden]):not([type=radio]):not([type=checkbox]), #kbbCheckoutForm textarea').forEach((el) => {
          if (!el.offsetParent || el.disabled || el.readOnly) return;
          el.focus();
          if (document.activeElement === el) out.ok++; else out.bad.push(el.name || el.id);
        });
        document.activeElement && document.activeElement.blur();
        return out;
      });

      // A fresh page: the focus pass above left every field it touched empty, and so starred.
      await page.goto(BASE + pre + '/checkout/', { waitUntil: 'networkidle' });
      // Leave Email empty: its star and the red label appear; type: they go.
      // (The label's colour eases over .16s, so it is read after that.)
      const email = page.locator('#billing_email');
      await email.click();
      await page.locator('#billing_first_name').click();
      await page.waitForTimeout(400);
      r.afterBlur = await star(page, 'billing_email');
      const fieldBox = await page.locator('#billing_email_field').boundingBox();
      if (fieldBox) await page.screenshot({ path: path.join(OUT, `asterisk-after-blur-${lang}-${w}.png`), clip: { x: 0, y: Math.max(0, fieldBox.y - 70), width: w, height: 260 } });
      // Typed in and cleared, then left: the browser's own :user-invalid.
      const name = page.locator('#billing_first_name');
      await name.click();
      await page.keyboard.type('M');
      await page.keyboard.press('Backspace');
      await page.locator('#billing_phone').click();
      await page.waitForTimeout(400);
      r.afterTypeClear = await star(page, 'billing_first_name');
      const nameBox = await page.locator('#billing_first_name_field').boundingBox();
      if (nameBox) await page.screenshot({ path: path.join(OUT, `asterisk-after-clear-${lang}-${w}.png`), clip: { x: 0, y: Math.max(0, nameBox.y - 70), width: w, height: 260 } });
      await email.fill('layla@example.com');
      await page.waitForTimeout(400);
      r.afterType = await star(page, 'billing_email');

      // Place order with the rest empty: their stars show.
      await page.locator('#billing_first_name').fill('');
      await page.locator('[data-place]').filter({ visible: true }).first().click();
      await page.waitForTimeout(300);
      r.afterSubmit = {};
      for (const n of ['billing_first_name', 'billing_phone', 'billing_address_1', 'billing_address_2', 'billing_state']) r.afterSubmit[n] = (await star(page, n)).star;
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.screenshot({ path: path.join(OUT, `submit-empty-${lang}-${w}.png`), fullPage: true });

      // Buttons and links in the form answer a click at their centre.
      r.clickable = await page.evaluate(() => {
        const bad = [];
        let ok = 0;
        document.querySelectorAll('#kbbCheckoutForm button, #kbbCheckoutForm a[href], #kbbCheckoutForm input:not([type=hidden]):not([type=radio]):not([type=checkbox]), #kbbCheckoutForm select').forEach((el) => {
          const b = el.getBoundingClientRect();
          if (!el.offsetParent || b.width < 2 || b.height < 2 || b.bottom < 0 || b.top > innerHeight) return;
          const hit = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
          const lab = el.id ? document.querySelector('label[for="' + el.id + '"]') : null;
          if (hit && (hit === el || el.contains(hit) || (lab && (hit === lab || lab.contains(hit))))) ok++; else bad.push((el.textContent || el.id || el.tagName).trim().slice(0, 30));
        });
        return { ok, bad };
      });
      r.sw = await page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
      r.errors = errors;
      report.push(r);
      await ctx.close();
    }
  }

  // An order with Area / Street EMPTY, cash on delivery, on the preview shop.
  for (const lang of ['en', 'ar']) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, userAgent: UA_M });
    const page = await ctx.newPage();
    const pre = lang === 'ar' ? '/ar' : '';
    await basket(page, pre);
    await page.goto(BASE + pre + '/checkout/', { waitUntil: 'networkidle' });
    await page.locator('#billing_email').fill('empty.street.' + lang + '@example.com');
    await page.locator('#billing_first_name').fill('Mariam Saeed');
    await page.locator('#billing_phone').fill('501234567');
    await page.locator('#billing_address_1').fill('Villa 7');
    await page.locator('#billing_state').selectOption({ index: 1 });
    await page.waitForTimeout(600);
    const cod = page.locator('input[name="payment_method"][value="cod"]');
    if (await cod.count()) await cod.check({ force: true });
    await page.waitForTimeout(300);
    await Promise.all([
      page.waitForURL(/order-received|success|thank/i, { timeout: 20000 }).catch(() => {}),
      page.locator('[data-place]').filter({ visible: true }).first().click(),
    ]);
    await page.waitForTimeout(1500);
    report.push({ order: lang, url: page.url(), title: await page.title() });
    await page.screenshot({ path: path.join(OUT, `empty-street-order-${lang}-390.png`), fullPage: false });
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'verify-facts.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
