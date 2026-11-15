/* Lane GCR: Gifts Criteria opened as the homepage's simple rows (the default),
   on the cart panel and the cart page, 390 and 1280, English and /ar; plus the
   admin control (Marketing -> Free gifts -> Overview -> Gift box popups ->
   "Gifts Criteria popup shows").
   GBX_X='{"mode":"live","pop":"off","crit":"3"}' FG_SEED=gbx-feature-seed.php sh tools/fg-preview.sh 12480
   BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> node tools/gcr-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID), OUT = path.resolve(__dirname, '../docs/lane-gcr-shots');
fs.mkdirSync(OUT, { recursive: true });
const out = [];
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

(async () => {
  const browser = await chromium.launch();
  for (const lang of ['', '/ar']) {
    for (const w of [390, 1280]) {
      const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      const errors = []; let reqs = 0;
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('request', () => { reqs++; });
      await page.goto(BASE + lang + '/', { waitUntil: 'networkidle' });
      const st = await page.evaluate(async (id) => (await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
      if (st !== 200) throw new Error('add ' + st + ' ' + lang + ' ' + w);
      for (const where of ['panel', 'cart']) {
        await page.goto(BASE + lang + (where === 'panel' ? '/' : '/cart'), { waitUntil: 'networkidle' });
        if (where === 'panel') { await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(400); }
        const f = page.locator('.fgb').filter({ visible: true }).first();
        await f.evaluate((x) => x.scrollIntoView({ block: 'center' })); await page.waitForTimeout(250);
        const cr = f.locator('.gbx-cr').filter({ visible: true }).first();
        reqs = 0;
        const c = await cr.boundingBox(); await page.mouse.click(c.x + c.width / 2, c.y + c.height / 2); await page.waitForTimeout(400);
        const r = await page.evaluate(() => {
          const p = document.querySelector('.gbx-cp:popover-open');
          if (!p) return { open: false };
          const b = p.getBoundingClientRect(), row = p.querySelector('.gbx-r');
          return { open: true, cls: p.className, rows: p.querySelectorAll('.gbx-r').length, photos: [...p.querySelectorAll('.gbx-rt')].filter((x) => x.offsetParent).length,
            buttons: p.querySelectorAll('button').length, tabs: p.querySelectorAll('.gbx-tabs,input').length,
            popW: Math.round(b.width), popH: Math.round(b.height), rowH: row ? Math.round(row.getBoundingClientRect().height) : 0,
            amountPx: row ? getComputedStyle(row.querySelector('.gbx-ra')).fontSize : null,
            sw: document.documentElement.scrollWidth, vw: innerWidth, dir: document.documentElement.dir || 'ltr' };
        });
        const t = `${lang ? 'ar-' : ''}${where}-${w}`;
        Object.assign(r, { t, requestsOnOpen: reqs });
        await page.screenshot({ path: path.join(OUT, `rows-${t}.png`) });
        await page.keyboard.press('Escape'); await page.waitForTimeout(250);
        r.escCloses = await page.evaluate(() => !document.querySelector('.gbx-cp:popover-open'));
        r.errors = errors.slice();
        out.push(r);
      }
      await ctx.close();
    }
  }
  // The admin control.
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const aerr = [];
  page.on('pageerror', (e) => aerr.push(String(e)));
  await page.goto(BASE + '/admin/login');
  await page.fill('#email', 'owner@example.com');
  await page.fill('#password', 'preview-password');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.evaluate(() => window.go('freegifts'));
  const card = page.locator('.fg-card', { hasText: 'Gift box popups' });
  await card.waitFor({ timeout: 10000 });
  await page.waitForTimeout(1500); await card.evaluate((x) => x.scrollIntoView({ block: 'start' })); await page.waitForTimeout(300);
  await card.screenshot({ path: path.join(OUT, 'admin-gift-box-popups-rows.png') });
  const before = await card.locator('[data-fk="x-crit_show"]').inputValue();
  await card.locator('[data-fk="x-crit_show"]').selectOption('tabs'); await page.waitForTimeout(900);
  const card2 = page.locator('.fg-card', { hasText: 'Gift box popups' });
  const after = await card2.locator('[data-fk="x-crit_show"]').inputValue();
  await card2.screenshot({ path: path.join(OUT, 'admin-gift-box-popups-tabs.png') });
  await card2.locator('[data-fk="x-crit_show"]').selectOption('rows'); await page.waitForTimeout(900);
  out.push({ admin: true, before, after, errors: aerr });
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'numbers.json'), JSON.stringify(out, null, 1));
  console.log(JSON.stringify(out));
})().catch((e) => { console.error(e); process.exit(1); });
