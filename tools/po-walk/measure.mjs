/* Lane PO: product, category, brand and checkout -- server ms and queries
   (median of N), HTML bytes, render-blocking CSS bytes and CLS, on a preview
   started by preview.sh.   node tools/po-walk/measure.mjs PORT [RUNS] */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
const PORT = process.argv[2]; const RUNS = Number(process.argv[3] || 9);
const BASE = `http://127.0.0.1:${PORT}`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 390, height: 844 } });
const page = await ctx.newPage();
await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await page.goto(`${BASE}/product/co-glow-serum`);
await page.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) }); });
const med = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
for (const path of ['/product/co-glow-serum', '/product-category/po-serums/', '/brands/po-anua/', '/checkout/']) {
  const ms = [], q = []; let bytes = 0, status = 0;
  for (let i = 0; i < RUNS; i++) {
    const r = await ctx.request.get(BASE + path);
    status = r.status();
    ms.push(Number(r.headers()['x-co-ms'])); q.push(Number(r.headers()['x-co-queries']));
    bytes = (await r.body()).length;
  }
  await page.goto(BASE + path, { waitUntil: 'load' });
  await page.waitForTimeout(600);
  const m = await page.evaluate(async () => {
    const cls = performance.getEntriesByType('layout-shift').reduce((s, e) => s + (e.hadRecentInput ? 0 : e.value), 0);
    const css = [...document.querySelectorAll('link[rel=stylesheet]')].filter((l) => !l.media || l.media === 'all').map((l) => l.href);
    let cssBytes = 0;
    for (const h of css) { try { cssBytes += (await (await fetch(h)).text()).length; } catch (e) {} }
    const scripts = [...document.scripts].filter((s) => s.src).map((s) => s.src.replace(location.origin, ''));
    return { cls: Math.round(cls * 10000) / 10000, cssBytes, scripts: scripts.length, sw: document.documentElement.scrollWidth };
  });
  console.log(`${path.padEnd(30)} ${status} ms ${med(ms)} queries ${med(q)} html ${bytes} B  blocking-css ${m.cssBytes} B  scripts ${m.scripts}  CLS ${m.cls}  scrollWidth ${m.sw}`);
}
await browser.close();
