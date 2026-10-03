/*
 * Lane RG — photographed and measured in Chromium.
 *
 *   sh tools/rg-preview.sh 9870
 *   RG_BASE=http://127.0.0.1:9870 node tools/rg-shots.cjs <phase> [steps]
 *
 * Every setting is saved THROUGH THE REAL ADMIN ENDPOINT (POST
 * /admin-api/product-page) as the owner would; every rectangle is read by
 * this harness, never by a script the shop serves. Writes docs/rg-shots/
 * <phase>-*.png and docs/rg-shots/<phase>-MEASUREMENTS.json.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { measure } = require('./rg-measure.cjs');

const BASE = process.env.RG_BASE || 'http://127.0.0.1:9870';
const OUT = path.join(__dirname, '..', 'docs', 'rg-shots');
const CHROME = process.env.RG_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const SLUG = process.env.RG_SLUG || 'pdp-heartleaf-toner';
const PHASE = process.argv[2] || 'before';
const STEPS = (process.argv[3] || 'all').split(',');
const M = {};
fs.mkdirSync(OUT, { recursive: true });

async function login(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

async function api(page, body, method = 'POST') {
  return page.evaluate(async ([body, method]) => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' };
    const r = await fetch('/admin-api/product-page', { method, credentials: 'same-origin', headers: h, body: method === 'POST' ? JSON.stringify(body) : undefined });
    return { status: r.status, body: await r.json() };
  }, [body, method]);
}

/** The Sections tab's own save: the whole module list, one row changed. */
async function sectionsSet(page, key, device, on) {
  const cur = await api(page, null, 'GET');
  const rows = cur.body.sections.map((s) => ({ key: s.key, desktop: s.desktop, mobile: s.mobile }));
  rows.find((r) => r.key === key)[device] = on;
  return api(page, { sections: rows });
}

/** What is drawn of the bundles block, read in the harness. */
async function bundles(page) {
  return page.evaluate(() => {
    const vis = (e) => !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0;
    const sec = document.querySelector('.pm-bundles');
    const label = document.querySelector('.pm-bundles .opt-label');
    const rows = [...document.querySelectorAll('.pm-bundles .variant')].filter(vis).length;
    const pl = document.querySelector('.pm-paylater');
    return {
      section: vis(sec) ? Math.round(sec.getBoundingClientRect().height) : 0,
      label: vis(label) ? label.textContent.trim().replace(/\s+/g, ' ') : null,
      visibleRows: rows,
      paylater: vis(pl) ? Math.round(pl.getBoundingClientRect().height) : 0,
      wrapper: document.querySelector('.pdp-page').className,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

async function shotProduct(browser, w, name, opts = {}) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: 1 });
  const p = await ctx.newPage();
  await p.goto(`${BASE}/product/${opts.slug || SLUG}/`, { waitUntil: 'networkidle' });
  await p.waitForTimeout(300);
  const out = { bundles: await bundles(p) };
  if (opts.details) {
    out.measure = await measure(p);
    const det = await p.$('.pm-details');
    for (const [i, label] of [[0, 'description'], [1, 'ingredients']]) {
      await p.evaluate((i) => { const t = document.querySelectorAll('.details .dtab')[i]; if (t) t.click(); }, i);
      await p.waitForTimeout(150);
      await det.scrollIntoViewIfNeeded();
      const box = await det.boundingBox();
      await p.screenshot({ path: path.join(OUT, `${PHASE}-${name}-${label}-${w}.png`), clip: { x: 0, y: box.y, width: w, height: Math.min(box.height, 420) } });
    }
  }
  if (opts.buy) {
    out.measure = out.measure || await measure(p);
    const rects = Object.values(out.measure.buy).filter((r) => r && typeof r === 'object');
    const top = w < 600 ? 0 : Math.max(0, Math.min(...rects.map((r) => r.top)) - 24);
    const bottom = Math.max(...rects.map((r) => r.bottom)) + 24;
    await p.screenshot({ path: path.join(OUT, `${PHASE}-${name}-${w}.png`), clip: { x: 0, y: top, width: w, height: bottom - top }, fullPage: true });
  }
  await ctx.close();
  return out;
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const admin = await ctx.newPage();
  await login(admin);
  const want = (s) => STEPS.includes('all') || STEPS.includes(s);

  if (want('details')) {
    M.details = {};
    for (const w of [390, 1280]) M.details[w] = (await shotProduct(browser, w, 'details', { details: true })).measure;
  }

  if (want('bundles')) {
    // The owner's exact action: Sections → "Options / bundles" → Desktop OFF, saved.
    M.bundlesSave = (await sectionsSet(admin, 'options', 'desktop', false)).status;
    M.bundles = {};
    for (const w of [390, 880, 881, 890, 900, 1000, 1280, 1440, 1608]) {
      M.bundles[w] = (await shotProduct(browser, w, 'bundles-desktop-off', { buy: [390, 1280, 1608].includes(w) })).bundles;
    }
    M.bundlesRestore = (await sectionsSet(admin, 'options', 'desktop', true)).status;
  }

  if (want('buy')) {
    M.buy = {};
    for (const w of [390, 1280, 1440]) {
      const r = await shotProduct(browser, w, 'buycolumn-default', { buy: true });
      M.buy[w] = { blocks: r.measure.buy, paylater: r.bundles.paylater, scrollWidth: r.bundles.scrollWidth, wrapper: r.bundles.wrapper };
    }
  }

  /* The gap under the tab row at two settings per device, saved through the
     Layout half of the same endpoint. */
  if (want('gaps')) {
    M.gaps = {};
    for (const [m, d] of [[8, 30], [28, 8]]) {
      const key = `phone${m}-laptop${d}`;
      M.gaps[key] = { save: (await api(admin, { layout: { tab_body_gap: m, tab_body_gap_d: d } })).status };
      for (const w of [390, 1280]) {
        const r = await shotProduct(browser, w, `gap-${key}`, { details: true });
        M.gaps[key][w] = r.measure.tabs.map((t) => `${t.tab}: ${t.gap}px`);
      }
    }
    M.gapsRestore = (await api(admin, { layout: { tab_body_gap: 18, tab_body_gap_d: 18 } })).status;
  }

  /* Tabby & Tamara on a laptop: on (the new default) and switched off through
     Desktop sections' own POST; the phone unchanged either way. */
  if (want('paylater')) {
    M.paylater = {};
    for (const state of ['on', 'off']) {
      M.paylater[state] = { save: (await api(admin, { dsections: { laptop: { paylater: state === 'on' } } })).status };
      for (const w of [390, 1280, 1440]) {
        const r = await shotProduct(browser, w, `paylater-laptop-${state}`, { buy: true });
        M.paylater[state][w] = { blocks: r.measure.buy, paylater: r.bundles.paylater, wrapper: r.bundles.wrapper, scrollWidth: r.bundles.scrollWidth };
      }
    }
    await api(admin, { dsections: { laptop: { paylater: true } } });
  }

  async function openProductPage() {
    const a = await ctx.newPage();
    await a.setViewportSize({ width: 1440, height: 1100 });
    await a.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await a.waitForTimeout(600);
    await a.evaluate(() => window.go('productpage'));
    await a.waitForSelector('[data-pdstab]', { timeout: 15000 });
    return a;
  }

  /* The admin: Desktop sections, ↑ clicks and a real mouse drag, Save; then
     the page at 1280 / 1440 and the phone. */
  if (want('reorder')) {
    const a = await openProductPage();
    await a.click('[data-pdstab]');
    await a.waitForSelector('#pdsBuy');
    await a.waitForTimeout(2500);
    await a.screenshot({ path: path.join(OUT, `${PHASE}-admin-desktop-sections-default.png`), fullPage: true });
    for (let i = 0; i < 2; i++) { await a.click('#pdsBuy [data-pds-up="paylater"]'); await a.waitForTimeout(150); }
    await a.dragAndDrop('#pdsBuy [data-pds-handle="bundles"]', '#pdsBuy [data-pds-row="cart"]');
    await a.waitForTimeout(1500);
    M.adminOrderAfterDrag = await a.evaluate(() => [...document.querySelectorAll('#pdsBuy [data-pds-row]')].map((r) => r.getAttribute('data-pds-row')));
    await a.screenshot({ path: path.join(OUT, `${PHASE}-admin-desktop-sections-dragged.png`), fullPage: true });
    await a.click('#ppSave');
    await a.waitForTimeout(1500);
    M.adminSavedMessage = await a.evaluate(() => (document.getElementById('ppDirty') || {}).textContent);
    await a.close();
    M.reorder = {};
    for (const w of [390, 1280, 1440]) {
      const r = await shotProduct(browser, w, 'buycolumn-reordered', { buy: true });
      const blocks = r.measure.buy;
      const seen = Object.entries(blocks).filter(([, v]) => v && typeof v === 'object').sort((x, y) => x[1].top - y[1].top);
      M.reorder[w] = { wrapper: r.bundles.wrapper, order: seen.map(([k]) => k), gaps: seen.slice(1).map(([k, v], i) => `${seen[i][0]}→${k}: ${v.top - seen[i][1].bottom}px`), blocks, scrollWidth: r.bundles.scrollWidth };
    }
    M.reorderRestore = (await api(admin, { dsections: { buy: ['title', 'price', 'short', 'paylater', 'bundles', 'ready', 'delivery', 'cart', 'auth', 'trust', 'paychips'] } })).status;
  }

  /* The admin controls, each tab photographed; one switch flipped on
     Desktop sections and read back off the Sections tab. */
  if (want('admin')) {
    const a = await openProductPage();
    const tab = async (sel, name) => {
      await a.click(sel);
      await a.waitForTimeout(1800);
      await a.screenshot({ path: path.join(OUT, `${PHASE}-admin-${name}.png`), fullPage: true });
    };
    await a.click('[data-pdstab]');
    await a.waitForTimeout(600);
    await a.click('#pdsBuy [data-pds-sw="bundles"]');
    await a.waitForTimeout(1500);
    await a.screenshot({ path: path.join(OUT, `${PHASE}-admin-desktop-sections-bundles-off-unsaved.png`), fullPage: true });
    M.adminBundlesSwitch = await a.evaluate(() => ({ desktopSections: document.querySelector('#pdsBuy [data-pds-sw="bundles"]').getAttribute('aria-checked'), sectionsModel: PP.sections.find((s) => s.key === 'options').desktop }));
    await a.click('[data-pptab="sections"]');
    await a.waitForTimeout(800);
    M.adminSectionsTabShows = await a.evaluate(() => { const i = PP.sections.findIndex((s) => s.key === 'options'); const t = document.querySelector('[data-pp="' + i + '"][data-k="desktop"]'); return t ? t.getAttribute('aria-checked') : null; });
    await a.screenshot({ path: path.join(OUT, `${PHASE}-admin-sections-same-switch.png`), fullPage: true });
    await a.close();
    const b2 = await openProductPage();
    const tab2 = async (sel, name) => {
      await b2.click(sel);
      await b2.waitForTimeout(1800);
      await b2.screenshot({ path: path.join(OUT, `${PHASE}-admin-${name}.png`), fullPage: true });
    };
    await tab2('[data-pptab="sp_page"]', 'spacing-page-tab-gaps');
    await tab2('[data-pptab="sp_buy"]', 'spacing-buy-paylater-gap');
    await tab2('[data-pmstab]', 'mobile-sections-heading-switches');
    await b2.close();
  }

  fs.writeFileSync(path.join(OUT, `${PHASE}-MEASUREMENTS.json`), JSON.stringify(M, null, 1));
  console.log(JSON.stringify(M));
  await browser.close();
})();
