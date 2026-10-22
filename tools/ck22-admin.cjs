/* Lane CK (22 October): the three admin screens' Text sizes with the live
   preview, in Chromium, on the local preview shop.
     BASE=http://127.0.0.1:PORT node tools/ck22-admin.cjs
   For each screen: open the size tab, wait for the real page in the frames,
   move a control and measure the frame (no request is made by the move),
   switch to Arabic, move an Arabic control, Save, reload, check the values
   came back, and check the SHOP prints what the preview showed (EN and /ar).
   Then everything is put back to defaults and saved. Pictures to docs/lane-ck/. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE;
if (!BASE || !/^http:\/\/127\.0\.0\.1:\d+$/.test(BASE)) throw new Error('BASE must be http://127.0.0.1:PORT');
const OUT = path.resolve(__dirname, '../docs/lane-ck');
const UA_D = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

const SCREENS = [
  { id: 'checkoutpage', p: 'chp', tabs: ['desktop_type', 'mobile_type'], key: 'd_ov_text', mkey: 'm_ov_text', arKey: 'd_t_title',
    probe: '.sec > h2', shop: '/checkout/', prop: '--cop-d-ovt', arProp: '--cop-d-ttitle' },
  { id: 'cartpage', p: 'cps', tabs: ['type_d', 'type_m'], key: 'tx_ov_text', mkey: 'tx_ov_text_m', arKey: 'tx_sumhead',
    probe: '#cartPage aside.sum > h2', shop: '/cart/', prop: '--cgz-d-ovt', arProp: '--cgz-d-tsumh' },
  { id: 'cartpanel', p: 'cpp', tabs: ['desktop', 'mobile'], key: 'ov_text', mkey: 'ov_text_m', arKey: 'tabs_size',
    probe: '#cart .kc-tab', shop: '/', prop: '--cpz-d-ovt', arProp: '--cpz-d-ttabs' },
];

async function frameSize(page, sel) {
  return page.evaluate((s) => {
    const f = document.querySelector('iframe[data-ktl]');
    const el = f && f.contentDocument && f.contentDocument.querySelector(s);
    return el ? f.contentWindow.getComputedStyle(el).fontSize : null;
  }, sel);
}

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
  await page.waitForTimeout(700);
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, userAgent: UA_D });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1000);

  const report = [];
  for (const s of SCREENS) {
    const r = { screen: s.id };
    let liveRequests = 0;
    const count = (req) => { if (/\/live\?lang=/.test(req.url())) liveRequests++; };
    page.on('request', count);

    await openTab(page, s, s.tabs[0]);
    r.framesDesktop = await page.$$eval('iframe[data-ktl]', (f) => f.length);
    r.before = await frameSize(page, s.probe);
    const reqBefore = liveRequests;
    await setRange(page, `data-${s.p}-key`, s.key, 15);
    await page.waitForTimeout(250);
    r.after = await frameSize(page, s.probe);
    r.requestsForTheMove = liveRequests - reqBefore;
    await page.screenshot({ path: path.join(OUT, `admin-${s.id}-desktop-en.png`), fullPage: true });
    await page.locator(".ktl-live").first().screenshot({ path: path.join(OUT, `admin-${s.id}-desktop-en-live.png`) });

    await openTab(page, s, s.tabs[1]);
    r.framesMobile = await page.$$eval('iframe[data-ktl]', (f) => f.map((x) => x.getBoundingClientRect().width + 'x' + x.contentWindow.innerWidth));
    await setRange(page, `data-${s.p}-key`, s.mkey, 15);
    await page.waitForTimeout(250);
    await page.screenshot({ path: path.join(OUT, `admin-${s.id}-mobile-en.png`), fullPage: true });
    await page.locator(".ktl-live").first().screenshot({ path: path.join(OUT, `admin-${s.id}-mobile-en-live.png`) });

    await openTab(page, s, s.tabs[0]);
    await page.click(`[data-ktl-lang="ar"][data-ktl-id="${s.p}"]`);
    await page.waitForFunction(() => { const f = document.querySelector('iframe[data-ktl][data-ktl-lang="ar"]'); const d = f && f.contentDocument; return !!(d && d.documentElement && d.body && d.documentElement.getAttribute("dir") === "rtl" && d.body.children.length > 3); }, null, { timeout: 30000 });
    r.arBadgeBefore = await page.$eval(`[data-ktl-st="${s.arKey}"]`, (b) => b.textContent);
    await setRange(page, 'data-ktl-ar', s.arKey, 130);
    await page.waitForTimeout(250);
    r.arBadgeAfter = await page.$eval(`[data-ktl-st="${s.arKey}"]`, (b) => b.textContent);
    await page.screenshot({ path: path.join(OUT, `admin-${s.id}-desktop-ar.png`), fullPage: true });
    await page.locator(".ktl-live").first().screenshot({ path: path.join(OUT, `admin-${s.id}-desktop-ar-live.png`) });

    await page.click(`[data-${s.p}-save]`);
    await page.waitForTimeout(1500);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await openTab(page, s, s.tabs[0]);
    r.savedEn = await page.$eval(`[data-${s.p}-key="${s.key}"]`, (i) => i.value);
    await page.click(`[data-ktl-lang="ar"][data-ktl-id="${s.p}"]`);
    await page.waitForSelector(`[data-ktl-ar="${s.arKey}"]`);
    r.savedAr = await page.$eval(`[data-ktl-ar="${s.arKey}"]`, (i) => i.value);
    await page.waitForFunction(() => { const f = document.querySelector('iframe[data-ktl][data-ktl-lang="ar"]'); const d = f && f.contentDocument; return !!(d && d.body && d.body.children.length > 3); }, null, { timeout: 30000 });
    await page.waitForTimeout(500);
    r.previewAfterReload = await frameSize(page, s.probe);
    page.off('request', count);
    report.push(r);
  }

  // The shop prints what was saved: English and Arabic.
  const shop = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: UA_D });
  const sp = await shop.newPage();
  await sp.goto(BASE + '/', { waitUntil: 'networkidle' });
  for (const id of [26, 5, 11]) {
    await sp.evaluate(async (pid) => fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf }, body: JSON.stringify({ product_id: pid, quantity: 1 }) }), id);
  }
  for (const s of SCREENS) {
    for (const pre of ['', '/ar']) {
      await sp.goto(BASE + pre + s.shop, { waitUntil: 'networkidle' });
      const html = await sp.content();
      report.push({ shop: pre + s.shop, en: html.includes(s.prop + ':1.15'), ar: html.includes(s.arProp + ':1.3'),
        size: await sp.evaluate((sel) => { const el = document.querySelector(sel); return el ? getComputedStyle(el).fontSize : null; }, s.probe) });
    }
  }
  await shop.close();

  // Everything back to defaults, saved, so the shop is as it ships.
  // Through the screens' own endpoints: every size key on the size tabs back
  // to its default, and the Arabic map emptied.
  report.push({ reset: await page.evaluate(async (screens) => {
    const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const base = location.pathname.replace(/\/+$/, '').replace(/\/[^/]*$/, '') + '/admin-api';
    const out = [];
    for (const s of screens) {
      const path = { checkoutpage: '/checkout-page', cartpage: '/cart-page', cartpanel: '/cart-panel' }[s.id];
      const body = await (await fetch(base + path, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
      const settings = {};
      body.tabs.forEach((t) => t.fields.forEach((f) => { settings[f.key] = f.value; }));
      body.tabs.filter((t) => s.tabs.indexOf(t.key) !== -1).forEach((t) => t.fields.forEach((f) => { settings[f.key] = f['default']; }));
      const r = await fetch(base + path, { method: 'POST', credentials: 'same-origin',
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
