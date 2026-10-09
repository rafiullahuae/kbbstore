// Lane QK3: a browser-made cart, printed as the encrypted kbb_cart cookie for tools/qk3-speed.sh.
const { chromium } = require('playwright');
(async () => {
  const BASE = process.argv[2] || 'http://127.0.0.1:10970';
  const b = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36' });
  const p = await ctx.newPage();
  await p.goto(BASE + '/product/barrier-repair-cream/', { waitUntil: 'networkidle' });
  await p.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: 20, quantity: 1 }) }); });
  const c = (await ctx.cookies()).find((x) => x.name === 'kbb_cart');
  console.log(c.value);
  await b.close();
})();
