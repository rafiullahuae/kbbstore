/*
 * Lane QA — Appearance → Product page → Mobile sections, photographed at 1280
 * and 390: the tab as shipped, a drag in progress, the list after the drop,
 * and the product page reordered by that save.
 *
 *   sh tools/qa-preview.sh 9800
 *   QA_BASE=http://127.0.0.1:9800 node tools/qa-admin-shots.cjs
 *
 * The reorder is made THROUGH THE SCREEN (a real mouse drag on the handle and
 * a press of Save changes), not by posting to the endpoint, so the picture of
 * the product page afterwards is proof the tab drives the page.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.QA_BASE || 'http://127.0.0.1:9800';
const OUT = path.join(__dirname, '..', 'docs', 'qa-shots');
const CHROME = process.env.QA_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const M = {};

async function login(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function openTab(page) {
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  await page.evaluate(() => window.go('productpage'));
  await page.waitForSelector('[data-pmstab]', { timeout: 15000 });
  await page.click('[data-pmstab]');
  await page.waitForSelector('#pmsList');
  await page.waitForTimeout(2500);
}

async function listState(page) {
  return page.evaluate(() => [...document.querySelectorAll('#pmsList [data-pms-row]')].map((r) => r.getAttribute('data-pms-row') + (r.classList.contains('off') ? ' (off)' : '')));
}

async function phoneOrder(ctx, slug) {
  const p = await ctx.newPage();
  await p.setViewportSize({ width: 390, height: 844 });
  await p.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
  const r = await p.evaluate(() => {
    const sel = { trust: '.pm-trust', gallery: '.pdp-page .gallery', title: '.pm-title', short: '.pm-short', price: '.pm-price', paylater: '.pm-paylater', bundles: '.pm-bundles', ready: '.pm-ready', cart: '.pm-cart', delivery: '.pdp-page .kbb-cart-form > .pts-del', auth: '.pdp-page .kbb-cart-form > .pts-stack', paychips: '.pm-paychips', details: '.pm-details', reviews: '.pdp-page > .sr', related: '.pdp-page > .ymal' };
    const seen = [];
    for (const [k, s] of Object.entries(sel)) { const e = document.querySelector(s); if (!e || getComputedStyle(e).display === 'none') continue; const b = e.getBoundingClientRect(); seen.push([k, b.top + scrollY]); }
    seen.sort((a, b) => a[1] - b[1]);
    return { order: seen.map((s) => s[0]), scrollWidth: document.documentElement.scrollWidth };
  });
  await p.screenshot({ path: path.join(OUT, 'admin-after-product-390.png'), fullPage: true });
  await p.close();
  return r;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  // Start from what ships, whatever an earlier run saved.
  {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await login(page);
    await page.evaluate(async () => {
      const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
      const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' };
      const d = await (await fetch('/admin-api/product-page', { credentials: 'same-origin', headers: h })).json();
      const opts = {};
      d.msections.options.forEach((t) => t.fields.forEach((f) => { opts[f.key] = f.default; }));
      await fetch('/admin-api/product-page', { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify({ msections: { list: d.msections.defaults, options: opts } }) });
    });
    await ctx.close();
  }

  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await login(page);
    await openTab(page);
    M[`tab-${w}`] = { list: await listState(page), scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) };
    await page.screenshot({ path: path.join(OUT, `admin-tab-${w}.png`), fullPage: true });
    await ctx.close();
  }

  // A drag in progress, at 1280, with the mouse: "Delivery box" up over "Ready to ship".
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await login(page);
    await openTab(page);
    const h = await page.$('[data-pms-handle="delivery"]');
    const t = await page.$('[data-pms-row="ready"]');
    await h.scrollIntoViewIfNeeded();
    const hb = await h.boundingBox();
    const tb = await t.boundingBox();
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
    await page.mouse.down();
    await page.mouse.move(hb.x + 20, hb.y - 20, { steps: 4 });
    await page.mouse.move(tb.x + 200, tb.y + 10, { steps: 8 });
    await page.waitForTimeout(200);
    M['drag-in-progress'] = await page.evaluate(() => ({
      dragging: [...document.querySelectorAll('.pms-row.dragging')].map((r) => r.getAttribute('data-pms-row')),
      over: [...document.querySelectorAll('.pms-row.over')].map((r) => r.getAttribute('data-pms-row')),
    }));
    await page.screenshot({ path: path.join(OUT, 'admin-drag-in-progress-1280.png') });
    await page.mouse.up();
    await page.waitForTimeout(400);
    M['after-drop'] = await listState(page);
    await page.screenshot({ path: path.join(OUT, 'admin-after-drop-1280.png'), fullPage: true });

    // And one more with the ↓ button, and a section switched on: the trust rows.
    await page.click('[data-pms-on="trust"]');
    await page.click('#ppSave');
    await page.waitForTimeout(2500);
    M['after-save'] = await listState(page);
    await page.screenshot({ path: path.join(OUT, 'admin-after-save-1280.png'), fullPage: true });
    M['product-after-save'] = await phoneOrder(ctx, 'pdp-heartleaf-toner');
    await ctx.close();
  }

  // A drag in progress at 390 with a FINGER: pointer events on the handle.
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, hasTouch: true });
    const page = await ctx.newPage();
    await login(page);
    await openTab(page);
    const h = await page.$('[data-pms-handle="paychips"]');
    await h.scrollIntoViewIfNeeded();
    const hb = await h.boundingBox();
    const tb = await (await page.$('[data-pms-row="auth"]')).boundingBox();
    const x0 = hb.x + hb.width / 2, y0 = hb.y + hb.height / 2, y1 = tb.y + 12;
    await page.evaluate(({ x0, y0, y1 }) => {
      const h = document.querySelector('[data-pms-handle="paychips"]');
      const ev = (type, x, y) => new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 7, pointerType: 'touch', clientX: x, clientY: y, isPrimary: true });
      h.dispatchEvent(ev('pointerdown', x0, y0));
      document.dispatchEvent(ev('pointermove', x0, (y0 + y1) / 2));
      document.dispatchEvent(ev('pointermove', x0 + 60, y1));
      window.__qaUp = () => document.dispatchEvent(ev('pointerup', x0 + 60, y1));
    }, { x0, y0, y1 });
    M['touch-drag-in-progress'] = await page.evaluate(() => ({
      dragging: [...document.querySelectorAll('.pms-row.dragging')].map((r) => r.getAttribute('data-pms-row')),
      over: [...document.querySelectorAll('.pms-row.over')].map((r) => r.getAttribute('data-pms-row')),
    }));
    await page.screenshot({ path: path.join(OUT, 'admin-drag-in-progress-390.png') });
    await page.evaluate(() => window.__qaUp());
    await page.waitForTimeout(300);
    M['touch-after-drop'] = await listState(page);
    await page.screenshot({ path: path.join(OUT, 'admin-after-drop-390.png'), fullPage: true });
    await ctx.close();
  }

  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS-admin.json'), JSON.stringify(M, null, 2));
  console.log(JSON.stringify(M, null, 1));
  await browser.close();
})();
