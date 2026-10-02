/*
 * Lane RF — Appearance → Product page → Desktop sections, photographed and
 * measured in Chromium: the product page in the default order and after a
 * reorder made THROUGH THE ADMIN TAB (a real mouse drag on the handle, a ↓
 * button, and Save changes), at 1280 and 1440, and the phone at 390 to show it
 * is unchanged.
 *
 *   sh tools/rf-preview.sh 9860
 *   RF_BASE=http://127.0.0.1:9860 node tools/rf-shots.cjs
 *
 * Writes docs/rf-shots/*.png and docs/rf-shots/MEASUREMENTS-shots.json.
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, NEVER BY A SCRIPT THE SHOP SERVES.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RF_BASE || 'http://127.0.0.1:9860';
const OUT = path.join(__dirname, '..', 'docs', 'rf-shots');
const CHROME = process.env.RF_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const SLUG = 'pdp-heartleaf-toner';
const M = {};

const SEL = { columns: '.pdp-page > .pdp', buytogether: '.pdp-page > .kbb-fbt', details: '.pdp-page > .pm-details', reviews: '.pdp-page > .sr', related: '.pdp-page > .ymal' };

async function login(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function api(page, body) {
  return page.evaluate(async (body) => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' };
    const r = await fetch('/admin-api/product-page', { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify(body) });
    return { status: r.status, body: await r.json() };
  }, body);
}

async function openTab(page) {
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  await page.evaluate(() => window.go('productpage'));
  await page.waitForSelector('[data-pdstab]', { timeout: 15000 });
  await page.click('[data-pdstab]');
  await page.waitForSelector('#pdsList');
  await page.waitForTimeout(2500);
}

async function listState(page) {
  return page.evaluate(() => [...document.querySelectorAll('#pdsList [data-pds-row]')].map((r) => r.getAttribute('data-pds-row')));
}

/** The four blocks top to bottom, their rects, and the gap above each. */
async function layout(page) {
  return page.evaluate((sel) => {
    const seen = [];
    for (const [k, s] of Object.entries(sel)) {
      const e = document.querySelector(s);
      if (!e || getComputedStyle(e).display === 'none') continue;
      const b = e.getBoundingClientRect();
      seen.push({ k, top: Math.round((b.top + scrollY) * 100) / 100, bottom: Math.round((b.bottom + scrollY) * 100) / 100, left: Math.round(b.left * 100) / 100, width: Math.round(b.width * 100) / 100, height: Math.round(b.height * 100) / 100 });
    }
    seen.sort((a, b) => a.top - b.top);
    seen.forEach((s, i) => { s.gapAbove = i === 0 ? null : Math.round((s.top - seen[i - 1].bottom) * 100) / 100; });
    const pg = document.querySelector('.pdp-page');
    return {
      order: seen.map((s) => s.k), blocks: seen,
      wrapperClass: pg.className, pageHeight: document.documentElement.scrollHeight, scrollWidth: document.documentElement.scrollWidth,
      display: getComputedStyle(pg).display,
    };
  }, SEL);
}

async function product(browser, w, name) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  await p.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(300);
  const r = await layout(p);
  await p.screenshot({ path: path.join(OUT, `${name}-${w}.png`), fullPage: true });
  await ctx.close();
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
    M.reset = (await api(page, { dsections: { order: ['buytogether', 'details', 'reviews', 'related'] } })).status;
    await ctx.close();
  }

  for (const w of [1280, 1440, 390]) M[`default-${w}`] = await product(browser, w, 'product-default');

  // The tab as shipped, at 1280 and 390.
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await login(page);
    await openTab(page);
    M[`tab-${w}`] = {
      list: await listState(page),
      strip: await page.evaluate(() => [...document.querySelectorAll('#ppStrip .ectab')].map((b) => b.textContent.replace(/\d+$/, '').trim())),
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
    };
    await page.screenshot({ path: path.join(OUT, `admin-tab-${w}.png`), fullPage: true });
    await ctx.close();
  }

  // A drag in progress at 1280 with the mouse: Reviews up over Buy these
  // together; then ↓ on Buy these together; the laptop preview photographed
  // before Save (live), then Save.
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await login(page);
    await openTab(page);
    const h = await page.$('[data-pds-handle="reviews"]');
    const t = await page.$('[data-pds-row="buytogether"]');
    // Centred, not merely "in view": the console's sticky Save bar sits over
    // the bottom of the viewport, and a press there lands on the bar.
    await h.evaluate((e) => e.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(200);
    const hb = await h.boundingBox();
    const tb = await t.boundingBox();
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
    await page.mouse.down();
    await page.mouse.move(hb.x + 20, hb.y - 20, { steps: 4 });
    await page.mouse.move(tb.x + 220, tb.y + 10, { steps: 8 });
    await page.waitForTimeout(200);
    M['drag-in-progress'] = await page.evaluate(() => ({
      dragging: [...document.querySelectorAll('.pds-row.dragging')].map((r) => r.getAttribute('data-pds-row')),
      over: [...document.querySelectorAll('.pds-row.over')].map((r) => r.getAttribute('data-pds-row')),
    }));
    await page.screenshot({ path: path.join(OUT, 'admin-drag-in-progress-1280.png') });
    await page.mouse.up();
    await page.waitForTimeout(400);
    M['after-drop'] = await listState(page);
    await page.click('[data-pds-down="buytogether"]');
    await page.waitForTimeout(400);
    M['after-down-button'] = await listState(page);
    M['preview-frame-live'] = await page.evaluate(() => {
      const fr = document.querySelector('[data-ppframe="desktop"]');
      const pg = fr && fr.contentDocument && fr.contentDocument.querySelector('.pdp-page');
      return pg ? { className: pg.className, style: pg.getAttribute('style').split(';').map((s) => s.trim()).filter((s) => s.startsWith('--pds')) } : null;
    });
    await page.screenshot({ path: path.join(OUT, 'admin-after-drop-1280.png'), fullPage: true });
    const pane = await page.$('[data-ppv="desktop"]');
    if (pane) {
      await page.evaluate(() => { const fr = document.querySelector('[data-ppframe="desktop"]'); fr.contentWindow.scrollTo(0, 950); });
      await page.waitForTimeout(300);
      await pane.screenshot({ path: path.join(OUT, 'admin-preview-live-1280.png') });
    }
    await page.click('#ppSave');
    await page.waitForTimeout(2500);
    M['after-save'] = { list: await listState(page), bar: await page.evaluate(() => document.getElementById('ppDirty').textContent) };
    await page.screenshot({ path: path.join(OUT, 'admin-after-save-1280.png'), fullPage: true });
    await ctx.close();
  }

  for (const w of [1280, 1440, 390]) M[`reordered-${w}`] = await product(browser, w, 'product-reordered');

  // The one pair normal flow would have collapsed: Buy these together straight
  // after Reviews. Set through the endpoint; the gap must be Reviews' 56px.
  {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await login(page);
    M['set-fbt-after-reviews'] = (await api(page, { dsections: { order: ['details', 'reviews', 'buytogether', 'related'] } })).status;
    await ctx.close();
  }
  for (const w of [1280, 1440]) M[`fbt-after-reviews-${w}`] = await product(browser, w, 'product-fbt-after-reviews');

  // And back to the default, so the preview is left as it ships.
  {
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    await login(page);
    M['reset-end'] = (await api(page, { dsections: { order: ['buytogether', 'details', 'reviews', 'related'] } })).status;
    await ctx.close();
  }

  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS-shots.json'), JSON.stringify(M, null, 1));
  console.log(JSON.stringify(M, (k, v) => (k === 'blocks' ? v.map((b) => `${b.k} top=${b.top} h=${b.height} gap=${b.gapAbove} w=${b.width}`) : v), 1));
  await browser.close();
})();
