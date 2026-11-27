/* Lane GSX (the owner, 27 Nov 2026): a gift-only mascara counted back to 1 stayed "Out of stock -- skipped", and he
   wanted to delete gift-only products. Shoots, against FG_SEED=gsx-seed.php sh tools/fg-preview.sh <port>:
     admin 1280  the range row before (red, with the gift stock control on the row), the product editor's save with
                 the dropdown still on "Out of stock" (the owner's exact move), the row after (in stock); Gift products
                 -> Delete -> "Are you sure?" -> gone from the list and from the range
     admin 390   the same row, + on its inline gift stock -> "Gift stock · 1"
     owner app   Free gifts -> Gift stock: the trash on a gift-only row -> the confirm sheet -> deleted
   Measures: every control clickable on the first try (elementFromPoint), scrollWidth, console errors.
   BASE=http://127.0.0.1:<port> APP=/<owner-app-path> node tools/gsx-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, APP = process.env.APP, OUT = path.resolve(__dirname, '../docs/lane-gsx');
fs.mkdirSync(OUT, { recursive: true });
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const hitFn = (sel) => { const el = document.querySelector(sel); if (!el) return null; el.scrollIntoView({ block: 'center' }); const b = el.getBoundingClientRect(), h = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return h === el || el.contains(h); };

async function admin(browser, w, report) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('/free-gifts/products/') ) errors.push(r.status() + ' ' + r.url()); });
  await page.goto(BASE + '/admin/login');
  await page.fill('#email', 'owner@example.com');
  await page.fill('#password', 'preview-password');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  const open = async () => {
    await page.evaluate(() => window.go('freegifts'));
    await page.waitForSelector('.fg-gi', { timeout: 20000 }).catch(() => {});
    const has = await page.evaluate(() => [...document.querySelectorAll('.fg-gi')].some((r) => /Maybelline/.test(r.textContent)));
    if (!has) { const tab = page.locator('button', { hasText: /AED 100\b/ }).first(); if (await tab.count()) await tab.click(); await page.waitForTimeout(400); }
  };
  const row = () => page.evaluate(() => {
    const r = [...document.querySelectorAll('.fg-gi')].find((x) => /Maybelline/.test(x.textContent));
    if (!r) return null;
    r.scrollIntoView({ block: 'center' });
    const plus = [...r.querySelectorAll('.fg-gs-b')].pop();
    const b = plus ? plus.getBoundingClientRect() : null, h = b ? document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2) : null;
    return { badge: r.querySelector('.pill').textContent, cls: r.className, inlineGiftStock: !!r.querySelector('.fg-gi-gs'), plusClickable: plus ? (h === plus || plus.contains(h)) : null, scrollW: document.documentElement.scrollWidth };
  });
  await open();
  const before = await row();
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/admin-${w}-1-range-row-out.png` });
  const out = { w, before };
  if (w === 1280) {
    // the owner's move: the product editor, count typed to 1, the dropdown left on "Out of stock"
    const pid = await page.evaluate(async () => {
      const t = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const d = await (await fetch('/admin-api/free-gifts', { headers: { Accept: 'application/json' } })).json();
      const p = d.gift_products.find((x) => /Maybelline/.test(x.name));
      const r = await fetch('/admin-api/product-editor-save/' + p.id, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': t }, body: JSON.stringify({ manage_stock: true, stock: 1, stock_status: 'outofstock' }) });
      return { id: p.id, status: r.status };
    });
    out.editorSave = pid;
    out.serverSays = await page.evaluate(async (id) => { const d = await (await fetch('/admin-api/free-gifts', { headers: { Accept: 'application/json' } })).json(); return d.gift_products.find((x) => x.id === id); }, pid.id);
    await page.reload(); await page.waitForTimeout(800);
    await open();
    out.after = await row();
    await page.screenshot({ path: `${OUT}/admin-${w}-2-range-row-back-in-stock.png` });
    // Gift products -> Delete
    await page.locator('button', { hasText: 'Gift products' }).first().click();
    await page.waitForSelector('[data-fk="gdel-' + pid.id + '"]', { timeout: 15000 });
    out.deleteClickable = await page.evaluate(hitFn, '[data-fk="gdel-' + pid.id + '"]');
    await page.screenshot({ path: `${OUT}/admin-${w}-3-gift-products-delete.png` });
    await page.click('[data-fk="gdel-' + pid.id + '"]');
    await page.waitForSelector('#kbbSureBg', { state: 'visible', timeout: 5000 }).catch(() => {});
    out.question = await page.evaluate(() => document.getElementById('kbbSureText')?.textContent || '');
    await page.screenshot({ path: `${OUT}/admin-${w}-4-are-you-sure.png` });
    const [res] = await Promise.all([page.waitForResponse((r) => r.request().method() === 'DELETE' && r.url().includes('/free-gifts/products/')), page.click('#kbbSureYes')]);
    out.deleteStatus = res.status();
    await page.waitForTimeout(600);
    out.stillListed = await page.evaluate((id) => !!document.querySelector('[data-fk="gdel-' + id + '"]'), pid.id);
    await page.screenshot({ path: `${OUT}/admin-${w}-5-deleted.png` });
  } else {
    // the other way back: + on the row's own gift stock
    const r = page.locator('.fg-gi', { hasText: 'Maybelline' }).locator('.fg-gs-b').last();
    await Promise.all([page.waitForResponse((x) => x.url().includes('/gift-stock')), r.click()]);
    await page.waitForTimeout(500);
    out.afterPlus = await row();
    await page.screenshot({ path: `${OUT}/admin-${w}-2-range-row-gift-stock-1.png` });
  }
  out.errors = errors;
  report.push(out);
  await ctx.close();
}

async function ownerApp(browser, report) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, userAgent: IPHONE, isMobile: true, hasTouch: true });
  await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto(BASE + APP, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'gsx');
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/api/enrol')), page.click('[data-enrol] button[type=submit]')]);
  await page.waitForTimeout(1500);
  await page.keyboard.press('Escape');
  await page.evaluate(() => { location.hash = '#/gifts'; });
  await page.waitForSelector('[data-gstock] [data-gdelp]', { timeout: 20000 });
  const target = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-gdelp]')].find((x) => /Mung Bean/.test(x.getAttribute('aria-label'))) || document.querySelector('[data-gdelp]'); return { id: b.getAttribute('data-gdelp'), name: b.getAttribute('aria-label') }; });
  const sel = '[data-gdelp="' + target.id + '"]';
  const out = { target, clickable: await page.evaluate(hitFn, sel), scrollW: await page.evaluate(() => document.documentElement.scrollWidth) };
  await page.waitForTimeout(300);
  await page.screenshot({ path: `${OUT}/oa-390-1-gift-stock-trash.png` });
  await page.click(sel);
  await page.waitForTimeout(600);
  await page.screenshot({ path: `${OUT}/oa-390-2-confirm.png` });
  const btn = page.locator('.sheet button, [role=dialog] button', { hasText: /^Delete$/ }).last();
  const [res] = await Promise.all([page.waitForResponse((r) => r.url().includes('/gifts/products/' + target.id + '/delete')), btn.click()]);
  out.status = res.status();
  await page.waitForTimeout(800);
  out.stillListed = await page.evaluate((s) => !!document.querySelector(s), sel);
  await page.screenshot({ path: `${OUT}/oa-390-3-deleted.png` });
  out.errors = errors;
  report.push({ ownerApp: out });
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch();
  const report = [];
  // MODE=desk on a fresh seed (1280: the editor's save, then Delete); MODE=phone on another (390 + the owner app)
  if (process.env.MODE !== 'phone') await admin(browser, 1280, report);
  if (process.env.MODE !== 'desk') { await admin(browser, 390, report); await ownerApp(browser, report); }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'report-' + (process.env.MODE || 'all') + '.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
