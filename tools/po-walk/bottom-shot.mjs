/* Lane PO: the bottom of the checkout at 390, with the layout viewport shrunk
   the way a keyboard shrinks it (Firefox resizes the layout viewport), scrolled
   to the very bottom.   node tools/po-walk/bottom-shot.mjs PORT OUTPREFIX [height] */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
const [PORT, OUT, H = '480'] = process.argv.slice(2);
const BASE = `http://127.0.0.1:${PORT}`;
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0' });
await ctx.route('**stripe.com/**', (r) => r.abort());
const page = await ctx.newPage();
await page.goto(`${BASE}/product/co-glow-serum`);
await page.evaluate(async () => { await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) }); });
await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
for (const [label, h] of [['keyboard-closed', 844], ['keyboard-open', Number(H)]]) {
  await page.setViewportSize({ width: 390, height: h });
  await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
  await page.waitForTimeout(400);
  const m = await page.evaluate(() => {
    const b = getComputedStyle(document.body), last = document.querySelector('.kbb-mobile-order .place');
    const r = last.getBoundingClientRect();
    return { bodyPadBottom: b.paddingBottom, bodyBg: b.backgroundColor, htmlBg: getComputedStyle(document.documentElement).backgroundColor,
      gapUnderButton: Math.round(innerHeight - r.bottom), innerHeight, scrollH: document.documentElement.scrollHeight,
      mpbar: (() => { const e = document.querySelector('.mpbar'); if (!e) return null; const s = getComputedStyle(e); return s.visibility + '/' + s.opacity; })() };
  });
  console.log(label, JSON.stringify(m));
  await page.screenshot({ path: `${OUT}-${label}.png` });
}
await browser.close();
