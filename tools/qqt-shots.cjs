/*
 * Quiqup P3 -- the customer delivery timeline, photographed at 1280 and 390,
 * EN and AR, against tools/qqt-preview.sh (fake Quiqup, nothing leaves the
 * machine). Output: docs/lane-qq-shots/timeline-*.png and
 * timeline-MEASUREMENTS.json (scrollWidth vs width, console errors, requests,
 * the live-view link answering a real click, the refresh swapping the list).
 *
 *   NODE_PATH=/home/user/kbbstore/node_modules QQ_BASE=http://127.0.0.1:8961 \
 *     QQT_DB=<preview dir>/preview.sqlite node tools/qqt-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const BASE = process.env.QQ_BASE || 'http://127.0.0.1:8961';
const OUT = process.env.QQ_OUT || path.join(__dirname, '..', 'docs', 'lane-qq-shots');
const EXE = process.env.QQ_CHROME || '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome';
const APP = path.join(__dirname, '..');
const PDIR = path.join(APP, 'storage/framework/testing/lane-qqt-preview');
fs.mkdirSync(OUT, { recursive: true });

const M = {};
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const ORDERS = { moving: 'KBB-58101', delivered: 'KBB-58102', missed: 'KBB-58103' };

function artisan(code) {
  return execFileSync('php', [path.join(APP, 'artisan'), 'tinker', '--execute=' + code], {
    env: Object.assign({}, process.env, {
      APP_ENV: 'local', DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(PDIR, 'preview.sqlite'), CACHE_STORE: 'file',
      APP_CONFIG_CACHE: path.join(PDIR, 'compiled/config.php'), APP_ROUTES_CACHE: path.join(PDIR, 'compiled/routes-none.php'),
    }),
    stdio: ['ignore', 'pipe', 'pipe'],
  }).toString();
}

async function measure(page, name, w) {
  const m = await page.evaluate(async () => {
    const tl = document.querySelector('[data-kbb-tl]');
    const link = document.querySelector('.kdt-live');
    let hit = null;
    if (link) {
      link.scrollIntoView({ block: 'center', behavior: 'instant' });
      await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
      const r = link.getBoundingClientRect();
      const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      hit = !!el && (el === link || link.contains(el));
    }
    return {
      scrollWidth: document.documentElement.scrollWidth,
      dir: document.documentElement.getAttribute('dir'),
      dotSide: tl && tl.querySelector('.kdt-dot') ? (tl.querySelector('.kdt-dot').getBoundingClientRect().left < tl.getBoundingClientRect().left + tl.getBoundingClientRect().width / 2 ? 'left' : 'right') : null,
      steps: tl ? [...tl.querySelectorAll('.kdt-s')].map((li) => li.className.replace('kdt-s ', '') + ':' + li.querySelector('b').textContent) : null,
      live: tl ? tl.getAttribute('data-live') !== null : null,
      scripts: document.querySelectorAll('script').length,
      liveLinkHit: hit,
      fontStep: tl ? getComputedStyle(tl.querySelector('.kdt-t')).fontSize : null,
    };
  });
  M[name + '-' + w] = Object.assign({ width: w }, m);
  return m;
}

async function shot(page, name, w) {
  await wait(200);
  const tl = await page.$('[data-kbb-tl]');
  if (tl) await tl.scrollIntoViewIfNeeded();
  await measure(page, name, w);
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
  await wait(150);
  await page.screenshot({ path: path.join(OUT, 'timeline-' + name + '-' + w + '.png'), fullPage: true });
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  for (const w of (process.env.QQ_WIDTHS || '1280,390').split(',').map(Number)) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    let requests = 0;
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('request', () => { requests++; });
    page.on('requestfinished', async (rq) => { const r = await rq.response(); if (r && r.status() >= 400) errors.push(r.status() + ' ' + rq.url()); });

    // Track page (guest), each state, EN and AR.
    for (const lang of ['en', 'ar']) {
      for (const [k, num] of Object.entries(ORDERS)) {
        requests = 0;
        const pre = lang === 'ar' ? '/ar' : '';
        await page.goto(BASE + pre + '/track-my-order/?order=' + num + '&email=mariam%40preview.test', { waitUntil: 'networkidle' });
        await shot(page, 'track-' + k + '-' + lang, w);
        M['track-' + k + '-' + lang + '-' + w].requests = requests;
      }
    }
    // A shopper with no shipment: today's page, no timeline, no script.
    await page.goto(BASE + '/track-my-order/?order=KBB-58104&email=mariam%40preview.test', { waitUntil: 'networkidle' });
    await shot(page, 'track-noshipment-en', w);

    // Signed in: the order list and the order pages.
    await page.goto(BASE + '/my-account/', { waitUntil: 'networkidle' });
    await page.fill('form[action$="/my-account/login"] input[name=email]', 'mariam@preview.test');
    await page.fill('form[action$="/my-account/login"] input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('form[action$="/my-account/login"] button[type=submit]')]);
    for (const lang of ['en', 'ar']) {
      const pre = lang === 'ar' ? '/ar' : '';
      await page.goto(BASE + pre + '/my-account/orders/', { waitUntil: 'networkidle' });
      await shot(page, 'account-list-' + lang, w);
      const hrefs = await page.evaluate((o) => { const out = {}; for (const [k, n] of Object.entries(o)) { const a = [...document.querySelectorAll('a.kac-row')].find((x) => x.textContent.includes(n)); out[k] = a ? new URL(a.href).pathname : null; } return out; }, ORDERS);
      for (const k of Object.keys(ORDERS)) {
        await page.goto(BASE + hrefs[k], { waitUntil: 'networkidle' });
        await shot(page, 'account-' + k + '-' + lang, w);
      }
    }

    // The live view link answers a real click (new tab to Quiqup's page).
    await page.goto(BASE + '/track-my-order/?order=' + ORDERS.moving + '&email=mariam%40preview.test', { waitUntil: 'networkidle' });
    const link = await page.$('.kdt-live');
    if (link) {
      await ctx.route('https://track-parcel.quiqup.com/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: 'quiqup' }));
      const [popup] = await Promise.all([ctx.waitForEvent('page'), link.click()]);
      M['live-link-click-' + w] = { opened: popup.url() };
      await popup.close();
    }
    M['errors-' + w] = errors;
    await ctx.close();
  }

  // THE "MOVING" REFRESH: the page is open on "Out for delivery"; Quiqup
  // reports delivered; the shopper comes back to the tab -> one request, the
  // list swaps to Delivered with "Up to date", and the script stops.
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const calls = [];
    page.on('request', (r) => { if (r.url().includes('/live')) calls.push(r.url().split('?')[0]); });
    page.on('response', async (r) => { if (r.url().includes('/live')) calls.push(r.status()); });
    await page.goto(BASE + '/track-my-order/?order=' + ORDERS.moving + '&email=mariam%40preview.test', { waitUntil: 'networkidle' });
    await shot(page, 'refresh-before', 390);
    // Unchanged: a return to the tab answers 304 and swaps nothing.
    await page.evaluate(() => { const t = Date.now(); Date.now = () => t + 61000; document.dispatchEvent(new Event('visibilitychange')); });
    await wait(800);
    artisan("$s=App\\Models\\Shipment::where('order_id',App\\Models\\Order::where('order_number','KBB-58101')->value('id'))->first();app(App\\Services\\Quiqup\\ShipmentSync::class)->observeAndApply($s,App\\Services\\Quiqup\\ShipmentSync::observe(['state'=>'delivery_complete','state_updated_at'=>now()->toIso8601ZuluString()]),'webhook');echo 'ok';");
    await page.evaluate(() => { const t = Date.now(); Date.now = () => t + 61000; document.dispatchEvent(new Event('visibilitychange')); });
    await wait(1200);
    await shot(page, 'refresh-after', 390);
    M['refresh-calls'] = calls;
    await ctx.close();
  }

  fs.writeFileSync(path.join(OUT, 'timeline-MEASUREMENTS.json'), JSON.stringify(M, null, 2));
  await browser.close();
  console.log(JSON.stringify(M['refresh-calls']), JSON.stringify(M['errors-390']), JSON.stringify(M['errors-1280']));
})().catch((e) => { console.error(e); process.exit(1); });
