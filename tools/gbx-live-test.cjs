/* Lane GBX: drives docs/gbx-previews/live/index.html in Chromium -- every
   control, every popup, Esc / close / outside tap, View all, Arabic, both
   widths -- and fails on any console error. node tools/gbx-live-test.cjs */
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
const FILE = 'file://' + path.resolve(__dirname, '../docs/gbx-previews/live/index.html');
const SHOTS = path.resolve(__dirname, '../docs/gbx-previews/live/shots');
fs.mkdirSync(SHOTS, { recursive: true });
const ok = []; const bad = [];
const check = (cond, what) => (cond ? ok : bad).push(what);
// A person's tap: the mouse at the middle of the element, whatever is on top.
let page;
const tap = async (loc) => { await loc.evaluate((x) => x.scrollIntoView({ block: 'center' })); const b = await loc.boundingBox(); await page.mouse.click(b.x + b.width / 2, b.y + b.height / 2); };

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = []; const external = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  // Google Fonts is the one allowed outside request; this sandbox cannot reach it, so it is answered empty here.
  await page.route('**/*', (r) => { const u = r.request().url(); if (u.startsWith('file:') || u.startsWith('data:')) return r.continue(); if (/fonts\.(googleapis|gstatic)\.com/.test(u)) return r.fulfill({ status: 200, contentType: 'text/css', body: '' }); external.push(u); return r.abort(); });
  await page.goto(FILE, { waitUntil: 'load' });
  const ctl = async (k, v) => { await page.click(`.seg[data-k="${k}"] button[data-v="${v}"]`); await page.waitForTimeout(250); };
  const fr = () => page.frame({ url: /\?stage/ });
  await page.waitForTimeout(600);
  await ctl('lang', 'en'); await ctl('width', 'phone'); await ctl('glow', 'none');
  let f = fr();
  check(await f.evaluate(() => innerWidth) === 390, 'phone frame is 390 wide inside');
  check(await f.locator('.fgb').count() === 2, 'two real boxes (cart panel + checkout)');
  check(await f.locator('.fgb .gbx-cr').count() === 4, 'Gifts Criteria beside View all and Hide, in both boxes');
  const first = await f.locator('.fgb').first().locator('.fgb-th img').evaluateAll((im) => im.map((i) => i.alt));
  check(first[0].startsWith('Senka') && first[first.length - 1].startsWith('Collagen'), 'photos full size first, masks last: ' + first.join(' | '));
  await page.screenshot({ path: path.join(SHOTS, 'phone-en-box.png') });

  for (const d of ['A', 'B', 'C', 'D']) {
    await ctl('pop', d); f = fr();
    const tile = f.locator('.fgb').first().locator('.fgb-th .fgb-t').nth(1);
    await tap(tile); await page.waitForTimeout(300);
    const shown = d === 'C' ? await f.locator('.gbx-c').textContent().catch(() => '') : await f.locator('dialog.gbx-d[open]').textContent().catch(() => '');
    check(/The Glow Set/.test(shown) && /AXIS-Y/i.test(shown) && /AED 120/.test(shown) && /FREE/.test(shown), `popup ${d} shows image, title, brand, price, FREE`);
    await page.screenshot({ path: path.join(SHOTS, `phone-en-popup-${d}.png`) });
    await page.keyboard.press('Escape'); await page.waitForTimeout(200);
    check(d === 'C' ? await f.locator('.gbx-c').count() === 0 : await f.locator('dialog.gbx-d[open]').count() === 0, `popup ${d} closes on Esc`);
    check(await f.evaluate(() => document.activeElement.classList.contains('fgb-t')), `popup ${d} gives focus back to the photo`);
  }
  // The sale price and the strike-through: Senka is on sale (35 -> 29).
  await ctl('pop', 'A'); f = fr();
  await tap(f.locator('.fgb').first().locator('.fgb-th .fgb-t').first()); await page.waitForTimeout(250);
  check(await f.locator('dialog[open] s').textContent() === 'AED 35' && await f.locator('dialog[open] .gbx-pr b').textContent() === 'AED 29', 'cut price AED 35 and price AED 29');
  await tap(f.locator('dialog[open] .gbx-x')); await page.waitForTimeout(200);
  check(await f.locator('dialog.gbx-d[open]').count() === 0, 'close button closes');
  await tap(f.locator('.fgb').first().locator('.fgb-th .fgb-t').first()); await page.waitForTimeout(250);
  { const b = await page.locator('#fr').boundingBox(); await page.mouse.click(b.x + 60, b.y + 80); } await page.waitForTimeout(250);
  check(await f.locator('dialog.gbx-d[open]').count() === 0, 'a tap outside closes');

  // View all still opens the list, in the same order.
  await tap(f.locator('.fgb').first().locator('.fgb-th .fgb-tg')); await page.waitForTimeout(200);
  const list = await f.locator('.fgb').first().locator('.fgb-ls .fgb-nm').allTextContents();
  check(await f.locator('.fgb').first().locator('.fgb-ls').isVisible() && list[0].startsWith('Senka') && /Collagen/.test(list[list.length - 1]), 'View all opens the list, full size first: ' + list.join(' | '));
  await page.screenshot({ path: path.join(SHOTS, 'phone-en-list.png') });
  await tap(f.locator('.fgb').first().locator('.fgb-ls .fgb-tg'));

  for (const d of ['1', '2', '3']) {
    await ctl('crit', d); f = fr();
    await tap(f.locator('.fgb').first().locator('.fgb-th .gbx-cr')); await page.waitForTimeout(300);
    const txt = await f.locator('dialog.gbx-d[open]').textContent();
    const n = d === '3' ? await f.locator('dialog[open] [role=tab]').count() : await f.locator('dialog[open] li').count();
    check(n === 6 && /Gifts Criteria/.test(txt) && /3 Full Size Products \+ 5 Masks/.test(txt) && /Your basket/.test(txt), `criteria ${d}: six bands, new wording, your band marked`);
    check(await f.evaluate(() => { const g = document.querySelector('dialog[open]'); return g.scrollWidth <= g.clientWidth && document.documentElement.scrollWidth <= innerWidth; }), `criteria ${d} fits with no sideways scroll`);
    await page.screenshot({ path: path.join(SHOTS, `phone-en-criteria-${d}.png`) });
    if (d === '3') {
      await tap(f.locator('dialog[open] [role=tab]').first()); await page.waitForTimeout(150);
      check(/2 Masks/.test(await f.locator('dialog[open] .gbx-tp').textContent()), 'criteria 3: a tab shows its band');
    }
    await page.keyboard.press('Escape'); await page.waitForTimeout(200);
    check(await f.locator('dialog.gbx-d[open]').count() === 0, `criteria ${d} closes on Esc`);
  }

  for (const g of ['G1', 'G2', 'G3', 'G4']) {
    await ctl('glow', g); f = fr();
    check(await f.evaluate(() => getComputedStyle(document.querySelector('.fgb-th .fgb-t')).boxShadow !== 'none'), `glow ${g} applied`);
    await f.locator('.fgb').first().screenshot({ path: path.join(SHOTS, `phone-en-glow-${g}.png`) });
  }

  await ctl('lang', 'ar'); f = fr();
  check(await f.evaluate(() => document.documentElement.dir) === 'rtl' && /منتجات بالحجم الكامل/.test(await f.locator('.fgb-w').first().textContent()), 'Arabic: right to left, Arabic wording');
  await tap(f.locator('.fgb').first().locator('.fgb-th .gbx-cr')); await page.waitForTimeout(300);
  check(/شروط الهدايا/.test(await f.locator('dialog[open]').textContent()), 'Arabic criteria popup');
  await page.screenshot({ path: path.join(SHOTS, 'phone-ar-criteria.png') });
  await page.keyboard.press('Escape');
  await tap(f.locator('.fgb').first().locator('.fgb-th .fgb-t').first()); await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOTS, 'phone-ar-popup.png') });
  await page.keyboard.press('Escape');

  await ctl('lang', 'en'); await ctl('width', 'desk'); await page.waitForTimeout(400); f = fr();
  check(await f.evaluate(() => innerWidth) === 1280, 'desktop frame is 1280 wide inside');
  await tap(f.locator('.fgb').nth(1).locator('.fgb-th .fgb-t').nth(2)); await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOTS, 'desk-en-popup.png') });
  await page.keyboard.press('Escape');
  await tap(f.locator('.fgb').nth(1).locator('.fgb-th .gbx-cr')); await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOTS, 'desk-en-criteria.png') });
  await page.keyboard.press('Escape');
  // Basket switch: a smaller basket, its own band.
  await page.selectOption('#basket', '1'); await page.waitForTimeout(300); f = fr();
  check(/1 Full Size Product \+ 2 Masks/.test(await f.locator('.fgb-w').first().textContent()), 'basket switch redraws the box for its band');

  check(errors.length === 0, 'no console errors: ' + JSON.stringify(errors));
  check(external.length === 0, 'no request outside the page, its images and Google Fonts: ' + JSON.stringify(external));
  await ctl('width', 'phone'); await ctl('glow', 'G1'); await ctl('pop', 'A'); await ctl('crit', '1'); await page.selectOption('#basket', '4');
  await browser.close();
  console.log('PASS ' + ok.length + '\n' + ok.map((x) => '  ok   ' + x).join('\n') + (bad.length ? '\nFAIL ' + bad.length + '\n' + bad.map((x) => '  FAIL ' + x).join('\n') : ''));
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
