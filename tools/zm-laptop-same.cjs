/*
 * LANE ZM — a laptop draws exactly what it drew before, proved in ONE run.
 *
 *   KBB_BASE=http://127.0.0.1:10641 KBB_OLD_BUILD=<dir holding the old public/build> node tools/zm-laptop-same.cjs
 *
 * Two shots taken minutes apart differ wherever the shop is live (a trending
 * row, a cart badge), which says nothing about CSS. So each page is drawn twice
 * in the SAME session a second apart: once with this branch's kbb.css, once with
 * the old build's kbb.css served at the same URL. Any pixel that differs is the
 * stylesheet's doing. 1280x900, a mouse, a desktop browser.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10641';
const OLD = process.env.KBB_OLD_BUILD;
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const oldManifest = JSON.parse(fs.readFileSync(path.join(OLD, 'manifest.json'), 'utf8'));
const oldCss = fs.readFileSync(path.join(OLD, oldManifest['resources/css/kbb/kbb.css'].file));
const PAGES = ['/', '/shop/', '/product/mac-anua/', '/cart/', '/checkout/', '/my-account/', '/track-my-order/', '/contact-us/', '/no-such-page-zm/', '/ar/'];

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  // The shop registers a service worker; its fetches never reach page.route, so
  // it is blocked here or the old stylesheet would never be served.
  // KBB_TOUCH=1 runs the style half on an iPhone instead, where the fields
  // SHOULD differ: it lists which elements did, to show nothing else moved.
  const TOUCH = process.env.KBB_TOUCH === '1';
  const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
  const ctx = await browser.newContext(TOUCH
    ? { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true, userAgent: IPHONE, serviceWorkers: 'block' }
    : { viewport: { width: 1280, height: 900 }, userAgent: MAC, serviceWorkers: 'block' });
  const page = await ctx.newPage();
  await page.goto(BASE + '/product/mac-anua/', { waitUntil: 'networkidle' });
  await page.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) }); });
  let swap = false;
  let served = 0;
  await page.route(/\/build\/assets\/kbb-[A-Za-z0-9_]{8}\.css$/, (route) => { if (!swap) return route.continue(); served++; return route.fulfill({ body: oldCss, contentType: 'text/css' }); });
  const shot = async (url) => { await page.goto(BASE + url, { waitUntil: 'networkidle' }); await page.waitForTimeout(300); return page.screenshot({ fullPage: true, animations: 'disabled', caret: 'hide' }); };
  const out = {};
  // A page that moves on its own (a slider, a countdown) differs from ITSELF;
  // the control pair -- this branch's CSS twice -- says how much of any
  // difference is the page and not the stylesheet.
  const compare = async (now, was) => now.equals(was) ? 'identical bytes' : page.evaluate(async ([x, y]) => {
      const load = (s) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(i); i.src = s; });
      const [a, b] = await Promise.all([load(x), load(y)]);
      if (a.width !== b.width || a.height !== b.height) return 'different size ' + [a.width, a.height, b.width, b.height];
      const c = document.createElement('canvas'); c.width = a.width; c.height = a.height; const g = c.getContext('2d');
      g.drawImage(a, 0, 0); const d1 = g.getImageData(0, 0, c.width, c.height).data; g.drawImage(b, 0, 0); const d2 = g.getImageData(0, 0, c.width, c.height).data;
      let n = 0; for (let k = 0; k < d1.length; k++) if (d1[k] !== d2[k]) n++;
      return n + ' differing channel values';
    }, ['data:image/png;base64,' + now.toString('base64'), 'data:image/png;base64,' + was.toString('base64')]);
  // And the deterministic half: every element's resolved type and box
  // properties under each stylesheet. A slider or a countdown moves pixels;
  // it does not move what font-size or padding the cascade resolved.
  const PROPS = ['font-size', 'line-height', 'font-weight', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right', 'margin-top', 'margin-bottom', 'display', 'color', 'background-color', 'border-top-width', 'border-radius'];
  const styles = async (url) => { await page.goto(BASE + url, { waitUntil: 'networkidle' }); return page.evaluate((P) => Array.from(document.querySelectorAll('body *')).map((e) => { const c = getComputedStyle(e); return e.tagName + ':' + P.map((k) => c.getPropertyValue(k)).join('|'); }), PROPS); };
  for (const url of PAGES) {
    swap = false; const sNew = await styles(url);
    swap = true; const sOld = await styles(url);
    let n = 0; const tags = {};
    for (let i = 0; i < Math.max(sNew.length, sOld.length); i++) if (sNew[i] !== sOld[i]) { n++; const t = String(sNew[i]).split(':')[0]; tags[t] = (tags[t] || 0) + 1; if (process.env.KBB_SHOWDIFF) process.stdout.write('    new ' + sNew[i] + '\n    old ' + sOld[i] + '\n'); }
    out['styles ' + url] = { elements: sNew.length, elementsOld: sOld.length, differing: n, differingTags: tags };
    process.stdout.write('  styles ' + url + ' ' + JSON.stringify(out['styles ' + url]) + '\n');
  }
  if (TOUCH) { fs.writeFileSync(path.join(__dirname, '..', 'docs', 'zm-shots', 'touch-styles.json'), JSON.stringify(out, null, 1) + '\n'); await browser.close(); return; }
  for (const url of PAGES) {
    swap = false; const now = await shot(url);
    const again = await shot(url);
    swap = true; const before = served; const was = await shot(url);
    out[url] = { oldCssServed: served > before, newVsOld: await compare(now, was), control_newVsNew: await compare(now, again) };
    process.stdout.write('  ' + url + ' ' + JSON.stringify(out[url]) + '\n');
  }
  fs.writeFileSync(path.join(__dirname, '..', 'docs', 'zm-shots', 'laptop-same.json'), JSON.stringify(out, null, 1) + '\n');
  await browser.close();
})();
