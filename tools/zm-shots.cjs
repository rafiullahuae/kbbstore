/*
 * LANE ZM — before/after pictures of the fields the no-focus-zoom floor moves,
 * at 390 (iPhone: isMobile, hasTouch, DPR 3) and at 1280 (laptop, mouse).
 *
 *   KBB_BASE=http://127.0.0.1:10641 KBB_APP=/<secret> node tools/zm-shots.cjs <before|after>
 *   node tools/zm-shots.cjs diff        # 1280 before vs after, pixel by pixel
 *
 * Each shot focuses its field first (the moment iOS would zoom) and records the
 * field's computed font-size and box height beside the picture, in
 * docs/zm-shots/report-<phase>.json. The 1280 pair is compared in the browser's
 * own canvas, so a laptop that changed by one pixel shows up as a count.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10641';
const APP = process.env.KBB_APP || '';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OUT = path.join(__dirname, '..', 'docs', 'zm-shots');
const PHASE = process.argv[2] || 'after';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
fs.mkdirSync(OUT, { recursive: true });

const SIZES = {
  390: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, userAgent: IPHONE },
  1280: { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, userAgent: MAC },
};

// [name, url, field to focus, signed in?]
const SHOTS = [
  ['checkout', '/checkout/', '#billing_first_name'],
  ['login-register', '/my-account/', 'form[action*="login"] input[name=email]'],
  // The cart page has no typed field (its quantity is a stepper); the coupon
  // a shopper types is the checkout's.
  ['coupon', '/checkout/', '#kbb_coupon_code'],
  ['header-search', '/', 'header .sbox input, header input[type=search]'],
  ['footer-newsletter', '/', 'footer input[type=email], input[name=email][type=email]'],
  ['search-404', '/no-such-page-zm/', '#content input[name=s]'],
  ['address-book', '/my-account/edit-address/1/', '#content input[name=first_name]', true],
];

async function snap(page, name, size, sel, report) {
  const h = await page.$(sel.split(',').map((s) => s.trim()).join(','));
  let m = null;
  if (h) {
    await h.scrollIntoViewIfNeeded().catch(() => {});
    await page.evaluate((e) => { const r = e.getBoundingClientRect(); window.scrollBy(0, r.top - window.innerHeight / 3); }, h);
    await h.focus().catch(() => {});
    await page.waitForTimeout(350);
    m = await page.evaluate((e) => {
      const cs = getComputedStyle(e); const r = e.getBoundingClientRect();
      return { px: parseFloat(cs.fontSize), h: Math.round(r.height * 10) / 10, w: Math.round(r.width * 10) / 10, lineHeight: cs.lineHeight, padding: cs.padding,
        scale: window.visualViewport.scale, scrollWidth: document.documentElement.scrollWidth, vw: window.innerWidth };
    }, h);
  }
  const file = PHASE + '-' + size + '--' + name + '.png';
  // The header's icon buttons carry live counters (the bag, the wishlist, the
  // signed-in dot) that move between two runs on their own; they are masked so
  // the 1280 pair compares the page, not the cart.
  await page.screenshot({ path: path.join(OUT, file), animations: 'disabled', caret: 'hide', mask: [page.locator('header .ib')], maskColor: '#888' });
  report[size + ' ' + name] = Object.assign({ file }, m || { missing: sel });
  process.stdout.write('  ' + size + ' ' + name + ' ' + JSON.stringify(m) + '\n');
}

async function diff() {
  const browser = await chromium.launch({ executablePath: EXE });
  const page = await browser.newPage();
  const res = {};
  for (const f of fs.readdirSync(OUT).filter((x) => x.startsWith('before-1280--'))) {
    const a = path.join(OUT, f); const b = path.join(OUT, f.replace('before-', 'after-'));
    if (!fs.existsSync(b)) continue;
    res[f.replace('before-1280--', '').replace('.png', '')] = await page.evaluate(async ([x, y]) => {
      const load = (src) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(i); i.src = src; });
      const [i1, i2] = await Promise.all([load(x), load(y)]);
      if (i1.width !== i2.width || i1.height !== i2.height) return { differentSize: [i1.width, i1.height, i2.width, i2.height] };
      const c = document.createElement('canvas'); c.width = i1.width; c.height = i1.height; const g = c.getContext('2d');
      g.drawImage(i1, 0, 0); const d1 = g.getImageData(0, 0, c.width, c.height).data;
      g.clearRect(0, 0, c.width, c.height); g.drawImage(i2, 0, 0); const d2 = g.getImageData(0, 0, c.width, c.height).data;
      let n = 0; for (let k = 0; k < d1.length; k += 4) if (d1[k] !== d2[k] || d1[k + 1] !== d2[k + 1] || d1[k + 2] !== d2[k + 2] || d1[k + 3] !== d2[k + 3]) n++;
      return { pixels: c.width * c.height, differing: n };
    }, ['data:image/png;base64,' + fs.readFileSync(a).toString('base64'), 'data:image/png;base64,' + fs.readFileSync(b).toString('base64')]);
  }
  fs.writeFileSync(path.join(OUT, 'diff-1280.json'), JSON.stringify(res, null, 1) + '\n');
  process.stdout.write(JSON.stringify(res, null, 1) + '\n');
  await browser.close();
}

(async () => {
  if (PHASE === 'diff') return diff();
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};
  for (const size of [390, 1280]) {
    const ctx = await browser.newContext(SIZES[size]);
    const page = await ctx.newPage();
    // A cart with two lines, so the cart and the checkout draw their fields.
    await page.goto(BASE + '/product/mac-anua/', { waitUntil: 'networkidle' });
    await page.evaluate(async () => {
      for (const id of [25, 26]) await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
    });
    let signedIn = false;
    for (const [name, url, sel, auth] of SHOTS) {
      if (auth && !signedIn) {
        await page.goto(BASE + '/my-account/', { waitUntil: 'networkidle' });
        await page.fill('form[action*="login"] input[name=email]', 'sabina.dev@example.com');
        await page.fill('form[action*="login"] input[name=password]', 'preview-password');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('form[action*="login"] [type=submit]')]);
        signedIn = true;
      }
      await page.goto(BASE + url, { waitUntil: 'networkidle' });
      await page.waitForTimeout(400);
      await snap(page, name, size, sel, report);
    }
    await ctx.close();

    if (APP) {
      const c2 = await browser.newContext(SIZES[size]);
      const p2 = await c2.newPage();
      await p2.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
      await p2.waitForTimeout(500);
      await snap(p2, 'owner-app-sign-in-pin', size, 'input[name=pin]', report);
      await c2.close();
    }

    const c3 = await browser.newContext(SIZES[size]);
    const p3 = await c3.newPage();
    await p3.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await snap(p3, 'admin-login', size, 'input[name=email]', report);
    await p3.fill('input[name=email]', 'owner@example.com');
    await p3.fill('input[name=password]', 'preview-password');
    await Promise.all([p3.waitForNavigation({ waitUntil: 'networkidle' }), p3.click('button[type=submit]')]);
    await p3.waitForTimeout(1000);
    await p3.evaluate(() => window.go('store-settings'));
    await p3.waitForLoadState('networkidle'); await p3.waitForTimeout(900);
    await snap(p3, 'admin-store-settings', size, '#content input[type=text], #content input:not([type]), #content select', report);
    await c3.close();
  }
  fs.writeFileSync(path.join(OUT, 'report-' + PHASE + '.json'), JSON.stringify(report, null, 1) + '\n');
  await browser.close();
})();
