/* The phone menu icon's blue ring (17 October 2026). PREVIEW ONLY: taps the
   menu open and shut (by ×, by the scrim) and by keyboard, and reports which
   element ends up focused and whether it shows a focus ring (:focus-visible).
   Usage: BASE=... node tools/mmf-check.cjs */
const { chromium } = require('playwright');
(async () => { const b = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium' }); const out = [];
 const state = (p) => p.evaluate(() => { const a = document.activeElement; return { active: a ? a.tagName.toLowerCase() + (a.id ? '#' + a.id : '') : null, ring: !!(a && a !== document.body && a.matches(':focus-visible')), outline: a ? getComputedStyle(a).outlineStyle + ' ' + getComputedStyle(a).outlineWidth : null }; });
 for (const path of ['/', '/ar/']) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true }); const p = await ctx.newPage(); const errs = []; p.on('pageerror', e => errs.push(String(e)));
  await p.goto(process.env.BASE + path, { waitUntil: 'networkidle' });
  await p.tap('#burger'); await p.waitForTimeout(500); const afterOpenTap = await state(p);
  await p.tap('#mmx'); await p.waitForTimeout(500); const afterCloseX = await state(p);
  await p.tap('#burger'); await p.waitForTimeout(500); await p.mouse.click(380, 400); await p.tap('#mscrim', { position: { x: 380, y: 400 } }).catch(() => {}); await p.waitForTimeout(500); const afterCloseScrim = await state(p);
  await p.focus('#burger'); await p.keyboard.press('Enter'); await p.waitForTimeout(500); const afterOpenKey = await state(p);
  await p.keyboard.press('Escape'); await p.waitForTimeout(500); const afterCloseKey = await state(p);
  out.push({ path, afterOpenTap, afterCloseX, afterCloseScrim, afterOpenKey, afterCloseKey, errs }); await ctx.close(); }
 console.log(JSON.stringify(out, null, 1)); await b.close(); })();
