/* Lane CK2 (23 October): do the admin's live previews apply EXACTLY the block
   the shop prints after Save? On the local preview, for each of the three
   screens (Appearance -> Checkout page / Cart page / Cart panel):
     - open the Desktop size tab, move Overall · Text % and the Mobile tab's,
       then switch to Arabic and move an Arabic size; count the requests the
       moves make (expected 0);
     - read the frames' live <style> (English frame, Arabic frame);
     - Save; open the shop page, English and /ar, and read the block it prints;
     - the strings must be equal, byte for byte.
   Then every size goes back to its default and is saved.
     BASE=http://127.0.0.1:PORT node tools/ck2-admin.cjs
   Pictures and facts to docs/lane-ck2/. Local preview only. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE;
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const OUT = path.resolve(__dirname, '../docs/lane-ck2');
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

const SCREENS = [
  { id: 'checkoutpage', p: 'chp', tabs: ['desktop_type', 'mobile_type'], key: 'd_t_rows', kv: 120, mkey: 'm_ov_text', mv: 10, arKey: 'd_t_title', shop: '/checkout/', style: 'kbb-cotype', api: '/checkout-page' },
  { id: 'cartpage', p: 'cps', tabs: ['type_d', 'type_m'], key: 'tx_total', kv: 125, mkey: 'tx_rows_m', mv: 115, arKey: 'tx_sumhead', shop: '/cart/', style: 'kbb-carttype', api: '/cart-page' },
  { id: 'cartpanel', p: 'cpp', tabs: ['desktop', 'mobile'], key: 'tabs_size', kv: 115, mkey: 'ov_text_m', mv: 12, arKey: 'sum_size', shop: '/shop/', style: 'kbb-cptype', api: '/cart-panel' },
];

async function setRange(page, attr, key, value) {
  await page.evaluate(([a, k, v]) => {
    const el = document.querySelector(`[${a}="${k}"]`);
    el.value = String(v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, [attr, key, value]);
}

async function openTab(page, s, tab) {
  await page.evaluate((id) => window.go(id), s.id);
  await page.waitForSelector(`[data-${s.p}-tab="${tab}"]`, { timeout: 15000 });
  await page.click(`[data-${s.p}-tab="${tab}"]`);
  await page.waitForFunction(() => { const f = document.querySelector('iframe[data-ktl]'); return f && f.contentDocument && f.contentDocument.body && f.contentDocument.body.children.length > 3; }, null, { timeout: 30000 });
  await page.waitForTimeout(600);
}

const liveCss = (page, lang) => page.evaluate((l) => {
  const f = document.querySelector(`iframe[data-ktl][data-ktl-lang="${l}"]`);
  const t = f && f.contentDocument && f.contentDocument.getElementById('kbb-live-css');
  return t ? t.textContent : null;
}, lang);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, userAgent: UA_D });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);

  const report = [];
  const live = {};
  for (const s of SCREENS) {
    const r = { screen: s.id };
    let requests = 0;
    const count = (req) => { if (['xhr', 'fetch', 'document'].includes(req.resourceType())) requests++; };

    await openTab(page, s, s.tabs[1]);
    page.on('request', count);
    await setRange(page, `data-${s.p}-key`, s.mkey, s.mv);
    await page.waitForTimeout(200);
    await openTab(page, s, s.tabs[0]);
    const before = requests;
    await setRange(page, `data-${s.p}-key`, s.key, s.kv);
    await page.waitForTimeout(250);
    r.requestsForTheMove = requests - before;
    await page.locator('.ktl-live').first().screenshot({ path: path.join(OUT, `admin-${s.id}-desktop-en-live.png`) });
    const en = await liveCss(page, 'en');

    await page.click(`[data-ktl-lang="ar"][data-ktl-id="${s.p}"]`);
    await page.waitForFunction(() => { const f = document.querySelector('iframe[data-ktl][data-ktl-lang="ar"]'); const d = f && f.contentDocument; return !!(d && d.body && d.body.children.length > 3); }, null, { timeout: 30000 });
    await page.waitForTimeout(400);
    const b2 = requests;
    await setRange(page, 'data-ktl-ar', s.arKey, 130);
    await page.waitForTimeout(250);
    r.requestsForTheArabicMove = requests - b2;
    await page.locator('.ktl-live').first().screenshot({ path: path.join(OUT, `admin-${s.id}-desktop-ar-live.png`) });
    const ar = await liveCss(page, 'ar');
    page.off('request', count);
    live[s.id] = { en, ar };

    await page.click(`[data-${s.p}-save]`);
    await page.waitForTimeout(1500);
    report.push(r);
  }

  // The shop prints what the previews showed, English and Arabic.
  const shop = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA_D });
  const sp = await shop.newPage();
  await sp.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
  const ids = await (await sp.request.get(BASE + '/ck-ids.json')).json();
  for (const id of ids.slice(0, 3)) {
    await sp.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
  }
  for (const s of SCREENS) {
    for (const [lang, pre] of [['en', ''], ['ar', '/ar']]) {
      const html = await (await sp.request.get(BASE + pre + s.shop)).text();
      const m = html.match(new RegExp('<style id="' + s.style + '">([\\s\\S]*?)</style>'));
      const printed = m ? m[1] : null;
      const shown = live[s.id][lang];
      report.push({ shop: pre + s.shop, lang, previewBytes: shown ? shown.length : null, shopBytes: printed ? printed.length : null, identical: shown !== null && shown === printed });
    }
    if (s.id === 'checkoutpage') {
      await sp.goto(BASE + s.shop, { waitUntil: 'networkidle' });
      await sp.screenshot({ path: path.join(OUT, 'shop-checkout-sizes-1280.png') });
    }
  }
  await shop.close();

  // Everything back to defaults, saved, so the shop is as it ships.
  report.push({ reset: await page.evaluate(async (screens) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const base = location.pathname.replace(/\/+$/, '').replace(/\/[^/]*$/, '') + '/admin-api';
    const out = [];
    for (const s of screens) {
      const body = await (await fetch(base + s.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
      const settings = {};
      body.tabs.forEach((t) => t.fields.forEach((f) => { settings[f.key] = f.value; }));
      body.tabs.filter((t) => s.tabs.indexOf(t.key) !== -1).forEach((t) => t.fields.forEach((f) => { settings[f.key] = f['default']; }));
      const r = await fetch(base + s.api, { method: 'POST', credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf },
        body: JSON.stringify({ settings, ar: {} }) });
      out.push(s.id + ' ' + r.status);
    }
    return out;
  }, SCREENS) });
  report.push({ pageErrors: errors });
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'admin-facts.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})();
