// Lane LBL -- Catalog -> Labels: the admin list, the editor, the picker
// searching (ms per keystroke), Both together with an exclusion, and the shop
// (category, brand, shop, product, /ar) with labels, at 390 and 1280.
// Measures scrollWidth, console errors, that every labelled card's photo link
// still answers a click under the badge, and the search time per keystroke.
// Usage: node tools/lbl-shots.cjs <base> <out-dir> <product-slug> [only-search]
// The admin screen is injected from its partial when the console does not
// include it yet (before the integrator wires it).
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');

const BASE = process.argv[2], OUT = process.argv[3], SLUG = process.argv[4], ONLY = process.argv[5] || '';
const PARTIAL = fs.readFileSync(path.join(__dirname, '../resources/views/admin/partials/product-labels-screen.blade.php'), 'utf8').match(/<script>([\s\S]*)<\/script>/)[1];
fs.mkdirSync(OUT, { recursive: true });
const report = {};

async function admin(browser, w) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 500 ? 844 : 900 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + (m.location() || {}).url); });
  page.on('response', (res) => { if (res.status() >= 400) errors.push(res.status() + ' ' + res.url()); });
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  if (!(await page.evaluate(() => !!document.querySelector('script') && String(window.go).length > 0 && document.body.innerHTML.indexOf("SCREEN = 'catlabels'") >= 0))) {
    await page.addScriptTag({ content: PARTIAL });
  }
  await page.evaluate(() => window.go('catlabels'));
  await page.waitForSelector('#content [data-lbl] .lb-row[data-id]', { timeout: 15000 });
  const r = { errors };
  if (!ONLY) await page.screenshot({ path: OUT + '/admin-list-' + w + '.png', fullPage: true });
  r.listScrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  // the editor, on the first label (Best Seller)
  await page.click('#content [data-lbl] .lb-row[data-id] [data-a="edit"]');
  await page.waitForSelector('#content [data-lbl] .lb-r', { timeout: 20000 });
  if (!ONLY) await page.screenshot({ path: OUT + '/admin-editor-' + w + '.png', fullPage: false });
  r.indexRows = await page.evaluate(() => document.querySelector('#content [data-lbl-count]').textContent);
  // searching: type one key at a time and read the measured ms after each
  const q = '#content #lbQ';
  await page.locator(q).scrollIntoViewIfNeeded();
  const ms = [];
  for (const word of ['snail essence', 'سيروم', 'cica 50ml', 'LBL-10']) {
    await page.fill(q, '');
    for (const ch of word) {
      await page.type(q, ch);
      ms.push(await page.evaluate(() => document.querySelector('#content [data-lbl-ms]').textContent));
    }
    r['found:' + word] = await page.evaluate(() => document.querySelector('#content [data-lbl-count]').textContent);
    if (!ONLY && w === 1280 || word === 'snail essence') {
      await page.evaluate(() => document.querySelector('#content [data-lbl] .lb-pick').scrollIntoView());
      await page.screenshot({ path: OUT + '/admin-picker-search-' + (word.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'arabic') + '-' + w + '.png' });
    }
  }
  r.msPerKeystroke = ms;
  // Both together: tick a category, exclude one product
  if (!ONLY) {
    await page.fill(q, '');
    await page.click('#content [data-lbl-mode] [data-v="both"]');
    await page.click('#content [data-lbl-tree] .lb-tn >> nth=0');
    await page.fill('#content #lbXq', 'cica');
    await page.waitForSelector('#content [data-lbl-xdd].on [data-x]');
    await page.click('#content [data-lbl-xdd] [data-x] >> nth=0');
    await page.evaluate(() => document.querySelector('#content [data-lbl] .lb-pick').scrollIntoView());
    await page.screenshot({ path: OUT + '/admin-picker-both-' + w + '.png', fullPage: false });
    await page.evaluate(() => document.querySelector('#content [data-lbl] .lb-sum').scrollIntoView({ block: 'center' }));
    await page.screenshot({ path: OUT + '/admin-picker-total-' + w + '.png', fullPage: false });
    r.total = await page.evaluate(() => document.querySelector('#content [data-lbl-total]').textContent + ' · ' + document.querySelector('#content [data-lbl-break]').textContent);
  }
  r.editorScrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  report['admin-' + w] = r;
  await ctx.close();
}

async function shop(browser, w, url, name) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 500 ? 844 : 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  const first = await page.$('.plb-s, .plb-sash, .plb-t');
  if (first) await page.evaluate((el) => el.scrollIntoView({ block: 'center' }), first);
  await page.waitForTimeout(200);
  await page.screenshot({ path: OUT + '/shop-' + name + '-' + w + '.png', fullPage: false });
  const m = await page.evaluate(() => {
    const out = { scrollWidth: document.documentElement.scrollWidth, width: innerWidth, stacks: document.querySelectorAll('.plb-s').length, sashes: document.querySelectorAll('.plb-sash').length, chips: document.querySelectorAll('.plb-t .plb').length, style: !!document.getElementById('kbb-plb'), clicks: [] };
    // Under every badge a click still reaches the card's photo link (pointer-events:none).
    document.querySelectorAll('.kbb-card-thumb .plb, .kbb-card-thumb .plb-sash').forEach((b) => {
      const r = b.getBoundingClientRect();
      if (r.width === 0 || r.bottom < 0 || r.top > innerHeight) return;
      const x = Math.min(Math.max(r.left + r.width / 2, 1), innerWidth - 1), y = r.top + r.height / 2;
      const hit = document.elementFromPoint(x, y);
      out.clicks.push(!!(hit && hit.closest('a[href]')));
    });
    const b = document.querySelector('.kbb-card-thumb .plb');
    if (b) { const cs = getComputedStyle(b); out.badgeFont = cs.fontSize; out.badgeHeight = b.getBoundingClientRect().height; }
    return out;
  });
  m.errors = errors;
  report['shop-' + name + '-' + w] = m;
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome' });
  for (const w of [1280, 390]) {
    await admin(browser, w);
    if (ONLY) continue;
    await shop(browser, w, '/collections/skincare-lbl/', 'category');
    await shop(browser, w, '/collections/makeup-lbl/', 'category-ribbon');
    await shop(browser, w, '/brands/cosrx/', 'brand');
    await shop(browser, w, '/shop/', 'shop');
    await shop(browser, w, '/product/' + SLUG + '/', 'product');
    await shop(browser, w, '/ar/collections/skincare-lbl/', 'ar-category');
    await shop(browser, w, '/ar/product/' + SLUG + '/', 'ar-product');
  }
  await browser.close();
  fs.writeFileSync(OUT + '/measurements' + (ONLY ? '-' + ONLY : '') + '.json', JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 1));
})();
