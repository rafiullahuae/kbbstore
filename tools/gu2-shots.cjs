/* Lane GU2: the "Gifts go fast" line on the homepage free-gifts section, and its
   size / weight / speed controls, photographed and measured.
   FG_SEED=fg5-seed.php sh tools/fg-preview.sh 11460 && sh tools/gu2-set.sh
   BASE=http://127.0.0.1:11460 PID=42 STAGE=<stage> node tools/gu2-shots.cjs
     STAGE=default  homepage EN/AR at 390/1280, a sweep pair, reduced motion
     STAGE=off      the homepage with the switch off ("New gifts added daily")
     STAGE=custom   homepage + cart page with a moved look (run gu2-set.sh with GU2_LOOK first)
     STAGE=admin    Display -> Gift box look and Homepage section, 1280 and 390
   Writes storage/gu-shots/home-*.png / admin-*.png and prints the numbers. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID || 0), STAGE = process.env.STAGE || 'default';
const OUT = path.resolve(__dirname, '../storage/gu-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

async function homeShot(page, file) {
  const r = await page.evaluate(() => {
    // The floating chat widget and its greeting sit over the section in a still photo; hidden for the picture only.
    document.querySelectorAll('body *').forEach((x) => { const p = getComputedStyle(x).position; if (p === 'fixed' && !x.closest('header')) x.style.visibility = 'hidden'; });
    const s = document.querySelector('section.fgh'), y = scrollY;
    const card = s.querySelector('.fgh-card').getBoundingClientRect();
    const line = (s.querySelector('.fgh-u') || s.querySelector('.fgh-note')).getBoundingClientRect();
    const top = card.top + y - 40;
    return { x: 0, y: top, w: document.documentElement.clientWidth, h: Math.max(line.bottom, card.bottom) + y - top + 60 };
  });
  await page.screenshot({ path: file, fullPage: true, clip: { x: r.x, y: r.y, width: r.w, height: r.h } });
}
async function measureHome(page) {
  return page.evaluate(() => {
    const s = document.querySelector('section.fgh'), u = s.querySelector('.fgh-u'), n = s.querySelector('.fgh-note');
    const el = u || n, r = el.getBoundingClientRect(), sp = u ? u.querySelector('.fgh-us') : null, cs = getComputedStyle(el);
    const rowBtn = s.querySelector('.fgh-r'); rowBtn.scrollIntoView({ block: 'center', behavior: 'instant' }); const br = rowBtn.getBoundingClientRect();
    const hit = document.elementFromPoint(br.left + br.width / 2, br.top + br.height / 2);
    const card = s.querySelector('.fgh-card').getBoundingClientRect(), r2 = el.getBoundingClientRect();
    return { line: u ? 'urgency' : 'note', text: el.textContent.trim(), gapBelowCard: Math.round(r2.top - card.bottom), h: Math.round(r.height), fontSize: cs.fontSize, weight: cs.fontWeight,
      centredOffset: +((r2.left + r2.width / 2) - (card.left + card.width / 2)).toFixed(1), animation: sp ? getComputedStyle(sp).animationName + ' ' + getComputedStyle(sp).animationDuration + ' ' + getComputedStyle(sp).animationDirection : null,
      rowClickable: !!hit && (hit === rowBtn || rowBtn.contains(hit)), scrollWidth: document.documentElement.scrollWidth, vw: innerWidth, sectionH: Math.round(s.getBoundingClientRect().height) };
  });
}
async function ctxFor(browser, w, extra) {
  return browser.newContext(Object.assign({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 }, extra || {}));
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  if (STAGE === 'default' || STAGE === 'off' || STAGE === 'custom') {
    const langs = STAGE === 'default' ? ['en', 'ar'] : ['en'];
    for (const lang of langs) for (const w of [390, 1280]) {
      const pre = lang === 'ar' ? '/ar' : '', ctx = await ctxFor(browser, w), page = await ctx.newPage(), errors = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
      await page.goto(BASE + pre + '/', { waitUntil: 'load' });
      await page.waitForTimeout(300);
      report[`home-${STAGE}-${lang}-${w}`] = Object.assign(await measureHome(page), { scriptErrors: errors.length });
      await homeShot(page, `${OUT}/home-${STAGE}-${lang}-${w}.png`);
      if (STAGE === 'default' && w === 390) {
        await page.waitForTimeout(200); await homeShot(page, `${OUT}/home-sweep-a-${lang}-390.png`);
        await page.waitForTimeout(900); await homeShot(page, `${OUT}/home-sweep-b-${lang}-390.png`);
      }
      if (STAGE === 'custom') { // the box line follows the same controls
        const s = await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
        if (s !== 200) throw new Error('add ' + s);
        await page.goto(BASE + '/cart/', { waitUntil: 'load' });
        report[`box-custom-${w}`] = await page.evaluate(() => {
          const f = [...document.querySelectorAll('fieldset.fgb')].find((x) => x.getBoundingClientRect().width > 0); f.scrollIntoView({ block: 'center' });
          const u = f.querySelector('.fgb-u'), r = u.getBoundingClientRect(), cs = getComputedStyle(u), sp = getComputedStyle(u.querySelector('.fgb-us'));
          return { fontSize: cs.fontSize, weight: cs.fontWeight, lineH: Math.round(r.height), gapBelowBorder: +(r.top - f.getBoundingClientRect().bottom).toFixed(1), animation: sp.animationName + ' ' + sp.animationDuration, scrollWidth: document.documentElement.scrollWidth };
        });
        const clip = await page.evaluate(() => { const f = [...document.querySelectorAll('fieldset.fgb')].find((x) => x.getBoundingClientRect().width > 0), a = f.getBoundingClientRect(), b = f.querySelector('.fgb-u').getBoundingClientRect(); return { x: Math.max(0, a.left - 14), y: Math.max(0, a.top - 16), width: a.width + 28, height: b.bottom - a.top + 30 }; });
        clip.width = Math.min(clip.width, w - clip.x);
        await page.screenshot({ path: `${OUT}/box-custom-en-${w}.png`, clip });
      }
      await ctx.close();
    }
    if (STAGE === 'default') {
      const ctx = await ctxFor(browser, 390, { reducedMotion: 'reduce' }), page = await ctx.newPage();
      await page.goto(BASE + '/', { waitUntil: 'load' });
      report['home-reduced-motion'] = await page.evaluate(() => { const s = getComputedStyle(document.querySelector('.fgh-us')); return { animation: s.animationName, fill: s.webkitTextFillColor, bg: s.backgroundImage }; });
      await ctx.close();
    }
  }
  if (STAGE === 'admin') {
    for (const w of [1280, 390]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 }), page = await ctx.newPage(), errors = [];
      let apiCalls = 0;
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('request', (r) => { if (r.url().includes('/admin-api/')) apiCalls++; });
      await page.goto(BASE + '/admin/login');
      await page.fill('#email', 'owner@example.com');
      await page.fill('#password', 'preview-password');
      await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
      await page.evaluate(() => window.go('freegifts'));
      await page.locator('[data-screen="freegifts"] .fg-status').waitFor({ timeout: 8000 }).catch(() => {});
      await page.locator('#fg-tab-display').click();
      await page.locator('[data-fk="lk-u_speed"]').waitFor();
      const u = page.locator('[data-fk="lk-urgency-look"]');
      await u.scrollIntoViewIfNeeded();
      const card = u.locator('xpath=ancestor::section[1]');
      await card.screenshot({ path: `${OUT}/admin-gift-box-look-${w}.png` });
      const before = apiCalls;
      await page.fill('[data-fk="lk-u_px"]', '99');      // clamped to 24, as by the server
      await page.fill('[data-fk="lk-u_speed"]', '1.8');
      await page.selectOption('[data-fk="lk-u_weight"]', '900');
      report[`admin-${w}`] = { requestsWhileEditing: apiCalls - before, scriptErrors: errors, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
        weights: await page.locator('[data-fk="lk-u_weight"] option').allTextContents() };
      await page.locator('[data-fk="lk-u_px"]').dispatchEvent('change');
      report[`admin-${w}`].pxAfterClamp = await page.inputValue('[data-fk="lk-u_px"]');
      await page.locator('#fg-tab-home').click();
      const sw = page.locator('button[role=switch]', { hasText: 'Line under the gifts' });
      await sw.waitFor();
      await sw.scrollIntoViewIfNeeded();
      report[`admin-${w}`].homeSwitch = await sw.getAttribute('aria-checked');
      await sw.locator('xpath=ancestor::section[1]').screenshot({ path: `${OUT}/admin-homepage-section-${w}.png` });
      await ctx.close();
    }
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
