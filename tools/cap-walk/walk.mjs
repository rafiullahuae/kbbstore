/*
 * Lane EH -- the checkout email hint, driven in Chromium.
 *
 *   node tools/eh-walk/walk.mjs PORT WIDTH LOCALE OUTDIR [place|off]
 *
 * Proves, at one width, on a preview started by tools/eh-walk/preview.sh with
 * tools/eh-walk/switch-on.php applied:
 *   - typing shows nothing (checked after every key);
 *   - leaving the field with jovana8b@yahoo.co shows the line;
 *   - tapping yahoo.com fixes the field, fires its events, and checkout's own
 *     validation accepts it (the field is :valid, form has no :invalid email);
 *   - typing again hides it at once;
 *   - a@yahoo.co.uk and a@company.co show nothing;
 *   - a long local part stays on one line and never widens the page;
 *   - scrollWidth equals the width; zero console errors;
 *   - with `place`: cash on delivery still places the order;
 *   - with `off`: no marker, no chunk request, nothing shown.
 * Screenshots of the field area go to OUTDIR. Measuring here is the harness's,
 * never the shop's (the shop's script measures nothing).
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';

const [PORT, WIDTH = '390', LOCALE = 'en', OUT = '.', MODE = ''] = process.argv.slice(2);
const BASE = `http://127.0.0.1:${PORT}`;
const W = Number(WIDTH);
const prefix = LOCALE === 'ar' ? '/ar' : '';
const tag = `${LOCALE}-${W}`;
fs.mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const ctx = await browser.newContext({
  viewport: { width: W, height: W < 600 ? 844 : 900 },
  deviceScaleFactor: W < 600 ? 2 : 1,
  hasTouch: W < 600,
  isMobile: W < 600,
  userAgent: W < 600
    ? 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36'
    : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
});
// The sandbox cannot reach Stripe: its script is the walk's stand-in
// (tools/po-walk/stripe-stub.js, copied), anything else of Stripe's is empty.
const STUB = fs.readFileSync(new URL('./stripe-stub.js', import.meta.url), 'utf8');
await ctx.route('**/js.stripe.com/**', (r) => r.fulfill({ status: 200, contentType: 'application/javascript', body: STUB }));
await ctx.route('**/*.stripe.com/**', (r) => r.fulfill({ status: 204, body: '' }));
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });
const requests = [];
page.on('request', (r) => requests.push(r.url().replace(BASE, '')));

const results = [];
const check = (name, ok, detail = '') => { results.push({ name, ok: !!ok, detail }); };

await page.goto(`${BASE}/__co/in-stock/co-glow-serum`);
await page.goto(`${BASE}/product/co-glow-serum`, { waitUntil: 'domcontentloaded' });
await page.evaluate(async () => {
  await fetch('/api/cart/add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' }, body: JSON.stringify({ product_id: 25, quantity: 1 }) });
});
requests.length = 0;
await page.goto(`${BASE}${prefix}/checkout/`, { waitUntil: 'networkidle' });
await page.waitForTimeout(300);

const chunkFetched = () => requests.some((u) => /\/build\/assets\/email-fix-[^/]+\.js$/.test(u));

/* The state of the line under the field, and the page's width. */
const state = () => page.evaluate(() => {
  const line = document.querySelector('[data-efix]') || document.querySelector('.co-ehint');
  const btn = document.querySelector('[data-efix-use]');
  const field = document.getElementById('billing_email');
  const r = line && !line.hidden ? line.getBoundingClientRect() : null;
  const b = btn ? btn.getBoundingClientRect() : null;
  const hit = b ? document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2) : null;
  const fs = line ? parseFloat(getComputedStyle(line).fontSize) : 0;
  return {
    marker: !!document.querySelector('[data-efix]'),
    text: line && !line.hidden ? line.textContent : '',
    hidden: line ? line.hidden : true,
    button: btn ? btn.textContent : null,
    buttonHit: !!hit && (hit === btn || btn.contains(hit)),
    buttonColor: btn ? getComputedStyle(btn).color : null,
    buttonWeight: btn ? getComputedStyle(btn).fontWeight : null,
    lineHeight: r ? Math.round(r.height * 10) / 10 : 0,
    lineWidth: r ? Math.round(r.width) : 0,
    lineRight: r ? Math.round(r.right) : 0,
    lineLeft: r ? Math.round(r.left) : 0,
    fontSize: fs,
    oneLine: r ? r.height < fs * 2 : true,
    value: field ? field.value : null,
    valid: field ? field.checkValidity() : null,
    invalidInForm: !!document.querySelector('#kbbCheckoutForm #billing_email:invalid'),
    sw: document.documentElement.scrollWidth,
    dir: document.documentElement.dir || getComputedStyle(document.documentElement).direction,
  };
});

const shot = async (name) => {
  const box = await page.evaluate(() => {
    const f = document.getElementById('billing_email');
    f.closest('.form-row')?.scrollIntoView({ block: 'center', behavior: 'instant' });
    const row = f.closest('.form-row').getBoundingClientRect();
    return { top: row.top, bottom: row.bottom };
  });
  await page.waitForTimeout(120);
  const top = Math.max(0, Math.floor(box.top - 70));
  const h = Math.min(Math.ceil(box.bottom - box.top + 130), (W < 600 ? 844 : 900) - top);
  const file = `${OUT}/eh-${tag}-${name}.png`;
  await page.screenshot({ path: file, clip: { x: 0, y: top, width: W, height: h } });
  return file;
};

const field = page.locator('#billing_email');
const leave = async () => {
  // Leave the way a shopper does: tap the next field.
  const next = page.locator('#billing_first_name:visible, #billing_phone:visible').first();
  if (await next.count()) await next.click();
  else await page.keyboard.press('Tab');
  await page.waitForTimeout(150);
};

const s0 = await state();
check('page is ' + (LOCALE === 'ar' ? 'rtl' : 'ltr'), LOCALE === 'ar' ? s0.dir === 'rtl' : s0.dir !== 'rtl', s0.dir);
check('scrollWidth equals the width on load', s0.sw === W, `${s0.sw} vs ${W}`);

if (MODE === 'off') {
  check('OFF: no marker printed', !s0.marker);
  await field.click();
  await field.pressSequentially('jovana8b@yahoo.co', { delay: 15 });
  await leave();
  const s = await state();
  check('OFF: leaving with a typo shows nothing', !s.button, s.text);
  check('OFF: the hint chunk was never requested', !chunkFetched(), requests.filter((u) => u.includes('email')).join(' '));
  await shot('off-after-leaving');
} else {
  check('ON: marker printed', s0.marker);
  check('ON: the hint chunk was fetched (once)', requests.filter((u) => /email-fix-/.test(u)).length === 1, requests.filter((u) => /email-fix-/.test(u)).join(' '));
  const greyText = s0.text;

  // 1. Typing shows nothing, checked after every key.
  await field.click();
  await field.fill('');
  let shownWhileTyping = 0;
  const typed = 'jovana8b@yahoo.co';
  for (const ch of typed) {
    await page.keyboard.type(ch);
    const s = await state();
    if (s.button || s.text !== greyText) shownWhileTyping++;
  }
  check('typing shows nothing (17 keys, checked after each)', shownWhileTyping === 0, `${shownWhileTyping} keys showed something`);
  const typingShot = await shot('1-typing');

  // 2. Leaving shows the line.
  await leave();
  const s2 = await state();
  const domain = 'yahoo.com';
  check('leaving with jovana8b@yahoo.co shows the line', s2.button === domain && s2.text.includes('jovana8b@') && s2.text.includes(domain), JSON.stringify(s2.text));
  check('the domain button is pink #c13e63, weight 700', s2.buttonColor === 'rgb(193, 62, 99)' && Number(s2.buttonWeight) >= 700, `${s2.buttonColor} ${s2.buttonWeight}`);
  check('one line', s2.oneLine, `height ${s2.lineHeight}px at ${s2.fontSize}px`);
  check('the button answers a real tap (elementFromPoint)', s2.buttonHit);
  check('scrollWidth equals the width with the hint', s2.sw === W, `${s2.sw}`);
  check('the field was not changed on its own', s2.value === typed, s2.value);
  const leftShot = await shot('2-after-leaving');

  // 3. Tap fixes the field; its events fire; validation accepts it.
  await page.evaluate(() => {
    window.__ehEvents = [];
    const f = document.getElementById('billing_email');
    ['input', 'change'].forEach((t) => f.addEventListener(t, () => window.__ehEvents.push(t)));
  });
  if (W < 600) await page.locator('[data-efix-use]').tap(); else await page.locator('[data-efix-use]').click();
  await page.waitForTimeout(150);
  const s3 = await state();
  const events = await page.evaluate(() => window.__ehEvents.join(','));
  check('tap puts jovana8b@yahoo.com in the field', s3.value === 'jovana8b@yahoo.com', s3.value);
  check('tap fires input then change', events === 'input,change', events);
  check('checkout validation accepts it (field valid, not :invalid)', s3.valid === true && !s3.invalidInForm);
  check('tap restores the grey line', !s3.button && s3.text === greyText, JSON.stringify(s3.text));
  const tapShot = await shot('3-after-tap');

  // 4. Leaving a now-correct address shows nothing.
  await field.click(); await leave();
  check('a correct address shows nothing', !(await state()).button);

  // 5. Typing again hides it at once.
  await field.click(); await field.fill(''); await field.pressSequentially('jovana8b@yahoo.co', { delay: 10 });
  await leave();
  check('hint back for the typo', (await state()).button === domain);
  await field.click();
  check('focusing alone keeps it', (await state()).button === domain);
  await page.keyboard.type('m');
  const s5 = await state();
  check('one more key hides it at once', !s5.button && s5.text === greyText, JSON.stringify(s5.text));

  // 6. Real or unknown domains: nothing.
  for (const v of ['a@yahoo.co.uk', 'a@company.co', 'someone@gmail.com']) {
    await field.click(); await field.fill(v); await leave();
    const s = await state();
    check(`${v} shows nothing`, !s.button && s.text === greyText, JSON.stringify(s.text));
  }

  // 7. A long local part: one line, ellipsis in the local part, page not widened.
  await field.click(); await field.fill('averyveryverylongname.with.many.parts1234567@gmial.com'); await leave();
  const s7 = await state();
  check('long address: hint shows, domain whole', s7.button === 'gmail.com', s7.button);
  check('long address: one line', s7.oneLine, `height ${s7.lineHeight}`);
  check('long address: scrollWidth equals the width', s7.sw === W, `${s7.sw}`);
  check('long address: the line stays inside the viewport', s7.lineRight <= W && s7.lineLeft >= 0, `${s7.lineLeft}..${s7.lineRight}`);
  await shot('4-long-address');

  // 8. Place order with cash on delivery, the address fixed by the hint.
  if (MODE === 'place') {
    await field.click(); await field.fill('jovana8b@yahoo.co'); await leave();
    if (W < 600) await page.locator('[data-efix-use]').tap(); else await page.locator('[data-efix-use]').click();
    const fill = async (sel, v) => { if (await page.$(`${sel}:visible`)) { if (!(await page.inputValue(sel))) await page.fill(sel, v); } };
    await fill('#billing_phone', '0501234567');
    await fill('#billing_first_name', 'Jovana');
    await fill('#billing_last_name', 'Petrovic');
    await fill('#billing_address_1', 'Villa 12');
    await fill('#billing_address_2', 'Marina Walk');
    await fill('#billing_city', 'Dubai');
    const st = await page.$('#billing_state');
    if (st && (await st.evaluate((e) => e.tagName)) === 'SELECT') { if (!(await st.inputValue())) await page.selectOption('#billing_state', { index: 1 }); } else await fill('#billing_state', 'Dubai');
    await page.click('label[for="payment_method_cod"]');
    await page.waitForTimeout(300);
    const btn = page.locator('[data-place]:visible').first();
    await btn.scrollIntoViewIfNeeded();
    await btn.click();
    let landed = false;
    try { await page.waitForURL(/checkout\/success|order-received|thank/, { timeout: 15000 }); landed = true; } catch (e) {}
    check('place order (cash on delivery) reaches the success page', landed, page.url().replace(BASE, ''));
    if (landed) {
      const body = await page.textContent('body');
      check('the order carries the fixed address', body.includes('jovana8b@yahoo.com'), '');
      await page.screenshot({ path: `${OUT}/eh-${tag}-5-placed.png` });
    }
  }
  results.push({ name: 'shots', ok: true, detail: [typingShot, leftShot, tapShot].join(' ') });
}

check('zero console errors', errors.length === 0, errors.join(' | '));
await browser.close();

let bad = 0;
for (const r of results) {
  if (!r.ok) bad++;
  console.log(`${r.ok ? 'PASS' : 'FAIL'}  [${tag}${MODE ? ' ' + MODE : ''}] ${r.name}${r.detail ? '  -- ' + r.detail : ''}`);
}
process.exitCode = bad ? 1 : 0;
