/* Lane CO: /checkout (with a basket), product, category and brand -- server ms
   and query count, median of N, on a preview started by preview.sh.
     node tools/co-card-walk/measure.mjs PORT SERUM_ID [RUNS] */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
const PORT = process.argv[2]; const SERUM = Number(process.argv[3]); const RUNS = Number(process.argv[4] || 15);
const BASE = `http://127.0.0.1:${PORT}`;
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' });
const page = await ctx.newPage();
await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await page.goto(`${BASE}/product/co-glow-serum`);
await page.evaluate(async (pid) => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }); }, SERUM);
const med = a => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
for (const path of ['/checkout/', '/product/co-glow-serum', '/shop/']) {
  const ms = [], q = []; let bytes = 0;
  for (let i = 0; i < RUNS; i++) {
    const r = await ctx.request.get(BASE + path);
    ms.push(Number(r.headers()['x-co-ms'])); q.push(Number(r.headers()['x-co-queries']));
    bytes = (await r.body()).length;
  }
  console.log(`${path.padEnd(24)} status ok  median ${med(ms)} ms  queries ${med(q)}  html ${bytes} B`);
}
await browser.close();
