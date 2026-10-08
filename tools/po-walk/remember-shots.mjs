/*
 * Lane PO -- "Remember my details on this device", shot and measured.
 *
 *   node tools/po-walk/remember-shots.mjs PORT SERUM_ID OUT_PREFIX WIDTH LOCALE
 *
 * Four frames on one sheet: the first visit filled in; the SECOND visit as it
 * opens (restored, "Not you? Clear details" in the Contact bar, the tick under
 * the address); after pressing the link; and the tick unticked. Each frame is
 * captioned with what localStorage holds at that moment and the page's CLS.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';

const [PORT, SERUM, OUT, WIDTH = '390', LOCALE = 'en'] = process.argv.slice(2);
const BASE = `http://127.0.0.1:${PORT}`;
const W = Number(WIDTH);
const prefix = LOCALE === 'ar' ? '/ar' : '';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({
  viewport: { width: W, height: W < 600 ? 844 : 900 },
  userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
});
await ctx.route('**stripe.com/**', (r) => r.abort());
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));

await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
await page.evaluate(async (pid) => {
  await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: pid, quantity: 1 }) });
}, Number(SERUM));

const state = () => page.evaluate(() => {
  let kept = null;
  try { kept = localStorage.getItem('kbb.checkout.details.v1'); } catch (e) { kept = 'THREW'; }
  const cls = performance.getEntriesByType('layout-shift').reduce((s, e) => s + (e.hadRecentInput ? 0 : e.value), 0);
  const c = document.getElementById('kbbRememberClear');
  const r = c ? c.getBoundingClientRect() : null;
  const hit = r && r.width ? document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2) : null;
  return {
    kept: kept ? Object.keys(JSON.parse(kept).f || {}).join(', ') : '(nothing)',
    cls: Math.round(cls * 10000) / 10000,
    clear: c ? getComputedStyle(c).visibility : 'none',
    clearHit: !!hit && (hit === c || c.contains(hit)),
    sw: document.documentElement.scrollWidth,
  };
});

const shots = [];
const snap = async (label) => {
  await page.evaluate(() => document.querySelector('.sec h2')?.scrollIntoView({ block: 'start', behavior: 'instant' }));
  await page.evaluate(() => window.scrollBy(0, -70));
  await page.waitForTimeout(250);
  const s = await state();
  shots.push({ label, s, img: (await page.screenshot()).toString('base64') });
};

// 1. First visit: typed in, each box left so its `change` fires.
await page.goto(`${BASE}${prefix}/checkout/`, { waitUntil: 'networkidle' });
for (const [sel, v] of [['#billing_first_name', 'Aisha Khan'], ['#billing_phone', '0501234567'], ['#billing_email', 'aisha@example.com'], ['#billing_address_1', 'Villa 12'], ['#billing_address_2', 'Marina Walk']]) {
  if (await page.$(`${sel}:visible`)) await page.fill(sel, v);
}
if (await page.$('select#billing_state')) await page.selectOption('#billing_state', 'Dubai');
await page.focus('#billing_first_name');
await snap(LOCALE === 'ar' ? '1 · first visit, typed' : '1 · first visit, typed');

// 2. Second visit: nothing typed.
await page.goto(`${BASE}${prefix}/checkout/`, { waitUntil: 'networkidle' });
await page.waitForTimeout(300);
await snap('2 · next visit: restored');
const restored = shots[shots.length - 1].s;

// 3. "Not you? Clear details".
await page.click('#kbbRememberClear');
await snap('3 · after "Not you? Clear details"');

// 4. Refill, then untick "Remember my details on this device".
await page.fill('#billing_first_name', 'Aisha Khan');
await page.focus('#billing_phone');
await page.click('label[for="kbb_remember"]');
await snap('4 · tick off: copy erased');

const thumb = W < 600 ? 300 : 620;
const sheet = await browser.newPage({ viewport: { width: (thumb + 14) * (W < 600 ? 4 : 2) + 14, height: 400 } });
await sheet.setContent(`<!doctype html><meta charset=utf-8><style>body{margin:0;padding:12px;font:13px/1.35 system-ui;background:#f4f1f2}
h1{font-size:15px;margin:0 0 8px}.g{display:grid;grid-template-columns:repeat(${W < 600 ? 4 : 2},${thumb}px);gap:14px}figure{margin:0;background:#fff;border:1px solid #ddd}
img{display:block;width:${thumb}px}figcaption{padding:6px 8px}b{display:block}</style>
<h1>Remember my details on this device — ${LOCALE} @ ${W}px</h1><div class=g>${shots.map((x) => `<figure><img src="data:image/png;base64,${x.img}"><figcaption><b>${x.label}</b>localStorage: ${x.s.kept}<br>"Not you?" link: ${x.s.clear}${x.s.clear === 'visible' ? (x.s.clearHit ? ' (answers a click)' : ' (NOT under the pointer)') : ''} · CLS ${x.s.cls} · scrollWidth ${x.s.sw}</figcaption></figure>`).join('')}</div>`);
await sheet.screenshot({ path: `${OUT}.png`, fullPage: true });
fs.writeFileSync(`${OUT}.json`, JSON.stringify({ shots: shots.map((x) => ({ label: x.label, ...x.s })), errors }, null, 1));
console.log(JSON.stringify({ restored, errors }));
await browser.close();
