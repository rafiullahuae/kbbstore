/* Lane GU: the "Gifts go fast" line under the free gifts box, photographed and measured.
   FG_SEED=fg5-seed.php sh tools/fg-preview.sh 11460   (then, inside its env, tools/gu-email.php)
   BASE=http://127.0.0.1:PORT PID=<product id inside a range> node tools/gu-shots.cjs
   Cart panel, cart page, checkout: EN and AR at 390 and 1280; the email EN and AR at 600;
   a sweep frame pair; reduced motion. Writes storage/gu-shots/ and prints the numbers. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID);
const OUT = path.resolve(__dirname, '../storage/gu-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

async function add(page, pre) {
  await page.goto(BASE + pre + '/', { waitUntil: 'domcontentloaded' });
  const s = await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
  if (s !== 200) throw new Error('add ' + s);
}

// The visible box's line: where it sits against the card, and whether the card's buttons still answer a click.
async function measure(page) {
  return page.evaluate(() => {
    const f = [...document.querySelectorAll('fieldset.fgb')].find((x) => { const r = x.getBoundingClientRect(); return r.width > 0 && r.right > 0 && r.left < innerWidth && getComputedStyle(x).visibility !== 'hidden'; });
    if (!f) return null;
    f.scrollIntoView({ block: 'center' });
    const u = f.querySelector('.fgb-u'), s = f.querySelector('.fgb-us');
    const fr = f.getBoundingClientRect(), ur = u.getBoundingClientRect();
    const btn = f.querySelector('.fgb-th .fgb-tg'), br = btn.getBoundingClientRect();
    const hit = document.elementFromPoint(br.left + br.width / 2, br.top + br.height / 2);
    const next = f.nextElementSibling; const nr = next && next.getClientRects().length ? next.getBoundingClientRect() : null;
    const cs = getComputedStyle(s);
    return { gapBelowBorder: +(ur.top - fr.bottom).toFixed(1), lineH: Math.round(ur.height), lineW: Math.round(ur.width), boxW: Math.round(fr.width),
      centredOffset: +((ur.left + ur.width / 2) - (fr.left + fr.width / 2)).toFixed(1), fontSize: getComputedStyle(u).fontSize, weight: getComputedStyle(u).fontWeight,
      overlapsNext: nr ? nr.top < ur.bottom - 0.5 : false, animation: cs.animationName + ' ' + cs.animationDuration + ' ' + cs.animationDirection,
      viewAllClickable: !!hit && (hit === btn || btn.contains(hit)), scrollWidth: document.documentElement.scrollWidth, vw: innerWidth,
      fontFamily: getComputedStyle(u).fontFamily.split(',')[0] };
  });
}

async function shotLine(page, file) {
  const r = await page.evaluate(() => {
    const f = [...document.querySelectorAll('fieldset.fgb')].find((x) => { const r = x.getBoundingClientRect(); return r.width > 0 && r.right > 0 && r.left < innerWidth && getComputedStyle(x).visibility !== 'hidden'; });
    f.scrollIntoView({ block: 'center' });
    const a = f.getBoundingClientRect(), b = f.querySelector('.fgb-u').getBoundingClientRect();
    return { x: Math.max(0, a.left - 14), y: Math.max(0, a.top - 16), w: a.width + 28, h: b.bottom - a.top + 30 };
  });
  await page.screenshot({ path: file, clip: { x: r.x, y: r.y, width: Math.min(r.w, page.viewportSize().width - r.x), height: r.h } });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const lang of ['en', 'ar']) for (const w of [390, 1280]) {
    const pre = lang === 'ar' ? '/ar' : '';
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await add(page, pre);

    // 1. cart page
    await page.goto(BASE + pre + '/cart/', { waitUntil: 'load' });
    report[`cart-page-${lang}-${w}`] = await measure(page);
    await shotLine(page, `${OUT}/cart-page-${lang}-${w}.png`);
    if (lang === 'en' && w === 390) { // the sweep moving: two frames 0.9 s apart
      await page.waitForTimeout(300); await shotLine(page, `${OUT}/sweep-a-en-390.png`);
      await page.waitForTimeout(900); await shotLine(page, `${OUT}/sweep-b-en-390.png`);
    }
    if (lang === 'ar' && w === 390) {
      await page.waitForTimeout(300); await shotLine(page, `${OUT}/sweep-a-ar-390.png`);
      await page.waitForTimeout(900); await shotLine(page, `${OUT}/sweep-b-ar-390.png`);
    }

    // 2. cart panel, opened from the header on the homepage
    await page.goto(BASE + pre + '/', { waitUntil: 'load' });
    const opener = page.locator('header [data-kbb-open="cart"]').first();
    if (await opener.count()) await opener.click().catch(() => {});
    await page.waitForSelector('#cart fieldset.fgb', { state: 'visible', timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(500);
    report[`cart-panel-${lang}-${w}`] = await measure(page);
    await page.screenshot({ path: `${OUT}/cart-panel-${lang}-${w}.png` });

    // 3. checkout summary
    await page.goto(BASE + pre + '/checkout/', { waitUntil: 'load' });
    for (const t of await page.locator('button.co-ta[aria-expanded="false"], [data-stab="summary"]').all()) if (w === 390 && await t.isVisible()) { await t.click().catch(() => {}); break; }
    await page.waitForTimeout(300);
    report[`checkout-${lang}-${w}`] = await measure(page);
    if (report[`checkout-${lang}-${w}`]) await shotLine(page, `${OUT}/checkout-${lang}-${w}.png`);
    else await page.screenshot({ path: `${OUT}/checkout-${lang}-${w}.png`, fullPage: true });

    report[`console-${lang}-${w}`] = errors;
    await ctx.close();
  }

  // Reduced motion: no animation, plain grey.
  const rm = await browser.newContext({ userAgent: UA, viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, reducedMotion: 'reduce' });
  const rp = await rm.newPage();
  await add(rp, '');
  await rp.goto(BASE + '/cart/', { waitUntil: 'load' });
  report['reduced-motion'] = await rp.evaluate(() => { const s = getComputedStyle(document.querySelector('.fgb-us')); return { animation: s.animationName, fill: s.webkitTextFillColor, bg: s.backgroundImage }; });
  await shotLine(rp, `${OUT}/reduced-motion-en-390.png`);
  await rm.close();

  // The email, at its own width.
  for (const lang of ['en', 'ar']) {
    const ctx = await browser.newContext({ viewport: { width: 640, height: 900 }, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    await p.goto('file://' + OUT + `/email-${lang}.html`, { waitUntil: 'load' });
    const el = p.locator('td', { hasText: lang === 'ar' ? 'الهدايا تنفد' : 'Gifts go fast' }).last();
    await el.scrollIntoViewIfNeeded();
    const b = await el.boundingBox();
    await p.screenshot({ path: `${OUT}/email-${lang}-640.png`, clip: { x: 0, y: Math.max(0, b.y - 380), width: 640, height: 440 } });
    report[`email-${lang}`] = await el.evaluate((t) => ({ color: getComputedStyle(t).color, hot: t.querySelector('span') && t.querySelector('span').textContent, hotColor: t.querySelector('span') && getComputedStyle(t.querySelector('span')).color, align: getComputedStyle(t).textAlign }));
    await ctx.close();
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
