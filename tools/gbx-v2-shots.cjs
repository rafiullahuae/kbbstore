/* Lane GBX v2 (2.60.620): the owner's feedback on 2.60.619 -- Gifts Criteria
   as a text link with its gift icon, photos not clickable (Photo popup Off),
   a big visible close on the criteria popup, a slight size animation on the
   tabs, and the space above the box in the cart panel. Admin card at 1280;
   cart panel box and each criteria design at 390 and 1280; Arabic once.
   GBX_X='{"mode":"live"}' FG_SEED=gbx-feature-seed.php sh tools/fg-preview.sh 12390
   BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> node tools/gbx-v2-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID), OUT = path.resolve(__dirname, '../docs/gbx-feature-shots/v2');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const res = { admin: {}, shop: [] };
const tap = async (page, loc) => { const b = await loc.boundingBox(); await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2); };

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const actx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const admin = await actx.newPage();
  const aerr = []; admin.on('pageerror', (e) => aerr.push(String(e)));
  await admin.goto(BASE + '/admin/login');
  await admin.fill('#email', 'owner@example.com'); await admin.fill('#password', 'preview-password');
  await Promise.all([admin.waitForNavigation(), admin.click('button[type=submit]')]);
  await admin.evaluate(() => window.go('freegifts'));
  const card = admin.locator('.fg-card', { hasText: 'Gift box popups' });
  await card.waitFor({ timeout: 10000 });
  res.admin.photoPopup = await admin.locator('[data-fk="x-pop"]').inputValue();
  res.admin.gap = await admin.locator('[data-fk="x-panel_gap"]').inputValue();
  res.admin.popOptions = await admin.locator('[data-fk="x-pop"] option').allTextContents();
  await card.scrollIntoViewIfNeeded(); await card.screenshot({ path: path.join(OUT, 'admin-card-1280.png') });

  for (const crit of ['1', '2', '3']) {
    if ((await admin.locator('[data-fk="x-crit"]').inputValue()) !== crit) { await admin.selectOption('[data-fk="x-crit"]', crit); await admin.waitForTimeout(900); }
    for (const [lang, w] of (crit === '1' ? [['en', 390], ['en', 1280], ['ar', 390]] : [['en', 390], ['en', 1280]])) {
      const pre = lang === 'ar' ? '/ar' : '', t = `${crit}-${lang}-${w}`;
      const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      const errors = []; page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); }); page.on('pageerror', (e) => errors.push(String(e)));
      await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
      await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
      await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
      await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(500);
      const f = page.locator('#cart .fgb').first();
      await f.evaluate((x) => x.scrollIntoView({ block: 'center' })); await page.waitForTimeout(250);
      const r = { crit, lang, w };
      r.box = await f.evaluate((x) => {
        const cr = x.querySelector('.fgb-th .gbx-cr'), va = x.querySelector('.fgb-th .fgb-tg'), cs = getComputedStyle(cr), vs = getComputedStyle(va);
        const prev = x.previousElementSibling;
        return { marginTop: getComputedStyle(x).marginTop, gapAbove: prev ? Math.round(x.getBoundingClientRect().top - prev.getBoundingClientRect().bottom) : null,
          photosAreButtons: x.querySelectorAll('.fgb-th button.fgb-t').length, photoPopups: x.querySelectorAll('.gbx-pp').length,
          linkLikeViewAll: ['fontSize', 'fontWeight', 'color', 'backgroundColor', 'borderTopWidth'].every((k) => cs[k] === vs[k]), icon: !!cr.querySelector('svg[width="13"][height="13"]'),
          sw: document.documentElement.scrollWidth };
      });
      // A photo tap does nothing now.
      await tap(page, f.locator('.fgb-th .fgb-t').first()); await page.waitForTimeout(250);
      r.photoTapOpensNothing = await page.evaluate(() => !document.querySelector(':popover-open'));
      await f.screenshot({ path: path.join(OUT, `box-panel-${t}.png`) });
      await tap(page, f.locator('.fgb-th .gbx-cr')); await page.waitForTimeout(350);
      r.close = await page.evaluate(() => { const x = document.querySelector('.gbx-cp:popover-open .gbx-x'); if (!x) return null; const b = x.getBoundingClientRect(), p = x.closest('.gbx-cp').getBoundingClientRect(), cs = getComputedStyle(x);
        return { w: Math.round(b.width), h: Math.round(b.height), glyph: getComputedStyle(x.querySelector('span')).fontSize, bg: cs.backgroundColor, fromEnd: document.documentElement.dir === 'rtl' ? Math.round(b.left - p.left) : Math.round(p.right - b.right), focused: document.activeElement === x }; });
      await page.screenshot({ path: path.join(OUT, `criteria-${t}.png`) });
      if (crit === '3') {
        const h0 = await page.evaluate(() => Math.round(document.querySelector('.gbx-cp:popover-open').getBoundingClientRect().height));
        await tap(page, page.locator('.gbx-cp:popover-open .gbx-tabs label').first());
        await page.waitForTimeout(90);
        const hMid = await page.evaluate(() => Math.round(document.querySelector('.gbx-cp:popover-open').getBoundingClientRect().height));
        await page.screenshot({ path: path.join(OUT, `criteria-3-tab-moving-${lang}-${w}.png`) });
        await page.waitForTimeout(400);
        const h1 = await page.evaluate(() => Math.round(document.querySelector('.gbx-cp:popover-open').getBoundingClientRect().height));
        await page.screenshot({ path: path.join(OUT, `criteria-3-tab-after-${lang}-${w}.png`) });
        r.tabHeights = { before: h0, at90ms: hMid, after: h1 };
      }
      await page.keyboard.press('Escape'); await page.waitForTimeout(250);
      r.escCloses = await page.evaluate(() => !document.querySelector('.gbx-cp:popover-open'));
      r.focusBack = await page.evaluate(() => document.activeElement.classList.contains('gbx-cr'));
      r.errors = errors;
      res.shop.push(r);
      await ctx.close();
    }
  }
  res.admin.errors = aerr;
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'measured.json'), JSON.stringify(res, null, 1));
  console.log(JSON.stringify(res, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
