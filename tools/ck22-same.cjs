/* Lane CK (22 October): with every new size at its default, is the page the
   SAME? The checkout, the cart page and the opened cart panel are drawn twice
   in Chromium -- once with this branch's stylesheets, once with the
   stylesheets the branch started from (OLD_DIR, extracted from git) served in
   their place -- and every element's computed font-size, padding, margin,
   gaps, line-height and box height are compared.
     BASE=http://127.0.0.1:PORT OLD_DIR=storage/ck-logs/old node tools/ck22-same.cjs
   Local preview only. Prints the differences (expected: none). */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OLD = process.env.OLD_DIR;
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const UA_M = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
const PROPS = ['fontSize', 'paddingTop', 'paddingBottom', 'paddingLeft', 'paddingRight', 'marginTop', 'marginBottom', 'marginLeft', 'marginRight', 'rowGap', 'columnGap', 'lineHeight'];

function dump(props) {
  const out = [];
  const all = document.querySelectorAll('body *');
  all.forEach((el, i) => {
    if (el.closest('script,style,noscript,svg')) return;
    const cs = getComputedStyle(el);
    const row = [i, el.tagName + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '')];
    props.forEach((p) => row.push(cs[p]));
    row.push(Math.round(el.getBoundingClientRect().height * 10) / 10);
    row.push(cs.display + ' ' + cs.color);
    out.push(row);
  });
  return out;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const old = {
    'kbb-checkout': fs.readFileSync(path.join(OLD, 'kbb-checkout.css')),
    'kbb-cart': fs.readFileSync(path.join(OLD, 'kbb-cart.css')),
    'kbb': fs.readFileSync(path.join(OLD, 'kbb.css')),
  };
  const report = [];
  for (const lang of ['en', 'ar']) {
    for (const w of [390, 1280]) {
      for (const where of ['/checkout/', '/cart/']) {
        const runs = [];
        for (const useOld of [false, true]) {
          const m = w === 390;
          const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, isMobile: m, hasTouch: m, userAgent: m ? UA_M : UA_D, serviceWorkers: 'block' });
          const page = await ctx.newPage();
          if (useOld) {
            await page.route(/\/build\/assets\/kbb(-checkout|-cart)?-[A-Za-z0-9_-]{8}\.css$/, (route) => {
              const name = route.request().url().match(/\/(kbb(?:-checkout|-cart)?)-[A-Za-z0-9_-]{8}\.css$/)[1];
              route.fulfill({ status: 200, contentType: 'text/css', body: old[name] });
            });
          }
          const pre = lang === 'ar' ? '/ar' : '';
          await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
          for (const id of [26, 5, 11]) {
            await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
          }
          await page.goto(BASE + pre + where, { waitUntil: 'networkidle' });
          if (where === '/cart/') await page.evaluate(() => { const c = document.getElementById('cart'); if (c) c.classList.add('on'); });
          await page.waitForTimeout(600);
          runs.push(await page.evaluate(dump, PROPS));
          await ctx.close();
        }
        const [now, before] = runs;
        const diffs = [];
        if (now.length !== before.length) diffs.push(`element count ${before.length} -> ${now.length}`);
        for (let i = 0; i < Math.min(now.length, before.length); i++) {
          for (let k = 2; k < now[i].length; k++) {
            if (now[i][k] !== before[i][k]) {
              diffs.push(`${now[i][1]} ${k - 2 < PROPS.length ? PROPS[k - 2] : (k - 2 === PROPS.length ? 'height' : 'display/colour')}: ${before[i][k]} -> ${now[i][k]}`);
            }
          }
        }
        report.push({ lang, w, where, elements: now.length, differences: diffs.length, sample: diffs.slice(0, 25) });
      }
    }
  }
  await browser.close();
  fs.writeFileSync(path.resolve(__dirname, '../docs/lane-ck/same-at-defaults.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
