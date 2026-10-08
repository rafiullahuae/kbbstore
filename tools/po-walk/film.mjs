/*
 * Lane PO -- Place order, frame by frame, with the timeline under it.
 *
 *   node tools/po-walk/film.mjs PORT SERUM_ID OUT_PREFIX [WIDTH] [METHOD] [SCENARIO] [LOCALE]
 *
 *   METHOD    cod | stripe
 *   SCENARIO  plain | 3ds | decline | soldout
 *   LOCALE    en | ar
 *
 * What it records, all against the moment Place order was pressed:
 *   - every frame Chromium painted (CDP screencast), kept when it differs
 *     from the one before, and laid out as one filmstrip PNG;
 *   - the overlay's life: added, its classes, its title, removed -- and the
 *     #toast, and the stand-in bank modal;
 *   - each POST's timing, with the server's own ms, queries, Stripe ms and
 *     mail ms (headers the preview's front controller adds);
 *   - the thank-you page: request, first byte, first contentful paint.
 *
 * It REPORTS; the reader judges. Writes OUT_PREFIX.png and OUT_PREFIX.json.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';
import path from 'path';
import crypto from 'crypto';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const [PORT, SERUM, OUT, WIDTH = '390', METHOD = 'cod', SCENARIO = 'plain', LOCALE = 'en'] = process.argv.slice(2);
const BASE = `http://127.0.0.1:${PORT}`;
const W = Number(WIDTH);
const STUB = fs.readFileSync(`${HERE}/stripe-stub.js`, 'utf8');
const REDUCED = process.env.PO_REDUCED === '1';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const context = await browser.newContext({
  viewport: { width: W, height: W < 600 ? 844 : 900 },
  userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
  reducedMotion: REDUCED ? 'reduce' : 'no-preference',
});
const page = await context.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
page.on('console', (m) => { if (m.type() === 'error' && !/status of 422/.test(m.text())) errors.push('console: ' + m.text()); });
await page.route('**stripe.com/**', (r) => r.abort());
await page.route('**js.stripe.com/**', (r) => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));

const stub = METHOD !== 'stripe' ? null
  : SCENARIO === '3ds' ? { paymentIntent: { status: 'succeeded' }, __threeDS: 1500 }
    : SCENARIO === 'decline' ? { error: { message: 'Your card was declined.' }, __delay: 600 }
      : { paymentIntent: { status: 'succeeded' }, __delay: 600 };

await page.addInitScript((a) => {
  if (a) window.__stripeStub = a;
  window.__onConfirm = (secret) => fetch('/__co/confirmed/' + String(secret).split('_secret')[0]);
  const marks = window.__poMarks = window.__poMarks || [];
  const mark = (n, x) => marks.push([n, performance.timeOrigin + performance.now(), x || '']);
  document.addEventListener('click', (e) => { if (e.target.closest && e.target.closest('[data-place]')) mark('press'); }, true);
  const isBox = (n) => n && n.nodeType === 1 && n.classList && n.classList.contains('kbb-placing');
  new MutationObserver((muts) => {
    for (const m of muts) {
      if (m.type === 'childList') {
        m.addedNodes.forEach((n) => {
          if (isBox(n)) mark('box-added', n.className);
          if (n.id === 'stubBank') mark('bank-modal-open');
        });
        m.removedNodes.forEach((n) => {
          if (isBox(n)) mark('box-removed');
          if (n.id === 'stubBank') mark('bank-modal-closed');
        });
        const t = m.target;
        if (t.classList && t.classList.contains('kbb-placing-title')) mark('box-title', t.textContent);
        if (t.id === 'toast') mark('toast-text', t.textContent);
      } else if (m.type === 'attributes') {
        const t = m.target;
        if (isBox(t)) mark('box-class', t.className);
        if (t.id === 'toast') mark('toast-class', t.className + ' | ' + t.textContent);
        if (t.matches && t.matches('[data-place]')) mark('button', (t.disabled ? 'disabled ' : 'enabled ') + t.textContent.trim().slice(0, 40));
      }
    }
  }).observe(document, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'disabled'] });
  addEventListener('pagehide', () => {
    try {
      const all = JSON.parse(sessionStorage.getItem('__poMarks') || '[]');
      sessionStorage.setItem('__poMarks', JSON.stringify(all.concat(marks)));
    } catch (e) {}
  });
}, stub);

const responses = [];
page.on('response', async (r) => {
  const u = r.url().replace(BASE, '');
  if (r.request().method() !== 'POST' && !u.startsWith('/checkout/success') && !u.startsWith('/ar/checkout/success')) return;
  if (u.startsWith('/__co')) return;
  const h = r.headers();
  const t = r.request().timing();
  responses.push({
    url: u.split('?')[0], status: r.status(), serverMs: Number(h['x-co-ms'] || 0), queries: Number(h['x-co-queries'] || 0),
    stripeMs: Number(h['x-po-stripe-ms'] || 0), mailMs: Number(h['x-po-mail-ms'] || 0),
    start: t.startTime, ttfb: t.startTime + t.responseStart, end: null, req: r.request(),
  });
});
page.on('requestfinished', (q) => {
  const row = responses.find((x) => x.req === q);
  if (row) { const t = q.timing(); row.end = t.startTime + t.responseEnd; }
});

/* The basket, and the checkout filled in the way a shopper fills it. */
await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await page.goto(`${BASE}/__co/in-stock/co-fwee-jelly-pot`);
await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
await page.evaluate(async (pid) => {
  await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: pid, quantity: 1 }) });
}, Number(SERUM));
if (SCENARIO === 'soldout') await page.goto(`${BASE}/__co/sold-out/co-glow-serum`);
const prefix = LOCALE === 'ar' ? '/ar' : '';
await page.goto(`${BASE}${prefix}/checkout/`, { waitUntil: 'networkidle' });
const fill = async (sel, v) => { if (await page.$(`${sel}:visible`)) { const cur = await page.inputValue(sel); if (!cur) await page.fill(sel, v); } };
await fill('#billing_email', 'buyer@example.com');
await fill('#billing_phone', '0501234567');
await fill('#billing_first_name', 'Aisha');
await fill('#billing_last_name', 'Khan');
await fill('#billing_address_1', 'Villa 12');
await fill('#billing_address_2', 'Marina Walk');
await fill('#billing_city', 'Dubai');
const st = await page.$('#billing_state');
if (st && (await st.evaluate((e) => e.tagName)) === 'SELECT') { if (!(await st.inputValue())) await page.selectOption('#billing_state', { index: 1 }); } else await fill('#billing_state', 'Dubai');
await page.click(`label[for="payment_method_${METHOD}"]`);
await page.waitForTimeout(400);

const btn = page.locator('[data-place]:visible').first();
await btn.scrollIntoViewIfNeeded();
await page.waitForTimeout(200);

/* Frames. */
const cdp = await context.newCDPSession(page);
const frames = [];
cdp.on('Page.screencastFrame', async (f) => {
  frames.push({ at: f.metadata.timestamp * 1000, data: f.data });
  try { await cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }); } catch (e) {}
});
await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 70, everyNthFrame: 1 });
await page.waitForTimeout(150);

await btn.click();

let landed = false;
try {
  await page.waitForURL(/checkout\/success/, { timeout: SCENARIO === 'decline' || SCENARIO === 'soldout' ? 3500 : 15000 });
  landed = true;
  await page.waitForLoadState('load');
  await page.waitForTimeout(700);
} catch (e) { await page.waitForTimeout(500); }
await cdp.send('Page.stopScreencast').catch(() => {});

const after = await page.evaluate(() => {
  let prior = [];
  try { prior = JSON.parse(sessionStorage.getItem('__poMarks') || '[]'); } catch (e) {}
  const nav = performance.getEntriesByType('navigation')[0];
  const fcp = performance.getEntriesByName('first-contentful-paint')[0];
  const o = performance.timeOrigin;
  return {
    marks: prior.concat(window.__poMarks || []),
    url: location.pathname,
    nav: nav ? { start: o, requestStart: o + nav.requestStart, responseStart: o + nav.responseStart, responseEnd: o + nav.responseEnd, dcl: o + nav.domContentLoadedEventEnd } : null,
    fcp: fcp ? o + fcp.startTime : null,
    toastNow: (document.getElementById('toast') || {}).textContent || '',
    boxNow: !!document.querySelector('.kbb-placing'),
  };
});
const press = (after.marks.find((m) => m[0] === 'press') || [])[1];
const rel = (t) => (t == null || press == null ? null : Math.round(t - press));
const marks = after.marks.map(([n, t, x]) => [rel(t), n, x]).sort((a, b) => a[0] - b[0]);

const timeline = {
  width: W, method: METHOD, scenario: SCENARIO, locale: LOCALE, reduced: REDUCED, landed, url: after.url,
  marks,
  requests: responses.filter((r) => r.start >= press - 50).map(({ req, ...r }) => ({ ...r, start: rel(r.start), ttfb: rel(r.ttfb), end: rel(r.end) })),
  thankYou: after.nav ? { navStart: rel(after.nav.start), requestStart: rel(after.nav.requestStart), responseStart: rel(after.nav.responseStart), responseEnd: rel(after.nav.responseEnd), fcp: rel(after.fcp) } : null,
  boxOpenedTimes: marks.filter((m) => m[1] === 'box-added').length,
  boxRemovedTimes: marks.filter((m) => m[1] === 'box-removed').length,
  toasts: marks.filter((m) => m[1].startsWith('toast')),
  errors,
};

/* The filmstrip: frames from the press to after the thank-you page's first
   paint, consecutive duplicates dropped, at most 18 kept (evenly). */
let kept = [];
let lastHash = '';
for (const f of frames.sort((a, b) => a.at - b.at)) {
  if (press != null && f.at < press - 120) { kept = [f]; continue; }
  const h = crypto.createHash('md5').update(f.data).digest('hex');
  if (h === lastHash) continue;
  lastHash = h;
  kept.push(f);
}
if (kept.length > 18) {
  const step = (kept.length - 1) / 17;
  kept = Array.from({ length: 18 }, (_, i) => kept[Math.round(i * step)]);
}
timeline.frames = kept.map((f) => rel(f.at));

if (process.env.PO_NOSHEET === '1') {
  fs.writeFileSync(`${OUT}.json`, JSON.stringify(timeline, null, 1));
  console.log(JSON.stringify({ out: OUT, landed, opened: timeline.boxOpenedTimes, removed: timeline.boxRemovedTimes, errors }));
  await browser.close();
  process.exit(0);
}
const cols = W < 600 ? 6 : 4;
const thumbW = W < 600 ? 195 : 320;
const sheet = await browser.newPage({ viewport: { width: cols * (thumbW + 12) + 12, height: 400 } });
const cards = kept.map((f) => `<figure><img src="data:image/jpeg;base64,${f.data}" width="${thumbW}"><figcaption>${rel(f.at) >= 0 ? '+' : ''}${rel(f.at)} ms</figcaption></figure>`).join('');
const legend = marks.filter((m) => !m[1].startsWith('button')).map((m) => `<li><b>${m[0]} ms</b> ${m[1]} <i>${String(m[2]).replace(/</g, '&lt;').slice(0, 60)}</i></li>`).join('');
await sheet.setContent(`<!doctype html><meta charset=utf-8><style>body{margin:0;padding:12px;font:13px/1.35 system-ui;background:#f4f1f2;color:#222}
h1{font-size:16px;margin:0 0 8px}.g{display:grid;grid-template-columns:repeat(${cols},${thumbW}px);gap:12px}figure{margin:0;background:#fff;border:1px solid #ddd}
img{display:block}figcaption{padding:4px 6px;font-weight:700}ul{columns:2;margin:10px 0 0;padding-left:18px}i{color:#666}</style>
<h1>${process.env.PO_TITLE || ''} — ${METHOD} / ${SCENARIO} / ${LOCALE} @ ${W}px — box opened ${timeline.boxOpenedTimes}×, removed ${timeline.boxRemovedTimes}×</h1>
<div class=g>${cards}</div><ul>${legend}</ul>`);
await sheet.screenshot({ path: `${OUT}.png`, fullPage: true });
fs.writeFileSync(`${OUT}.json`, JSON.stringify(timeline, null, 1));
console.log(JSON.stringify({ out: OUT, landed, opened: timeline.boxOpenedTimes, removed: timeline.boxRemovedTimes, toasts: timeline.toasts.length, requests: timeline.requests, thankYou: timeline.thankYou, errors }, null, 0));
await browser.close();
