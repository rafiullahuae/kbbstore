/* Lane CO: server ms and queries (the preview's X-Co-Ms / X-Co-Queries) for
   the checkout, product and shop pages, median of 12 warm requests, plus HTML
   bytes and render-blocking CSS bytes of the checkout.
   node measure.mjs PORT SERUM_ID */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
const PORT = process.argv[2], PID = Number(process.argv[3] || 25), BASE = `http://127.0.0.1:${PORT}`;
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
const p = await ctx.newPage();
await p.route('**stripe.com/**', r => r.abort());
await p.goto(`${BASE}/__co/forget-carts`);
await p.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await p.goto(`${BASE}/product/co-glow-serum`);
await p.evaluate(async (pid) => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }); }, PID);
const med = a => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
const out = {};
for (const [name, path] of [['checkout', '/checkout/'], ['product', '/product/co-glow-serum'], ['shop', '/shop/']]) {
  const ms = [], q = []; let bytes = 0;
  for (let i = 0; i < 13; i++) {
    const r = await p.request.get(BASE + path);
    if (i === 0) continue;
    ms.push(Number(r.headers()['x-co-ms'])); q.push(Number(r.headers()['x-co-queries']));
    bytes = (await r.body()).length;
  }
  out[name] = { ms: med(ms), queries: med(q), queriesMax: Math.max(...q), htmlBytes: bytes };
}
await p.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
out.checkoutCss = await p.evaluate(async () => {
  let total = 0; const list = [];
  for (const l of document.querySelectorAll('head link[rel="stylesheet"]')) { const t = await (await fetch(l.href)).text(); total += t.length; list.push(l.href.split('/').pop() + ' ' + t.length); }
  return { total, list };
});
out.cls = await p.evaluate(() => new Promise(res => { let v = 0; new PerformanceObserver(l => { for (const e of l.getEntries()) if (!e.hadRecentInput) v += e.value; }).observe({ type: 'layout-shift', buffered: true }); setTimeout(() => res(Math.round(v * 10000) / 10000), 1500); }));
console.log(JSON.stringify(out, null, 1));
await b.close();
