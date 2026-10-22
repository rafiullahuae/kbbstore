/* Lane FG: the free gifts box in the cart panel, on the cart page and in the
   checkout summary, closed and opened, at 390 and 1280, English and Arabic, in
   a basket for each band; plus measurements (title size, tile size, the
   clickable check on View all / Hide, scrollWidth, console errors).
   BASE=http://127.0.0.1:PORT node tools/fg-shots.cjs [ids-json] */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-fg');
fs.mkdirSync(OUT, { recursive: true });
const BANDS = JSON.parse(process.env.BANDS || '{"100":40,"300":41,"500":42,"1000":43,"2000":44}');
const ONLY = process.env.ONLY || '';
const out = [];
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

async function basket(page, pre, pid) {
  await page.goto(BASE + pre + '/', { waitUntil: 'domcontentloaded' });
  const r = await page.evaluate(async (id) => {
    const res = await fetch((window.KBB && window.KBB.base || '') + '/api/cart/add', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
    return res.status;
  }, pid);
  if (r !== 200) throw new Error('add ' + r);
}

async function measure(page, scope) {
  return page.evaluate((scope) => {
    const root = scope ? document.querySelector(scope) : document;
    const f = [...root.querySelectorAll('.fgb')].find((x) => x.getClientRects().length);
    if (!f) return { box: false, scrollW: document.documentElement.scrollWidth };
    const lg = f.querySelector('legend'), t = f.querySelector('.fgb-th .fgb-t'), b = f.querySelector('.fgb-th .fgb-tg');
    const br = b.getBoundingClientRect(), hit = document.elementFromPoint(br.left + br.width / 2, br.top + br.height / 2);
    const fr = f.getBoundingClientRect();
    return { box: true, boxW: Math.round(fr.width), boxH: Math.round(fr.height), title: getComputedStyle(lg).fontSize + '/' + getComputedStyle(lg).fontWeight,
      tile: t ? Math.round(t.getBoundingClientRect().width) : null, tiles: f.querySelectorAll('.fgb-th .fgb-t').length,
      viewAllClickable: hit === b || b.contains(hit), border: getComputedStyle(f).borderTopWidth + ' ' + getComputedStyle(f).borderTopColor,
      scrollW: document.documentElement.scrollWidth };
  }, scope);
}

async function openAndShoot(page, scope, file, w) {
  const sel = (scope ? scope + ' ' : '') + '.fgb';
  const f = page.locator(sel).filter({ visible: true }).first();
  await f.scrollIntoViewIfNeeded();
  await f.screenshot({ path: `${OUT}/${file}-closed.png` });
  await f.locator('.fgb-th .fgb-tg').click();
  const open = await f.evaluate((x) => ({ listShown: !x.querySelector('.fgb-ls').hidden, thumbsHidden: x.querySelector('.fgb-th').hidden,
    focus: document.activeElement && document.activeElement.textContent, rowName: getComputedStyle(x.querySelector('.fgb-ln')).fontSize,
    rowTile: Math.round(x.querySelector('.fgb-lr .fgb-t').getBoundingClientRect().width), free: getComputedStyle(x.querySelector('.fgb-lv')).fontSize + '/' + getComputedStyle(x.querySelector('.fgb-lv')).color }));
  await f.screenshot({ path: `${OUT}/${file}-open.png` });
  await f.locator('.fgb-ls .fgb-tg').click();
  const closedAgain = await f.evaluate((x) => x.querySelector('.fgb-ls').hidden && !x.querySelector('.fgb-th').hidden);
  return { ...open, closedAgain };
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const lang of ['en', 'ar']) for (const w of [390, 1280]) for (const [band, pid] of Object.entries(BANDS)) {
    if (ONLY && !ONLY.split(',').includes(band)) continue;
    const pre = lang === 'ar' ? '/ar' : '';
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage(); const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    await basket(page, pre, pid);
    const tag = `${lang}-${w}-${band}`;

    // 1. the cart panel
    await page.goto(BASE + pre + '/', { waitUntil: 'networkidle' });
    await page.locator('[data-kbb-cart]').first().click();
    await page.locator('#cart .fgb').first().waitFor({ state: 'visible', timeout: 8000 });
    await page.waitForTimeout(400);
    const panel = await measure(page, '#cart');
    await page.screenshot({ path: `${OUT}/panel-${tag}.png` });
    const panelOpen = await openAndShoot(page, '#cart', `panel-box-${tag}`, w);

    // 2. the cart page
    await page.goto(BASE + pre + '/cart/', { waitUntil: 'networkidle' });
    const cart = await measure(page, 'main');
    await page.locator('main .fgb').first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/cart-${tag}.png` });
    const cartOpen = await openAndShoot(page, 'main', `cart-box-${tag}`, w);

    // 3. the checkout summary (opened on a phone)
    await page.goto(BASE + pre + '/checkout/', { waitUntil: 'networkidle' });
    if (w === 390) { const row = page.locator('#kbbSummary .cosr').first(); if (await row.count()) await row.click(); await page.waitForTimeout(400); }
    const co = await measure(page, '#kbbSummary');
    const line = await page.evaluate(() => { const l = [...document.querySelectorAll('.fg-line')].find((x) => x.getClientRects().length); return l ? [...l.querySelectorAll('span')].map((x) => x.textContent.trim()).join(' | ') : null; });
    await page.locator('#kbbSummary .fgb').first().scrollIntoViewIfNeeded();
    await page.screenshot({ path: `${OUT}/checkout-${tag}.png` });
    const coOpen = await openAndShoot(page, '#kbbSummary', `checkout-box-${tag}`, w);

    out.push({ tag, panel, panelOpen, cart, cartOpen, checkout: co, checkoutOpen: coOpen, line, errors });
    console.log(tag, JSON.stringify({ panel, cart: cart.box, checkout: co.box, line, errors: errors.length }));
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(out, null, 1));
  await browser.close();
})();
