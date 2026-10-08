/*
 * Lane RPL screenshots: Catalog → Products → edit, replace a picture.
 *
 * Driven the way the owner drives it: open the product, hover (or focus) a
 * gallery thumbnail, click it, choose a clean picture in the Media Library's
 * replace mode, replace a second one with a picture uploaded from INSIDE the
 * picker, replace the shared one, press Update, read the note. Then the server
 * is asked what the old addresses answer now. Every number printed is measured
 * in this Chromium, not asserted.
 *
 *   node tools/rpl-shots.cjs <width>      (RPL_BASE, RPL_CHROME, RPL_PRODUCT)
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RPL_BASE || 'http://127.0.0.1:9144';
const OUT = process.env.RPL_OUT || (__dirname + '/../docs/lane-rpl-shots');
const PRODUCT = +(process.env.RPL_PRODUCT || 25);

(async () => {
  const width = +process.argv[2];
  const phone = width < 600;
  const browser = await chromium.launch({ executablePath: process.env.RPL_CHROME || undefined });
  const ctx = await browser.newContext({ viewport: { width, height: phone ? 844 : 1000 }, deviceScaleFactor: 1, hasTouch: phone, isMobile: phone });
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + ((m.location() && m.location().url) || '')); });
  page.on('pageerror', e => errors.push(String(e)));
  page.on('dialog', d => d.dismiss());
  const missing = [];
  page.on('response', r => { if (r.status() === 404) missing.push(r.url().replace(BASE, '')); });

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(id => window.peoEdit(id), PRODUCT);
  await page.waitForSelector('[data-repl="1"]', { timeout: 15000 });
  await page.waitForTimeout(800);

  const media = await page.$('.peo-mediawrap');
  await media.scrollIntoViewIfNeeded();

  const before = await page.evaluate(() => [...document.querySelectorAll('#peo-gal .peo-tile img')].map(i => i.getAttribute('src').split('/').pop()));

  // 1. Hover (pointer) or focus (keyboard / phone) on gallery position 3.
  const target = page.locator('[data-repl="1"]');
  if (phone) await target.focus(); else await target.hover();
  await page.waitForTimeout(250);
  const hint = await page.evaluate(() => {
    const b = document.querySelector('[data-repl="1"]');
    const r = b.getBoundingClientRect();
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    const h = b.querySelector('.peo-replhint');
    return {
      button: [Math.round(r.width), Math.round(r.height)],
      hintOpacity: getComputedStyle(h).opacity, hintText: h.textContent,
      clickableAtCentre: !!hit && (hit === b || b.contains(hit)),
      scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
    };
  });
  /* At 390 a VIEWPORT shot, not an element shot: Playwright's element shot
     re-applies the device metrics, and Chromium drops the touch emulation's
     (hover:none) for the rest of the page's life -- the strip hint then
     photographs as the desktop overlay, which is the instrument, not the page. */
  if (phone) { await target.scrollIntoViewIfNeeded(); await page.evaluate(() => scrollBy(0, -120)); await page.waitForTimeout(200); await page.screenshot({ path: `${OUT}/1-hover-thumbnail-${width}.png` }); }
  else await media.screenshot({ path: `${OUT}/1-hover-thumbnail-${width}.png` });
  hint.hoverNone = await page.evaluate(() => matchMedia('(hover:none)').matches);
  hint.hintHeightUnfocused = await page.evaluate(() => getComputedStyle(document.querySelector('[data-repl="0"] .peo-replhint')).height);

  // The main image is a Replace button too: answered at its centre, in view.
  await page.locator('[data-repl="main"]').scrollIntoViewIfNeeded();
  hint.mainClickable = await page.evaluate(() => { const m = document.querySelector('[data-repl="main"]'); const q = m.getBoundingClientRect(); const x = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2); return !!x && (x === m || m.contains(x)); });
  await target.scrollIntoViewIfNeeded();

  // 2. Click it: the Media Library in replace mode.
  await target.click();
  await page.waitForSelector('.mp-back:not([hidden]) .mp-tile', { timeout: 15000 });
  await page.waitForTimeout(700);
  await page.click('[data-mp-url*="front-clean"]');
  await page.waitForTimeout(200);
  const picker = await page.evaluate(() => ({
    title: document.querySelector('#mp-title').textContent,
    replacingShown: !document.querySelector('#mp-repl').hidden,
    replacingImg: (document.querySelector('#mp-replimg').getAttribute('src') || '').split('/').pop(),
    ok: document.querySelector('#mp-ok').textContent,
    uploadButton: !!document.querySelector('#mp-upload'),
    scrollWidth: document.documentElement.scrollWidth,
  }));
  await page.screenshot({ path: `${OUT}/2-picker-replace-mode-${width}.png` });
  await page.click('#mp-ok');
  await page.waitForTimeout(400);

  // 3. Position 4 (the BUY 1 GET 1 promo): replaced by a picture uploaded from inside the picker.
  const up = path.join(OUT, '..', '..', 'storage', 'rpl-logs', 'IMG_4821.jpg');
  if (!fs.existsSync(up)) throw new Error('missing upload fixture ' + up);
  await page.click('[data-repl="2"]');
  await page.waitForSelector('.mp-back:not([hidden])');
  await page.setInputFiles('#mp-file', up);
  await page.waitForFunction(() => document.querySelector('#mp-ok') && !document.querySelector('#mp-ok').disabled, null, { timeout: 20000 });
  await page.click('#mp-ok');
  await page.waitForTimeout(400);

  // 4. Position 5 (the shelf shot another product also shows): replaced by the back shot.
  await page.click('[data-repl="3"]');
  await page.waitForSelector('.mp-back:not([hidden]) .mp-tile');
  await page.waitForTimeout(500);
  await page.click('[data-mp-url*="back-clean"]');
  await page.click('#mp-ok');
  await page.waitForTimeout(400);

  const afterPick = await page.evaluate(() => [...document.querySelectorAll('#peo-gal .peo-tile img')].map(i => i.getAttribute('src').split('/').pop()));

  // 5. Update product.
  await page.click('#peo-save');
  await page.waitForSelector('#peo-trash', { timeout: 20000 });
  await page.waitForTimeout(600);
  const note = await page.evaluate(() => ({
    rows: [...document.querySelectorAll('#peo-trash .peo-trow')].map(r => r.innerText.replace(/\s+/g, ' ').trim()),
    undo: document.querySelectorAll('#peo-trash [data-undo]').length,
    gallery: [...document.querySelectorAll('#peo-gal .peo-tile img')].map(i => i.getAttribute('src').split('/').pop()),
    alts: [...document.querySelectorAll('#peo-gal .peo-alt')].map(i => i.value),
    scrollWidth: document.documentElement.scrollWidth,
  }));
  const trash = await page.$('#peo-trash');
  await trash.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/3-after-save-note-${width}.png` });
  await trash.screenshot({ path: `${OUT}/4-note-close-${width}.png` });

  // 6. What the old addresses answer now.
  const ask = async rel => { const r = await page.request.get(BASE + '/' + rel, { maxRedirects: 0 }); return [r.status(), (r.headers()['location'] || '').replace(BASE, '')]; };
  const http = {
    replaced: await ask('uploads/products/anua-toner-sale-50-off.jpg'),
    replacedPhoneCopy: await ask('img-cache/400/uploads/products/anua-toner-sale-50-off.jpg'),
    uploadedReplaced: await ask('uploads/products/anua-toner-promo-banner.jpg'),
    shared: await ask('uploads/products/anua-heartleaf-shelf.jpg'),
  };

  // 7. Undo the first one.
  await page.click('#peo-trash [data-undo]');
  await page.waitForTimeout(1200);
  const undone = await page.evaluate(() => ({
    rows: [...document.querySelectorAll('#peo-trash .peo-trow')].map(r => r.innerText.replace(/\s+/g, ' ').trim()),
    gallery: [...document.querySelectorAll('#peo-gal .peo-tile img')].map(i => i.getAttribute('src').split('/').pop()),
  }));
  await (await page.$('.peo-mediawrap')).screenshot({ path: `${OUT}/5-after-undo-${width}.png` });

  console.log(JSON.stringify({ width, before, hint, picker, afterPick, note, http, undone, errors, missing }, null, 2));
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
