/* Checkout polish (17 October 2026), PREVIEW ONLY: the phone number on the
   right on /ar, and the sign-in dialog opening with nothing focused on a touch
   phone (as before on a computer). Usage: BASE=... node tools/acp-check.cjs */
const { chromium } = require('playwright');
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
(async () => { const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium' }); const out = [];
 for (const [lang, pre] of [['ar', '/ar'], ['en', '']]) for (const touch of [true, false]) {
  const ctx = await b.newContext({ viewport: touch ? { width: 390, height: 844 } : { width: 1280, height: 900 }, userAgent: UA, hasTouch: touch, isMobile: touch });
  const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e)));
  await p.goto(process.env.BASE + pre + '/shop/', { waitUntil: 'networkidle' });
  await p.locator('a.kbb-card-cart.add_to_cart_button').first().click(); await p.waitForTimeout(2000);
  await p.goto(process.env.BASE + pre + '/checkout/', { waitUntil: 'networkidle' });
  await p.fill('#billing_email', 'owner@example.com'); await p.fill('input[name=billing_phone]', '0525352613');
  const tel = await p.evaluate(() => { const i = document.querySelector('input[name=billing_phone]'); const c = getComputedStyle(i); const lead = i.parentElement.querySelector('.lead'); const lr = lead ? lead.getBoundingClientRect() : null; const ir = i.getBoundingClientRect();
    const range = document.createRange(); return { dir: c.direction, align: c.textAlign, padL: c.paddingLeft, padR: c.paddingRight, iconLeft: lr ? Math.round(lr.left) : null, iconRight: lr ? Math.round(lr.right) : null, textRightEdge: Math.round(ir.right - parseFloat(c.paddingRight)), textLeftEdge: Math.round(ir.left + parseFloat(c.paddingLeft)) }; });
  if (touch) await p.locator('input[name=billing_phone]').screenshot({ path: `docs/lane-acp/phone-${lang}-390.png` });
  const link = p.locator('[data-kbb-signin]').first(); let focus = null;
  if (await link.count()) { await link.click(); await p.waitForTimeout(400); focus = await p.evaluate(() => { const a = document.activeElement; return a ? (a.tagName.toLowerCase() + (a.id ? '#' + a.id : '') + (a.className ? '.' + String(a.className).split(' ')[0] : '')) : null; }); }
  out.push({ lang, touch, tel, focusAfterOpen: focus, errs }); await ctx.close(); }
 console.log(JSON.stringify(out, null, 1)); await b.close(); })();
