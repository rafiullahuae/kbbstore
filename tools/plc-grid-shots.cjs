/*
 * Lane GRID — Appearance → Product grid, photographed before and after.
 *
 * Boot the preview first:   ./tools/plc-preview.sh 8993
 * Then:                     node tools/plc-grid-shots.cjs 8993 <after|before>
 *
 * THE POINT OF THE "before" PASS is that the defect is invisible to a test that
 * only counts swatches: all 32 were there the whole time, and all 32 were blank
 * pink rectangles. So this run also COUNTS THE CARDS INSIDE THE SWATCHES and
 * reports it beside the picture — 0 before, 32 after — which is the number the
 * screenshot is evidence for.
 *
 * MEASURING HERE IS FINE AND MEASURING IN THE PAGE IS NOT. Rule 4 forbids the
 * SHOP sizing itself with JavaScript; this file is the instrument, and what it
 * reads is what the brief asks for: document.documentElement.scrollWidth, plus
 * the swatch and preview boxes.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const PORT = process.argv[2] || '8993';
const PASS = process.argv[3] || 'after';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-grid-shots');
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const VIEWPORTS = [{ tag: '1280', w: 1280, h: 1000 }, { tag: '390', w: 390, h: 844 }];
const rows = [];

async function measure(page, key, vp, extra = {}) {
  const m = await page.evaluate(() => {
    const sw = document.querySelectorAll('.skinsw');
    const first = sw[0];
    const prev = document.getElementById('pgPrev');
    const box = el => { if (!el) return null; const r = el.getBoundingClientRect(); return { w: +r.width.toFixed(1), h: +r.height.toFixed(1) }; };
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      swatches: sw.length,
      /* THE NUMBER THAT MATTERS. A swatch with a real design in it contains a
         .kbb-card; the blank placeholder contained nothing at all. */
      swatchesWithACard: [...sw].filter(s => s.querySelector('.kbb-card')).length,
      firstSwatchBox: box(first),
      firstSwatchHasCart: !!(first && first.querySelector('.kbb-card-cart')),
      firstSwatchHasPrice: !!(first && first.querySelector('.kbb-card-price')),
      previewExists: !!prev,
      previewCards: prev ? prev.querySelectorAll('.kbb-card').length : 0,
      previewBox: box(prev),
      previewSkin: prev ? (prev.querySelector('.kbb-pgrid') || {}).dataset?.skin || null : null,
      previewCols: prev ? getComputedStyle(prev).getPropertyValue('--pg-cols').trim() : null,
      previewGap: prev ? getComputedStyle(prev).getPropertyValue('--pg-gap').trim() : null,
      toggles: document.querySelectorAll('[data-pgtog]').length,
      sliders: document.querySelectorAll('[data-pgnum]').length,
    };
  });
  const row = { pass: PASS, shot: key, viewport: vp.tag, ...m, ...extra };
  rows.push(row);
  console.log(JSON.stringify(row));
  return m;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const vp of VIEWPORTS) {
    const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    page.on('dialog', d => d.dismiss());

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type=submit], input[type=submit]'),
    ]);

    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('layout'));
    await page.waitForTimeout(2200);

    await measure(page, 'screen', vp);
    await page.screenshot({ path: `${OUT}/${PASS}-screen-${vp.tag}.png`, fullPage: true });

    if (PASS === 'after') {
      /* THE PREVIEW FOLLOWS A SELECTION. Pick a design that looks nothing like
         the default so the picture cannot be mistaken for a repaint of the
         same thing: `bold` is the dark-luxury card. */
      await page.click('[data-pgskin="bold"]');
      await page.waitForTimeout(700);
      await measure(page, 'picked-bold', vp);
      await page.screenshot({ path: `${OUT}/${PASS}-picked-bold-${vp.tag}.png`, fullPage: true });

      /* A CONTROL, BEFORE AND AFTER. Switch the Add to cart button off and the
         button leaves every card in the preview. */
      await page.click('[data-pgtog="show_cart"]');
      await page.waitForTimeout(600);
      const m = await measure(page, 'cart-off', vp);
      await page.screenshot({ path: `${OUT}/${PASS}-cart-off-${vp.tag}.png`, fullPage: true });
      const cartsNow = await page.evaluate(() =>
        [...document.querySelectorAll('#pgPrev .kbb-card-cart')]
          .filter(b => getComputedStyle(b).display !== 'none').length);
      rows[rows.length - 1].visibleCartButtonsInPreview = cartsNow;
      console.log(JSON.stringify({ note: 'visible Add-to-cart buttons in preview after switching it off', cartsNow, previewCards: m.previewCards }));
      await page.click('[data-pgtog="show_cart"]');   // put it back, nothing is saved
      await page.waitForTimeout(400);

      /* SPACING. Drag the gap to its maximum and photograph the preview opening up. */
      await page.evaluate(() => {
        const r = document.querySelector('[data-pgnum="gap"]');
        r.value = '32';
        r.dispatchEvent(new Event('input', { bubbles: true }));
      });
      await page.waitForTimeout(600);
      await measure(page, 'gap-32', vp);
      await page.screenshot({ path: `${OUT}/${PASS}-gap-32-${vp.tag}.png`, fullPage: true });
    }

    await ctx.close();
  }

  await browser.close();
  const f = `${OUT}/${PASS}-measurements.json`;
  fs.writeFileSync(f, JSON.stringify(rows, null, 2));
  console.log('wrote ' + f);
})();
