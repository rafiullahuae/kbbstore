/* Lane GP: Growth & Marketing -> Free gifts -> Ranges & gifts -> Gift-only
   products, at 1280 and 390: the panel and list, the Media Library opened from
   the photo button, a product added, the inline edit, the remove confirm, the
   picker's "+ New gift-only product" and the new product picked as a gift --
   then /shop and the shop's search with the gift-only products absent.
   Numbers: scrollWidth, console errors, requests the panel makes.
     sh tools/gp-preview.sh 10880
     BASE=http://127.0.0.1:10880 node tools/gp-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-gp-shots');
fs.mkdirSync(OUT, { recursive: true });
const wait = (p, ms) => p.waitForTimeout(ms);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [1280, 390]) {
    const H = w === 390 ? 844 : 900;
    const ctx = await browser.newContext({ viewport: { width: w, height: H }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [], quickReqs = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.url().includes('/admin-api/free-gifts/quick')) quickReqs.push(r.method() + ' ' + r.url().replace(BASE, '')); });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('freegifts'));
    await page.locator('.fg-tab', { hasText: 'Ranges & gifts' }).click();
    await page.locator('[data-fk-panel="quick"] .fg-qr').first().waitFor();
    await wait(page, 300);
    const m = { scrollWidth: {} };
    const sw = async (k) => { m.scrollWidth[k] = await page.evaluate(() => document.documentElement.scrollWidth); };
    const shotPanel = async (name) => {
      const panel = page.locator('[data-fk-panel="quick"]');
      await panel.scrollIntoViewIfNeeded();
      await panel.screenshot({ path: `${OUT}/${name}-${w}.png` });
    };
    const tag = String(w).slice(0, 2) + Date.now().toString().slice(-4);

    await shotPanel('1-panel-list'); await sw('panel');
    m.rowHeight = await page.$eval('[data-fk-panel="quick"] .fg-qr', (e) => e.offsetHeight);

    // Add: name, prices, photo from the Media Library.
    await page.fill('[data-fk="qa-name"]', 'Snail Repair Mini Cream ' + tag);
    await page.fill('[data-fk="qa-cut"]', '55');
    await page.fill('[data-fk="qa-now"]', '39.50');
    await page.click('[data-fk="qa-photo"]');
    await page.locator('.mp-tile').first().waitFor();
    await wait(page, 400);
    await page.screenshot({ path: `${OUT}/2-media-library-${w}.png` });
    await page.locator('.mp-tile', { has: page.locator('img') }).first().click();
    await page.click('#mp-ok');
    await wait(page, 300);
    await shotPanel('3-add-filled');
    // A price mistake is caught before anything is sent.
    await page.fill('[data-fk="qa-now"]', '80');
    await page.click('[data-fk="qa-save"]'); await wait(page, 200);
    m.clientRefusal = await page.locator('[data-fk-panel="quick"] .fg-qa .fg-msg').textContent();
    await shotPanel('3b-add-refused');
    await page.fill('[data-fk="qa-now"]', '39.50');
    const t0 = Date.now();
    await page.click('[data-fk="qa-save"]');
    await page.locator('[data-fk-panel="quick"] .fg-qr', { hasText: 'Snail Repair Mini Cream ' + tag }).waitFor();
    m.addMs = Date.now() - t0;
    await wait(page, 200);
    await shotPanel('4-added-in-list'); await sw('added');

    // Inline edit.
    const row = page.locator('[data-fk-panel="quick"] .fg-qr', { hasText: 'Snail Repair Mini Cream ' + tag });
    await row.locator('button', { hasText: 'Edit' }).click();
    await page.fill('[data-fk="qe-name"]', 'Snail Repair Mini Cream 15ml ' + tag);
    await page.fill('[data-fk="qe-cut"]', '60');
    await page.fill('[data-fk="qe-now"]', '35');
    await shotPanel('5-edit-inline');
    await page.click('[data-fk="qe-save"]');
    await page.locator('[data-fk-panel="quick"] .fg-qr', { hasText: '15ml ' + tag }).waitFor();
    await wait(page, 200);
    await shotPanel('6-edited');

    // Remove: inline confirm; the one given on an order says so.
    const yuja = page.locator('[data-fk-panel="quick"] .fg-qr', { hasText: 'Yuja Niacin Mini Toner' });
    await yuja.locator('button', { hasText: 'Remove' }).click(); await wait(page, 150);
    m.removeConfirm = await page.locator('[data-fk-panel="quick"] .fg-confirm').textContent();
    await shotPanel('7-remove-confirm');
    await page.locator('[data-fk-panel="quick"] .fg-confirm button', { hasText: 'Keep it' }).click(); await wait(page, 150);

    // The picker: the new product is there at once, and the picker can make one.
    const rangeAdd = page.locator('[data-fk^="add-"]').first();
    await rangeAdd.scrollIntoViewIfNeeded();
    await rangeAdd.click();
    await page.locator('.fg-res').first().waitFor(); await wait(page, 300);
    await page.fill('[data-fk="pk-q"]', '15ml');
    await page.locator('.fg-res', { hasText: '15ml ' + tag }).waitFor(); await wait(page, 200);
    await page.screenshot({ path: `${OUT}/8-picker-finds-it-${w}.png` });
    await page.fill('[data-fk="pk-q"]', '');
    await page.click('[data-fk="pk-new"]'); await wait(page, 150);
    await page.fill('[data-fk="pq-name"]', 'Green Tea Mini Foam ' + tag);
    await page.fill('[data-fk="pq-cut"]', '28');
    await page.fill('[data-fk="pq-now"]', '19');
    await page.screenshot({ path: `${OUT}/9-picker-quick-add-${w}.png` });
    await page.click('[data-fk="pq-save"]');
    await page.locator('.fg-res', { hasText: 'Green Tea Mini Foam ' + tag }).waitFor(); await wait(page, 200);
    await page.screenshot({ path: `${OUT}/10-picker-created-${w}.png` });
    await page.locator('.fg-res', { hasText: 'Green Tea Mini Foam ' + tag }).click(); await wait(page, 200);
    if (await page.locator('.fg-ask button').count()) { await page.locator('.fg-ask button').first().click(); await wait(page, 200); }
    m.pickedState = await page.locator('.fg-res', { hasText: 'Green Tea Mini Foam ' + tag }).locator('.fg-state-b').textContent();
    await page.screenshot({ path: `${OUT}/11-picked-as-gift-${w}.png` });
    await page.locator('.fg-pk-foot button', { hasText: 'Done' }).click(); await wait(page, 300);
    const save = page.locator('button', { hasText: 'Save range' }).first();
    if (await save.count()) { await save.scrollIntoViewIfNeeded(); await save.click(); await wait(page, 900); }
    const gi = page.locator('.fg-gi', { hasText: 'Green Tea Mini Foam ' + tag }).first();
    if (await gi.count()) { await gi.scrollIntoViewIfNeeded(); await wait(page, 150); }
    await page.screenshot({ path: `${OUT}/12-range-saved-with-gift-${w}.png` });
    await sw('end');
    m.quickRequests = quickReqs;
    m.errors = errors;
    report[w] = m;

    // The shop: /shop and its search, with every gift-only product absent.
    const shop = await ctx.newPage();
    await shop.goto(BASE + '/shop/?s=Mini');
    await wait(shop, 500);
    await shop.screenshot({ path: `${OUT}/13-shop-search-mini-${w}.png` });
    const html = await shop.content();
    m.shopSearchShowsGift = ['Centella Calming Mini Mask', 'Yuja Niacin Mini Toner', 'Snail Repair Mini Cream', 'Green Tea Mini Foam'].filter((n) => html.includes(n));
    await shop.goto(BASE + '/shop/');
    await wait(shop, 400);
    const html2 = await shop.content();
    m.shopShowsGift = ['Centella Calming Mini Mask', 'Yuja Niacin Mini Toner', 'Snail Repair Mini Cream', 'Green Tea Mini Foam', 'Hydra Barrier Cream Travel Size'].filter((n) => html2.includes(n));
    await shop.screenshot({ path: `${OUT}/14-shop-${w}.png` });
    const api = await shop.goto(BASE + '/api/search?q=Mini');
    m.apiSearchShowsGift = (await api.text()).includes('Centella Calming');
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
