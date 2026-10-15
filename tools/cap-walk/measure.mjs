/* Lane EH: checkout server ms and queries (median of N), HTML bytes, scripts
   and every request the page makes, on a preview started by preview.sh.
     node tools/eh-walk/measure.mjs PORT [RUNS] [PATH...] */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';

const [PORT, RUNS_ARG, ...PATHS] = process.argv.slice(2);
const RUNS = Number(RUNS_ARG || 15);
const BASE = `http://127.0.0.1:${PORT}`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 390, height: 844 } });
await ctx.route('**stripe.com/**', (r) => r.abort());
const page = await ctx.newPage();
await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await page.goto(`${BASE}/product/co-glow-serum`);
await page.evaluate(async () => {
  await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) });
});
const med = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
for (const path of (PATHS.length ? PATHS : ['/checkout/', '/product/co-glow-serum'])) {
  const ms = [], q = []; let bytes = 0, status = 0;
  for (let i = 0; i < RUNS; i++) {
    const r = await ctx.request.get(BASE + path);
    status = r.status();
    ms.push(Number(r.headers()['x-co-ms'])); q.push(Number(r.headers()['x-co-queries']));
    bytes = (await r.body()).length;
  }
  const reqs = [];
  const onReq = (r) => reqs.push(r.url().replace(BASE, ''));
  page.on('request', onReq);
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  page.off('request', onReq);
  const scripts = await page.evaluate(() => [...document.scripts].filter((s) => s.src).length);
  console.log(`${path.padEnd(24)} ${status} ms ${med(ms)} (min ${Math.min(...ms)}) queries ${med(q)} html ${bytes} B scripts ${scripts} requests ${reqs.length}`);
  console.log('   ' + reqs.filter((u) => !u.startsWith('data:')).join('\n   '));
}
await browser.close();
