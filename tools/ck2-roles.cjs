/* Lane CK2 (23 October 2026): which text role each twin rule belongs to,
   measured on the real pages rather than guessed from selectors.

   Run against a preview of commit 0e27fb0f (Lane CK: the role containers and
   twins still in the stylesheets). Each role's factor is set to a distinct
   marker number; every element a twin's selector matches reports the factor
   it inherits, which names the role container it sits in.

     BASE=http://127.0.0.1:PORT node tools/ck2-roles.cjs <twins.json> <out.json>

   tools/ck2-twins.php adds the roles a selector names by text, and writes the
   PHP table. Local preview only. */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE;
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const twins = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));

const SURF = {
  checkout: {
    css: '.kbb-checkout{--cop-ovt:1!important;--cop-trows:2!important;--cop-ttotal:3!important;--cop-tcoupon:5!important;--cop-thelp:7!important;--cop-tdlv:11!important;--cop-tpay:13!important;--cop-ttick:17!important}',
    v: '--ckt', roles: { 2: 'rows', 3: 'total', 5: 'coupon', 7: 'help', 11: 'dlv', 13: 'pay', 17: 'tick' },
  },
  cartpage: {
    css: '.kbb-cartpage{--cgz-ovt:1!important;--cgz-tsumh:2!important;--cgz-trows:3!important;--cgz-ttotal:5!important;--cgz-tcoupon:7!important;--cgz-thelp:11!important}',
    v: '--cgt', roles: { 2: 'sumh', 3: 'rows', 5: 'total', 7: 'coupon', 11: 'help' },
  },
  cartpanel: {
    css: '#cart{--cpz-ovt:1!important;--cpz-ttabs:2!important;--cpz-tsum:3!important;--cpz-thelp:5!important;--cpz-tcoupon:7!important}',
    v: '--kpt', roles: { 2: 'tabs', 3: 'sum', 5: 'help', 7: 'coupon' },
  },
};

function scan(arg) {
  const { list, v, roles } = arg;
  const variants = (sel) => {
    const noPe = sel.replace(/::?(before|after|placeholder|marker|-webkit-[\w-]+|-moz-[\w-]+)\b/g, '');
    const noDyn = noPe.replace(/(^|[^(,\s]):(hover|focus-visible|focus-within|focus|active)\b/g, '$1');
    return [sel, noPe, noDyn];
  };
  return list.map((sel) => {
    const found = new Set();
    for (const s of variants(sel)) {
      let els = [];
      try { els = document.querySelectorAll(s); } catch (e) { continue; }
      els.forEach((el) => {
        const raw = getComputedStyle(el).getPropertyValue(v).trim();
        const m = raw.match(/\*\s*(\d+)\s*\)\s*$/);
        if (m && roles[m[1]]) found.add(roles[m[1]]);
      });
    }
    return [...found];
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = twins.map(() => new Set());
  for (const w of [390, 1280]) {
    for (const where of ['/checkout/', '/cart/', '/shop/']) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, serviceWorkers: 'block', userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' });
      const page = await ctx.newPage();
      await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
      const ids = await (await page.request.get(BASE + '/ck-ids.json')).json();
      for (const id of ids.slice(0, 3)) {
        await page.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
      }
      await page.goto(BASE + where, { waitUntil: 'networkidle' });
      await page.evaluate(() => { const c = document.getElementById('cart'); if (c) c.classList.add('on'); });
      for (const [name, s] of Object.entries(SURF)) {
        await page.addStyleTag({ content: s.css });
        const idx = twins.map((t, i) => (t.surface === name ? i : -1)).filter((i) => i >= 0);
        const res = await page.evaluate(scan, { list: idx.map((i) => twins[i].sel), v: s.v, roles: s.roles });
        res.forEach((r, k) => r.forEach((x) => out[idx[k]].add(x)));
      }
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(process.argv[3], JSON.stringify(out.map((s) => [...s].sort())));
  const n = out.filter((s) => s.size).length;
  console.log(`twins ${twins.length}, with a measured role ${n}`);
})();
