/*
 * Lane PW, phase 1: photograph docs/pw-preview/index.html.
 *   node tools/pwa-preview-shots.cjs
 * Full page at 390 and 820, plus the device stage for every option x device at
 * "the offer", the install steps per device, Arabic, and opened-from-home.
 * Reports scrollWidth vs clientWidth (no sideways scroll) and console errors.
 */
const { chromium } = require('playwright');
const path = require('path');
const FILE = 'file://' + path.resolve(__dirname, '../docs/pw-preview/index.html');
const OUT = path.resolve(__dirname, '../docs/pw-preview');

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const errors = [];
  const shoot = async (w, hash, file, full, sel) => {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
    // The capture browser cannot reach Google Fonts through this sandbox's
    // proxy, so the preview's Outfit/Cairo request is answered with the shop's
    // own self-hosted files (resources/fonts) -- the same faces, so the shots
    // show the real type rather than a fallback.
    await ctx.route('https://fonts.googleapis.com/**', (r) => r.fulfill({ contentType: 'text/css', body:
      "@font-face{font-family:Outfit;font-weight:100 900;src:url(https://fonts.gstatic.com/kbb/outfit-latin.woff2) format('woff2')}"
      + "@font-face{font-family:Cairo;font-weight:200 1000;src:url(https://fonts.gstatic.com/kbb/cairo-arabic.woff2) format('woff2')}" }));
    await ctx.route('https://fonts.gstatic.com/kbb/*', (r) => {
      const f = r.request().url().split('/').pop();
      r.fulfill({ contentType: 'font/woff2', path: path.resolve(__dirname, '../resources/fonts', f.split('-')[0], f) });
    });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(file + ': ' + e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(file + ': ' + m.text()); });
    await page.goto(FILE + (hash ? '#' + hash : ''), { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    // The sticky controls would sit over the top of an element shot.
    if (sel) await page.addStyleTag({ content: '.ctl{position:static!important}' });
    const m = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
    if (sel) await page.locator(sel).screenshot({ path: `${OUT}/${file}.png` });
    else await page.screenshot({ path: `${OUT}/${file}.png`, fullPage: full });
    console.log(file, 'scrollWidth', m[0], 'clientWidth', m[1]);
    await ctx.close();
  };
  await shoot(390, '', 'page-390', true);
  await shoot(820, '', 'page-820', true);
  for (const opt of ['A', 'B', 'C', 'D']) {
    for (const dev of ['iphone', 'android', 'ipad']) {
      await shoot(1280, `opt=${opt}&dev=${dev}&st=offer`, `${opt}-${dev}-offer`, false, '#dev');
    }
  }
  for (const dev of ['iphone', 'android', 'ipad']) await shoot(1280, `opt=C&dev=${dev}&st=sheet`, `steps-${dev}`, false, '#dev');
  await shoot(1280, 'opt=C&dev=iphone&st=browse', 'C-iphone-menu', false, '#dev');
  await shoot(1280, 'opt=C&dev=iphone&st=offer&lang=ar', 'C-iphone-offer-ar', false, '#dev');
  await shoot(1280, 'opt=B&dev=iphone&st=installed', 'installed-iphone', false, '#dev');
  await shoot(1280, 'opt=C', 'icons', false, '#iconCards');
  await shoot(1280, 'opt=C', 'home-screens', false, '#homes');
  console.log('errors:', errors.length ? errors : 'none');
  await browser.close();
})();
