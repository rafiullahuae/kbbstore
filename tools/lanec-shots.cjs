/* Lane C: per-product low-stock level and back orders that keep selling.
   BASE=http://127.0.0.1:PORT OA=/<owner-app-path>/ BO=<back-ordered id> LOW=<id> node tools/lanec-shots.cjs
   Shots go to storage/lanec-shots/. */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = process.env.BASE, OA = process.env.OA, BO = +process.env.BO, LOW = +process.env.LOW;
const OUT = path.resolve(__dirname, '../storage/lanec-shots');
fs.mkdirSync(OUT, { recursive: true });
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const UA_PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};

  // ── the admin, 1280 and 390 ─────────────────────────────────────────────
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const pg = await ctx.newPage();
    const errors = [];
    pg.on('pageerror', (e) => errors.push(String(e)));
    pg.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
    await pg.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await pg.fill('input[name=email]', 'owner@example.com');
    await pg.fill('input[name=password]', 'preview-password');
    await Promise.all([pg.waitForNavigation({ waitUntil: 'networkidle' }), pg.click('button[type=submit]')]);

    // Catalog -> Products: the back-ordered row.
    await pg.evaluate(() => window.go && window.go('catalog')); await wait(2500);
    const cell = pg.locator('.cp-bo').first();
    if (await cell.count()) { await cell.scrollIntoViewIfNeeded(); await wait(300); }
    await pg.screenshot({ path: `${OUT}/admin-catalog-${w}.png` });
    out['catalog' + w] = await pg.evaluate(() => {
      const n = document.querySelector('.cp-bo-n'), l = document.querySelector('.cp-bo');
      return { figure: n && n.textContent, colour: n && getComputedStyle(n).color, label: l && l.textContent, scrollWidth: document.documentElement.scrollWidth };
    });

    // Product editor: the back-ordered product's Stock card.
    await pg.evaluate((id) => window.peoEdit(id), BO); await wait(2500);
    const card = pg.locator('.peo-bo').first();
    if (await card.count()) { await card.scrollIntoViewIfNeeded(); await wait(300); }
    await pg.screenshot({ path: `${OUT}/admin-editor-backorder-${w}.png` });
    out['editorBO' + w] = await pg.evaluate(() => ({ badge: (document.querySelector('.peo-bo') || {}).innerText || null }));

    // Product editor: set a product's own low-stock level and save.
    if (w === 1280) {
      await pg.evaluate((id) => window.peoEdit(id), LOW); await wait(2500);
      const f = pg.locator('[data-bind=low_stock_amount]');
      await f.scrollIntoViewIfNeeded(); await f.fill('9'); await wait(200);
      await pg.screenshot({ path: `${OUT}/admin-editor-low-level-before-save-${w}.png` });
      const saved = pg.waitForResponse((r) => r.url().includes('/product-editor-save/') && r.request().method() === 'POST');
      await pg.locator('#peo-save').click();
      const res = await (await saved).json();
      await wait(800);
      await pg.locator('[data-bind=low_stock_amount]').scrollIntoViewIfNeeded();
      await pg.screenshot({ path: `${OUT}/admin-editor-low-level-saved-${w}.png` });
      out.editorLowSaved = res && res.product ? res.product.low_stock_amount : res;

      // Store -> Ecommerce -> General -> Stock & back orders.
      await pg.evaluate(() => window.go && window.go('ecommerce')); await wait(2500);
      const sel = pg.locator('[data-ec="admin_backorder_display"]').first();
      if (await sel.count()) await sel.scrollIntoViewIfNeeded();
      else await pg.locator('text=Stock & back orders').first().scrollIntoViewIfNeeded().catch(() => {});
      await wait(400);
      await pg.screenshot({ path: `${OUT}/admin-ecommerce-setting-${w}.png` });
    }
    out['adminErrors' + w] = errors;
    await ctx.close();
  }

  // ── the owner app, 390 ──────────────────────────────────────────────────
  {
    const ctx = await b.newContext({ userAgent: UA_PHONE, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    const pg = await ctx.newPage();
    const errors = [];
    pg.on('pageerror', (e) => errors.push(String(e)));
    await pg.goto(BASE + OA, { waitUntil: 'networkidle' }); await wait(800);
    if (await pg.locator('[data-enrol]').count()) {
      await pg.fill('[data-enrol] [name=email]', 'owner@example.com');
      await pg.fill('[data-enrol] [name=pin]', '482615');
      await pg.click('[data-enrol] button[type=submit]'); await wait(2500);
    }
    await pg.goto(BASE + OA + '#/products', { waitUntil: 'networkidle' }); await wait(2500);
    await pg.fill('[data-search]', 'Relief Sun'); await wait(2200);
    await pg.screenshot({ path: `${OUT}/ownerapp-products-390.png` });
    out.oaList = await pg.evaluate(() => { const t = document.querySelector('.stk.bo'); return t && { text: t.textContent, colour: getComputedStyle(t).color }; });

    await pg.goto(BASE + OA + '#/products/' + BO, { waitUntil: 'networkidle' }); await wait(2500);
    await pg.screenshot({ path: `${OUT}/ownerapp-product-backorder-390.png` });
    await pg.locator('[data-ed=inv]').click(); await wait(900);
    await pg.screenshot({ path: `${OUT}/ownerapp-inventory-backorder-390.png` });

    await pg.goto(BASE + OA + '#/products/' + LOW, { waitUntil: 'networkidle' }); await pg.reload({ waitUntil: 'networkidle' }); await wait(2500);
    await pg.locator('[data-ed=inv]').click(); await wait(900);
    await pg.fill('[data-low]', '7');
    await pg.screenshot({ path: `${OUT}/ownerapp-inventory-low-level-390.png` });
    const resP = pg.waitForResponse((r) => r.url().includes('/products/' + LOW) && r.request().method() === 'POST');
    await pg.locator('[data-save]').click();
    const res = await (await resP).json(); await wait(1200);
    await pg.screenshot({ path: `${OUT}/ownerapp-product-low-level-saved-390.png` });
    out.oaLowSaved = res.product ? res.product.low_stock_amount : res;
    out.oaErrors = errors;
    await ctx.close();
  }

  // ── the shop: the back-ordered product is still buyable ─────────────────
  for (const w of [1280, 390]) {
    const ctx = await b.newContext(w === 390 ? { userAgent: UA_PHONE, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : { userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36', viewport: { width: 1280, height: 900 } });
    const pg = await ctx.newPage();
    const errors = [];
    pg.on('pageerror', (e) => errors.push(String(e)));
    pg.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
    await pg.goto(BASE + '/product/relief-sun-rice-probiotics-spf50/', { waitUntil: 'networkidle' }); await wait(800);
    await pg.screenshot({ path: `${OUT}/shop-product-backorder-${w}.png` });
    const add = await pg.evaluate(async (pid) => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const r = await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: pid, quantity: 1 }) });
      return { status: r.status, body: (await r.text()).slice(0, 120) };
    }, BO);
    out['shop' + w] = await pg.evaluate(() => ({
      stockline: (document.querySelector('.stockline') || {}).innerText || null,
      soldOutText: /sold out/i.test(document.body.innerText),
      scrollWidth: document.documentElement.scrollWidth,
    }));
    out['shop' + w].add = add;
    out['shopErrors' + w] = errors;
    await ctx.close();
  }

  console.log(JSON.stringify(out, null, 1));
  await b.close();
})();
