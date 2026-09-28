/*
 * Lane SP2 screenshots.
 *
 * Two features, one screen: Catalog → Product editor.
 *
 *   HALF 1  "What is in the box", in all three pricing modes, and the
 *           hand-typed one after a member has been repriced — showing the
 *           anchor, today's total, the reduction and the resulting price.
 *
 *   HALF 2  "Search appearance" → Share image, auto-filled from a freshly
 *           chosen main image, and then the hand-picked case proving a main
 *           image change does NOT overwrite it.
 *
 * Every shot prints the numbers behind it as JSON: the measured widths, the
 * text of every money tile, and — for the fixed mode — the four figures the
 * panel is showing. The fils behind them are in the report; these are what the
 * owner sees.
 *
 * Modelled on tools/sp-shots.cjs, and the browser is passed as executablePath
 * rather than installed.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SP2_BASE || 'http://127.0.0.1:8700';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SP2_OUT || `${APP}/docs/lane-sp2-shots`;

async function measure(page) {
  return page.evaluate(() => {
    const text = (sel) => {
      const n = document.querySelector(sel);
      return n ? n.textContent.replace(/\s+/g, ' ').trim() : null;
    };

    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      /* THE NUMBER THAT MATTERS AT 390. scrollWidth greater than the viewport
         is a page with a horizontal scrollbar. */
      overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,

      mode: (document.querySelector('[data-bind="price_mode"]') || {}).value || null,
      priceBox: (document.querySelector('[data-bind="price_aed"]') || {}).value || null,
      saleBox: (document.querySelector('[data-bind="sale_aed"]') || {}).value || null,

      tiles: [...document.querySelectorAll('[data-peo-money]')]
        .map((n) => n.getAttribute('data-peo-money') + '=' + n.textContent.trim())
        .join(' | ') || null,

      /* The working-out, row by row, exactly as it is printed. */
      working: [...document.querySelectorAll('.peo-setwork > div')]
        .map((r) => r.querySelector('.k').textContent.trim() + ': '
          + r.querySelector('.v').textContent.replace(/\s+/g, ' ').trim()),
      workingRows: document.querySelectorAll('.peo-setwork > div').length,
      followNote: text('#peo-setfollow .peo-note'),
      reanchorButton: text('#peo-setreanchor'),

      ogState: text('.peo-ogstate'),
      ogAuto: document.querySelectorAll('.peo-ogstate.is-auto').length,
      ogHand: document.querySelectorAll('.peo-ogstate.is-hand').length,
      ogBox: (document.querySelector('#peo-og') || {}).value || null,
      mainImage: (document.querySelector('.peo-main-img img') || {}).src
        ? (document.querySelector('.peo-main-img img').src.slice(0, 46) + '…')
        : null,
      backToAuto: !!document.querySelector('#peo-ogauto'),

      /* Font sizes, measured once from the rendered styles rather than
         asserted, because CLAUDE.md asks for the numbers that matter. */
      workLabelPx: (() => {
        const n = document.querySelector('.peo-setwork .k');
        return n ? getComputedStyle(n).fontSize : null;
      })(),
      workValuePx: (() => {
        const n = document.querySelector('.peo-setwork .v');
        return n ? getComputedStyle(n).fontSize : null;
      })(),
    };
  });
}

async function shoot(page, name, w, h) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(500);

  const m = await measure(page);
  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
  console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }, null, 1));
}

async function both(page, name, h) {
  for (const w of [390, 1280]) await shoot(page, name, w, h);
}

/* The panel on its own, at both widths.
 *
 * A full-page shot of this editor is four thousand pixels tall and the panel
 * under discussion is somewhere in the middle of it. The full page is still
 * taken -- it is the proof that nothing else on the screen moved -- and this is
 * the one a human can actually read. */
async function panel(page, name, selector, h) {
  for (const w of [390, 1280]) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(400);
    const el = await page.$(selector);
    if (!el) { console.log(JSON.stringify({ missing: selector, shot: name, width: w })); continue; }
    await el.scrollIntoViewIfNeeded();
    await page.waitForTimeout(250);
    await el.screenshot({ path: `${OUT}/${name}-${w}.png` });
    const box = await el.boundingBox();
    console.log(JSON.stringify({
      panel: `${name}-${w}`, width: w,
      panelWidthPx: Math.round(box.width), panelHeightPx: Math.round(box.height),
    }));
  }
}

async function signIn(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
}

/** Open one product in the editor by its id, and wait for the panels to draw. */
async function open(page, id) {
  await page.evaluate((n) => window.peoEdit(n), id);
  await page.waitForTimeout(1600);
}

/* The product ids, BY SLUG and never by name.
 *
 * The preview's migrations seed a demo catalogue that already contains a "Glow
 * Starter Set", so a lookup by name opened somebody else's product and the
 * first run photographed it — the panel was right, the product was wrong. The
 * seed's slugs are all prefixed `sp2-`, which is unique by construction. */
async function ids(page) {
  return page.evaluate(async () => {
    const r = await fetch('/admin-api/product-editor-list?q=sp2-', { headers: { Accept: 'application/json' } });
    const b = await r.json();
    const by = {};
    (b.products || []).forEach((p) => { by[p.slug] = p.id; });
    return by;
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  // The editor asks before losing unsaved changes; this script always says yes.
  page.on('dialog', (d) => d.accept());

  await signIn(page);

  const by = await ids(page);
  console.log(JSON.stringify({ step: 'ids', ...by }));

  /* ═══════════════════ HALF 1 — the pricing panel, all three modes ═══ */

  /* 1. FIXED, AND A MEMBER ALREADY REPRICED. The seed anchored this set at
     20000 fils and then took 1500 off the toner, so this is the shot the whole
     lane is about: the working-out, and a price nobody typed today. */
  await open(page, by['sp2-glow-starter-set']);
  await both(page, 'pricing-fixed-after-member-repriced', 2100);
  await panel(page, 'panel-pricing-fixed-after-member-repriced', '[data-peo-panel="setbox"]', 2100);

  /* 2. A PERCENTAGE OFF THE TOTAL — unchanged by this lane, photographed so
     the three modes can be compared side by side. */
  await open(page, by['sp2-night-repair-set']);
  await both(page, 'pricing-discount-percent', 2000);
  await panel(page, 'panel-pricing-discount-percent', '[data-peo-panel="setbox"]', 2100);

  /* 3. AN AMOUNT OFF THE TOTAL. */
  await open(page, by['sp2-barrier-rescue-set']);
  await both(page, 'pricing-discount-amount', 2000);
  await panel(page, 'panel-pricing-discount-amount', '[data-peo-panel="setbox"]', 2100);

  /* 4. FIXED, WITH THE ANCHOR ABOUT TO MOVE. Typing a new price re-anchors on
     save, so the panel stops claiming a reduction it is about to zero and says
     what saving will do instead. */
  await open(page, by['sp2-glow-starter-set']);
  await page.fill('[data-bind="price_aed"]', '175');
  await page.dispatchEvent('[data-bind="price_aed"]', 'input');
  await page.waitForTimeout(600);
  await both(page, 'pricing-fixed-retyped-will-reanchor', 2100);
  await panel(page, 'panel-pricing-fixed-retyped-will-reanchor', '[data-peo-panel="setbox"]', 2100);

  /* ═══════════════════════════ HALF 2 — the share image ═════════════ */

  /* Choose a main image THROUGH THE MEDIA LIBRARY — the owner's own control,
     whose onPick callback is the only thing this lane changed about it. The
     picker is a modal in its own partial; nothing below reaches past it. */
  async function pickMainImage(page, wanted) {
    await page.click('#peo-mainlib');
    await page.waitForSelector('.mp-tile', { timeout: 8000 });
    await page.waitForTimeout(500);
    /* BY FILENAME, NOT BY POSITION. An earlier draft took the first tile, and
       the first tile is whatever the library happens to list first -- so the
       serum's new packshot was actually the set's box shot, and the screenshot
       told a true story about the wrong picture. */
    const url = await page.evaluate((name) => {
      const tiles = [...document.querySelectorAll('.mp-tile')];
      const tile = tiles.find((t) => (t.getAttribute('data-mp-url') || '').includes(name));
      if (!tile) throw new Error('no media tile matching ' + name);
      tile.click();
      return tile.getAttribute('data-mp-url');
    }, wanted);
    await page.waitForTimeout(400);
    // The modal confirms with "Use image", which is what fires onPick — and
    // onPick is the only thing this lane changed about the picker.
    await page.click('#mp-ok');
    await page.waitForSelector('.mp-back', { state: 'hidden', timeout: 8000 });
    await page.waitForTimeout(900);
    return url;
  }

  /* 5. AUTO-FILLED FROM A FRESHLY CHOSEN MAIN IMAGE.
     The serum has no share image of its own. Choosing a main image fills it,
     and the panel says "Automatic — taken from the main image". */
  await open(page, by['sp2-azelaic-serum']);
  const ogEmpty = await page.evaluate(() => document.querySelector('#peo-og').value);
  const chosen = await pickMainImage(page, 'serum-front-2026');
  console.log(JSON.stringify({ step: 'auto-fill', ogBefore: ogEmpty, chosen }));
  await both(page, 'seo-image-auto-from-main', 2100);
  await panel(page, 'panel-seo-image-auto-from-main', '[data-peo-panel="seo"]', 2100);

  /* 6. HAND-PICKED, AND A MAIN IMAGE CHANGE THAT DID NOT TOUCH IT.
     This product was seeded with a share image that is a different picture
     from its main image. The main image is then replaced through the same
     picker, in front of the camera, and the share image must not move. */
  await open(page, by['sp2-ceramide-moisturiser']);
  const beforeOg = await page.evaluate(() => document.querySelector('#peo-og').value);
  await both(page, 'seo-image-hand-picked-before', 2100);
  await panel(page, 'panel-seo-image-hand-picked-before', '[data-peo-panel="seo"]', 2100);

  const replaced = await pickMainImage(page, 'ceramide-front-2026');
  const afterOg = await page.evaluate(() => document.querySelector('#peo-og').value);

  console.log(JSON.stringify({
    step: 'hand-picked-survives-main-change',
    mainImageNowIs: replaced,
    shareImageUnchanged: beforeOg === afterOg,
    shareImageLength: afterOg.length,
  }));

  await both(page, 'seo-image-hand-picked-kept', 2100);
  await panel(page, 'panel-seo-image-hand-picked-kept', '[data-peo-panel="seo"]', 2100);

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
