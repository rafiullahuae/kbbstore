/* The wishlist heart: instant at the tap, put back on failure, and the cart
   panel's heart tab grey until something is saved, then red. PREVIEW ONLY.
   Usage: BASE=http://127.0.0.1:<port> node tools/wli-check.cjs */
const { chromium } = require('playwright');
(async () => { const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium' }); const out = [];
 for (const [path, tag] of [['/product/relief-sun-rice-probiotics-spf50/', 'en'], ['/ar/product/relief-sun-rice-probiotics-spf50/', 'ar']]) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e)));
  let mode = 'slow';
  await p.route('**/wishlist/toggle', async (route) => { if (mode === 'fail') { await new Promise(r => setTimeout(r, 600)); return route.fulfill({ status: 500, body: '{}', contentType: 'application/json' }); } await new Promise(r => setTimeout(r, 1000)); return route.continue(); });
  await p.goto(process.env.BASE + path, { waitUntil: 'networkidle' });
  const tab = () => p.evaluate(() => { const s = document.querySelector('.kc-wt .kc-wh'); return s ? { on: s.classList.contains('on'), color: getComputedStyle(s).color, fill: s.getAttribute('fill') } : null; });
  const r = { tag, tabBefore: await tab() };
  const heart = p.locator('[data-kbb-wish]').first();
  const t0 = Date.now(); await heart.click();
  r.onAfterTapMs = await p.evaluate(() => new Promise(res => { const s = performance.now(); const el = document.querySelector('[data-kbb-wish]'); const chk = () => el.classList.contains('on') ? res(Math.round(performance.now() - s)) : requestAnimationFrame(chk); chk(); }));
  r.clickToOnMs = Date.now() - t0;
  r.tabRightAfterTap = await tab();
  await p.waitForTimeout(1500); r.onAfterServer = await heart.evaluate(e => e.classList.contains('on')); r.tabAfterServer = await tab();
  mode = 'fail'; await p.waitForTimeout(100);
  await heart.click(); r.offAtTapInFailMode = !(await heart.evaluate(e => e.classList.contains('on')));
  await p.waitForTimeout(800); r.restoredAfterFail = await heart.evaluate(e => e.classList.contains('on'));
  r.errs = errs; out.push(r);
  if (tag) { mode = 'slow'; try { await p.click('#kcOpen, header a.ib[href*="cart"], header .ib-cart', { timeout: 2000 }); await p.waitForTimeout(700); await p.screenshot({ path: `docs/lane-wli/tab-${tag}-390.png`, clip: { x: 0, y: 0, width: 390, height: 120 } }); } catch (e) { r.shot = 'no panel: ' + String(e).slice(0, 60); } }
  await ctx.close(); }
 console.log(JSON.stringify(out, null, 1)); await b.close(); })();
