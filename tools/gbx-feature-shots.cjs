/* Lane GBX, the real feature: Marketing -> Free gifts -> Overview -> Gift box
   popups in the admin (Off, Testing, Live and the pickers, 1280), then the
   shop in Testing from an address ON the Testing list and from one that is
   not (cart panel and checkout, 390 and 1280), with a photo popup and the
   Gifts Criteria popup opened by a real click, Esc closing each.
   FG_SEED=gbx-feature-seed.php sh tools/fg-preview.sh 12380
   BASE=http://127.0.0.1:<port> PID=<fg-band-1200 id> node tools/gbx-feature-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, PID = Number(process.env.PID), OUT = path.resolve(__dirname, '../docs/gbx-feature-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const res = { admin: {}, shop: [] };

async function admin(browser, step) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto(BASE + '/admin/login');
  await page.fill('#email', 'owner@example.com');
  await page.fill('#password', 'preview-password');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.evaluate(() => window.go('freegifts'));
  const card = page.locator('.fg-card', { hasText: 'Gift box popups' });
  await card.waitFor({ timeout: 10000 });
  await step(page, card);
  res.admin.errors = (res.admin.errors || []).concat(errors);
  await ctx.close();
}
const mode = async (page, card, m) => {
  await card.locator(`[data-fk="x-mode-${m}"]`).click();
  await page.locator('#kbbSureYes').click();
  await page.waitForTimeout(700);
};
const shot = async (page, card, name) => { await card.scrollIntoViewIfNeeded(); await page.waitForTimeout(200); await card.screenshot({ path: path.join(OUT, name) }); };

async function shop(browser, label) {
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
    await ctx.addInitScript(() => { window.__cls = 0; new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); });
    const page = await ctx.newPage();
    const errors = []; let reqs = 0;
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('request', (r) => { reqs++; });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    const st = await page.evaluate(async (id) => (await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) })).status, PID);
    if (st !== 200) throw new Error('add ' + st);
    for (const where of ['panel', 'checkout']) {
      await page.goto(BASE + (where === 'panel' ? '/' : '/checkout/'), { waitUntil: 'networkidle' });
      if (where === 'panel') { await page.locator('[data-kbb-cart]').first().click(); await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 }); await page.waitForTimeout(400); }
      if (where === 'checkout' && w === 390) { const row = page.locator('#kbbSummary .cosr').first(); if (await row.count()) await row.click(); await page.waitForTimeout(400); }
      const f = page.locator((where === 'panel' ? '#cart' : '#kbbSummary') + ' .fgb').filter({ visible: true }).first();
      await f.evaluate((x) => x.scrollIntoView({ block: 'center' })); await page.waitForTimeout(250);
      const t = `${label}-${where}-${w}`;
      const r = { label, where, w, cls: await page.evaluate(() => window.__cls), sw: await page.evaluate(() => document.documentElement.scrollWidth),
        popups: await f.evaluate((x) => x.classList.contains('gbx')), criteriaButton: await f.locator('.gbx-cr').count() };
      await f.screenshot({ path: path.join(OUT, `box-${t}.png`) });
      if (r.popups) {
        // A real click on the third photo, then Esc.
        reqs = 0;
        const tile = f.locator('.fgb-th .fgb-t').nth(2);
        const b = await tile.boundingBox(); await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2); await page.waitForTimeout(350);
        r.photoPopup = await page.evaluate(() => { const p = document.querySelector('.gbx-pp:popover-open'); return p ? p.textContent.replace(/\s+/g, ' ').trim().slice(0, 120) : null; });
        r.requestsOnOpen = reqs;
        await page.screenshot({ path: path.join(OUT, `popup-${t}.png`) });
        await page.keyboard.press('Escape'); await page.waitForTimeout(250);
        r.photoEscCloses = await page.evaluate(() => !document.querySelector('.gbx-pp:popover-open'));
        r.focusBack = await page.evaluate(() => document.activeElement.classList.contains('fgb-t'));
        r.panelStillOpen = where !== 'panel' || await page.evaluate(() => document.querySelector('#cart').classList.contains('on'));
        const cr = f.locator('.fgb-th .gbx-cr');
        const c = await cr.boundingBox(); await page.mouse.click(c.x + c.width / 2, c.y + c.height / 2); await page.waitForTimeout(350);
        r.criteria = await page.evaluate(() => { const p = document.querySelector('.gbx-cp:popover-open'); return p ? p.querySelectorAll('li').length : null; });
        await page.screenshot({ path: path.join(OUT, `criteria-${t}.png`) });
        await page.keyboard.press('Escape'); await page.waitForTimeout(250);
        r.criteriaEscCloses = await page.evaluate(() => !document.querySelector('.gbx-cp:popover-open'));
        r.swOpen = await page.evaluate(() => document.documentElement.scrollWidth);
      }
      r.errors = errors.splice(0);
      res.shop.push(r);
    }
    await ctx.close();
  }
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  // The admin: Off as shipped, then Testing with the pickers, then Live.
  await admin(browser, async (page, card) => {
    res.admin.default = await card.locator('[aria-checked="true"]').first().textContent();
    await shot(page, card, 'admin-off-1280.png');
    await page.screenshot({ path: path.join(OUT, 'admin-overview-1280.png'), fullPage: true });
    await mode(page, card, 'live');
    await shot(page, card, 'admin-live-1280.png');
    await mode(page, card, 'testing');
    await page.selectOption('[data-fk="x-glow"]', 'G1'); await page.waitForTimeout(700);
    await shot(page, card, 'admin-testing-1280.png');
    res.admin.saved = await page.evaluate(async () => (await (await fetch('/admin-api/free-gifts', { headers: { Accept: 'application/json' } })).json()).config.extras);
  });
  // The shop from 127.0.0.1, which IS on the Testing list.
  await shop(browser, 'listed');
  // Take this address off the list (another one stays on it): now it is a stranger.
  await admin(browser, async (page) => {
    await page.fill('[data-fk="tester-ip"]', '203.0.113.7'); await page.fill('[data-fk="tester-label"]', 'Owner phone');
    await page.locator('.fg-ipadd button', { hasText: 'Add IP' }).click(); await page.waitForTimeout(700);
    await page.locator('button[aria-label="Remove 127.0.0.1"]').click(); await page.waitForTimeout(700);
    res.admin.testersAfter = await page.evaluate(async () => (await (await fetch('/admin-api/free-gifts', { headers: { Accept: 'application/json' } })).json()).config.testers);
  });
  await shop(browser, 'unlisted');
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'measured.json'), JSON.stringify(res, null, 1));
  console.log(JSON.stringify(res, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
