/* Lane GBX v3 (2.60.621): "View all" / "Gifts Criteria" text size. The cart
   panel and checkout boxes at 390 and 1280, before (11.5px, the size until
   2.60.620, set back for the shot) and after (the new 10px default), plus the
   admin card. GBX_X='{"mode":"live","crit":"3"}' FG_SEED=gbx-feature-seed.php sh tools/fg-preview.sh 12400
   BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> node tools/gbx-v3-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID), OUT = path.resolve(__dirname, '../docs/gbx-feature-shots/v3');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const res = [];
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const actx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const admin = await actx.newPage();
  await admin.goto(BASE + '/admin/login');
  await admin.fill('#email', 'owner@example.com'); await admin.fill('#password', 'preview-password');
  await Promise.all([admin.waitForNavigation(), admin.click('button[type=submit]')]);
  await admin.evaluate(() => window.go('freegifts'));
  const card = admin.locator('.fg-card', { hasText: 'Gift box popups' });
  await card.waitFor({ timeout: 10000 }); await card.scrollIntoViewIfNeeded();
  await card.screenshot({ path: path.join(OUT, 'admin-card-1280.png') });
  res.push({ admin: [await admin.locator('[data-fk="x-link_m"]').inputValue(), await admin.locator('[data-fk="x-link_d"]').inputValue()] });
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.evaluate(async (id) => (await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
    for (const where of ['panel', 'checkout']) {
      for (const when of ['before', 'after']) {
        await page.goto(BASE + (where === 'panel' ? '/' : '/checkout/'), { waitUntil: 'networkidle' });
        if (when === 'before') await page.addStyleTag({ content: '.fgb{--fg-lk:11.5px!important}' });
        if (where === 'panel') { await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(400); }
        if (where === 'checkout' && w === 390) { const row = page.locator('#kbbSummary .cosr').first(); if (await row.count()) await row.click(); await page.waitForTimeout(400); }
        const f = page.locator((where === 'panel' ? '#cart' : '#kbbSummary') + ' .fgb').filter({ visible: true }).first();
        await f.evaluate((x) => x.scrollIntoView({ block: 'center' })); await page.waitForTimeout(200);
        const m = await f.evaluate((x) => { const va = x.querySelector('.fgb-th .fgb-tg'), cr = x.querySelector('.fgb-th .gbx-cr'), svg = cr && cr.querySelector('svg');
          return { viewAll: getComputedStyle(va).fontSize, criteria: cr ? getComputedStyle(cr).fontSize : null, icon: svg ? Math.round(svg.getBoundingClientRect().width * 10) / 10 : null, sw: document.documentElement.scrollWidth }; });
        res.push({ w, where, when, ...m });
        await f.screenshot({ path: path.join(OUT, `${where}-${when}-${w}.png`) });
      }
    }
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'measured.json'), JSON.stringify(res, null, 1));
  console.log(res.map((r) => JSON.stringify(r)).join('\n'));
})().catch((e) => { console.error(e); process.exit(1); });
