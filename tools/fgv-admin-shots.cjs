/* Lane FGV: Marketing -> Free gifts -> Ranges & gifts -> <range> -> Add
   (mask) products -> search "tint" -> Choose variation -> Black -> Save range.
   At 1280 (and 390 for the chooser): the product with options is no longer
   greyed out, its variations list with their own photo, "Colour: …" and
   stock, the picked one lands as its own gift row ("Glow Lip Tint – Black"),
   and after Save the server's copy carries the variant id.
   FG_SEED=fgv-seed.php sh tools/fg-preview.sh 11780
   BASE=http://127.0.0.1:11780 node tools/fgv-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/fgv-shots');
fs.mkdirSync(OUT, { recursive: true });
const wait = (p, ms) => p.waitForTimeout(ms);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = [];
  for (const w of [1280, 390]) {
    const H = w === 390 ? 844 : 900;
    const ctx = await browser.newContext({ viewport: { width: w, height: H }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('favicon')) errors.push(r.status() + ' ' + r.url()); });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.evaluate(() => { try { localStorage.removeItem('kbb.freegifts.range'); } catch (e) {} window.go('freegifts'); });
    await page.locator('[data-screen="freegifts"] .fg-status').waitFor();
    await page.locator('#fg-tab-ranges').click();
    await wait(page, 300);
    await page.locator('.fg-rtab', { hasText: '2,000+' }).first().click();
    await wait(page, 300);
    const r = { w };
    r.rowsBefore = await page.$$eval('.fg-gi-n b', (b) => b.map((e) => e.textContent));
    await page.locator('[data-fk^="add-mask-"]').first().click();
    await page.locator('.fg-modal').waitFor();
    await page.fill('[data-fk="pk-q"]', 'tint');
    await page.waitForFunction(() => document.querySelector('.fg-pk-st') && /match/.test(document.querySelector('.fg-pk-st').textContent));
    await wait(page, 200);
    const res = page.locator('.fg-res', { hasText: 'Glow Lip Tint' }).first();
    r.resultDisabled = await res.isDisabled();
    r.resultState = await res.locator('.fg-state-b').textContent();
    if (w === 1280) await page.screenshot({ path: `${OUT}/1-picker-variable-product-${w}.png` });
    // A real click on the row: is the row what is under the pointer?
    r.resultClickable = await res.evaluate((b) => { const q = b.getBoundingClientRect(); const h = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2); return !!h && (h === b || b.contains(h)); });
    await res.click();
    await page.locator('.fg-vars').waitFor();
    await wait(page, 200);
    r.variations = await page.$$eval('.fg-vars .fg-res', (b) => b.map((e) => e.innerText.replace(/\s+/g, ' ').trim()));
    r.variationPhotos = await page.$$eval('.fg-vars .fg-res img', (i) => i.map((e) => e.getAttribute('src').split('/').pop()));
    await page.screenshot({ path: `${OUT}/2-choose-variation-${w}.png` });
    if (w === 390) { r.scrollW = await page.evaluate(() => document.documentElement.scrollWidth); r.errors = errors; report.push(r); await ctx.close(); continue; }
    await page.locator('.fg-vars .fg-res', { hasText: 'Black' }).click();
    await wait(page, 200);
    r.pickedStatus = await page.locator('.fg-pk-st').textContent();
    r.blackPressed = await page.locator('.fg-vars .fg-res', { hasText: 'Black' }).getAttribute('aria-pressed');
    await page.screenshot({ path: `${OUT}/3-black-picked-${w}.png` });
    await page.locator('.fg-pk-foot .btn').click();
    await wait(page, 300);
    r.rowsAfterPick = await page.$$eval('.fg-gi-n b', (b) => b.map((e) => e.textContent));
    await page.locator('[data-fk^="save-"]').click();
    await page.locator('#toast.show').waitFor(); await wait(page, 400);
    r.toast = await page.locator('#toast').textContent();
    const need = await page.evaluate(() => { const c = document.querySelector('#content'); return c.scrollHeight + c.getBoundingClientRect().top + 4; });
    await page.setViewportSize({ width: w, height: Math.max(H, Math.ceil(need)) }); await wait(page, 200);
    await page.screenshot({ path: `${OUT}/4-saved-range-${w}.png` });
    await page.setViewportSize({ width: w, height: H });
    r.saved = await page.evaluate(async () => {
      const base = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/free-gifts';
      const d = await (await fetch(base, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json();
      const rg = d.ranges.filter((v) => v.max === null)[0];
      return rg.gifts.filter((g) => g.variant_id).map((g) => ({ product_id: g.product_id, variant_id: g.variant_id, name: g.product && g.product.name, stock: g.product && g.product.stock }));
    });
    r.scrollW = await page.evaluate(() => document.documentElement.scrollWidth);
    r.errors = errors;
    report.push(r);
    await ctx.close();
  }
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
