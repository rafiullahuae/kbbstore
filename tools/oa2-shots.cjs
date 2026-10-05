/*
 * LANE OA2 — the owner app's refresh behaviour, photographed and measured.
 *
 *   KBB_BASE=http://127.0.0.1:10260 KBB_APP=/<secret> node tools/oa2-shots.cjs
 *
 * Against tools/mac-preview.sh (seeded by tools/mac-seed.php). Time is moved
 * with a Date.now() offset the harness injects (window.__skew), the app's own
 * timers untouched; API latency is added by the harness so the grey bars stay
 * on screen long enough to photograph. The MEASURING here is the harness
 * measuring the page — the app itself measures nothing.
 * Output: docs/oa2-shots/*.jpg and docs/oa2-shots/numbers.txt.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10260';
const APP = process.env.KBB_APP;
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OUT = path.join(__dirname, '..', 'docs', 'oa2-shots');
fs.mkdirSync(OUT, { recursive: true });

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
const ANDROID_TAB = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
const DEVICES = {
  'phone-390': { w: 390, h: 844, ua: IPHONE, dpr: 2, ios: true },
  'tablet-820': { w: 820, h: 1180, ua: ANDROID_TAB, dpr: 1 },
};

const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));

async function newPage(browser, d, extra) {
  const ctx = await browser.newContext(Object.assign({ viewport: { width: d.w, height: d.h }, deviceScaleFactor: d.dpr, isMobile: d.w < 700, hasTouch: true, userAgent: d.ua }, extra || {}));
  await ctx.addInitScript(() => {
    const real = Date.now.bind(Date);
    Date.now = () => real() + (window.__skew || 0);
    window.__skSeen = 0;
    new MutationObserver((m) => { if (m.some((x) => x.target.hasAttribute && x.target.hasAttribute('data-sk'))) window.__skSeen++; })
      .observe(document, { subtree: true, attributes: true, attributeFilter: ['data-sk'] });
  });
  if (d.ios && d.w < 700) {
    await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
  }
  const page = await ctx.newPage();
  const net = { delay: 0, fail: null, log: [], open: 0 };
  page.on('request', (q) => { if (q.url().includes('/api/')) net.open++; });
  const done = (q) => { if (q.url().includes('/api/')) net.open--; };
  page.on('requestfinished', done);
  page.on('requestfailed', done);
  page.oaNet = net;
  page.on('pageerror', (e) => say('  PAGE ERROR: ' + e.message));
  await page.route('**/api/**', async (r) => {
    const u = r.request().url(), h = r.request().headers();
    const ep = u.split('/api/')[1].split('?')[0].replace(/\/\d+.*/, '/:id');
    net.log.push({ t: Date.now(), ep, method: r.request().method(), passive: h['x-oa-passive'] === '1', key: !!h['x-oa-csrf'] });
    if (net.fail && net.fail.test(u)) { await r.abort('failed'); return; }
    if (net.delay && r.request().method() === 'GET' && !/\/api\/(changes|state)/.test(u)) await sleep(net.delay);
    await r.continue().catch(() => {});
  });
  return { ctx, page, net };
}

const shot = async (page, name, clip) => page.screenshot({ path: path.join(OUT, name + '.jpg'), type: 'jpeg', quality: 82, clip });
const idle = async (page) => {
  await page.waitForFunction(() => !document.querySelector('[data-sk]'), null, { timeout: 15000 });
  for (let i = 0; i < 100 && page.oaNet.open > 0; i++) await sleep(100);   // every API request answered
  await page.waitForTimeout(300);
};
const comeBack = (page, ms) => page.evaluate((add) => { window.__skew = (window.__skew || 0) + add; window.__skSeen = 0; document.dispatchEvent(new Event('visibilitychange')); }, ms);
const go = async (page, hash) => { await page.evaluate((h) => { location.hash = h; }, hash); await idle(page); };

/* Heights of what the body holds — the bars, then the content that replaced them. */
const shape = (page, sel) => page.evaluate((s) => Array.from(document.querySelectorAll(s)).slice(0, 4).map((e) => Math.round(e.getBoundingClientRect().height * 10) / 10), sel);
const sw = (page) => page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
const counts = (log) => log.reduce((a, x) => { a[x.ep] = (a[x.ep] || 0) + 1; return a; }, {});

async function bars(page, net, name, hash, rowSel) {
  net.delay = 0;
  if (hash) await go(page, hash);
  const real = await shape(page, rowSel);
  net.delay = 1800;
  net.log.length = 0;
  await comeBack(page, 40 * 60000);
  await page.waitForSelector('[data-sk]', { timeout: 3000 });
  await page.waitForTimeout(450);
  const sk = await shape(page, rowSel);
  const n = await page.evaluate(() => document.querySelectorAll('.sk').length);
  await shot(page, name);
  const [w, vw] = await sw(page);
  await idle(page);
  net.delay = 0;
  say('  ' + name + ': ' + n + ' grey bars; ' + rowSel + ' heights bars ' + JSON.stringify(sk) + ' vs data ' + JSON.stringify(real) + '; scrollWidth ' + w + '/' + vw
    + '; requests ' + JSON.stringify(counts(net.log)) + ' [' + net.log.map((x) => x.ep).join(' ') + '], passive ' + net.log.filter((x) => x.passive).length);
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  for (const [dev, d] of Object.entries(DEVICES)) {
    say('\n== ' + dev + ' (' + d.w + 'x' + d.h + ', ' + (d.ios ? 'iPhone Safari UA' : 'Android tablet Chrome UA') + ')');
    const { ctx, page, net } = await newPage(browser, d);
    await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-enrol]');
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=pin]', '482615');
    await page.fill('input[name=device_name]', 'oa2-' + dev);

    // 1. First open after sign-in: nothing held, so grey bars, every section at once.
    net.delay = 1800;
    net.log.length = 0;
    await page.click('[data-enrol] button[type=submit]');
    await page.waitForSelector('[data-sk]', { timeout: 5000 });
    await page.waitForTimeout(450);
    const skHero = await shape(page, '.body > *');
    await shot(page, dev + '--01-first-open-bars');
    const t0 = net.log.length ? net.log[0].t : 0;
    const spread = net.log.filter((x) => x.method === 'GET' && x.ep !== 'enrol').map((x) => x.ep + '@' + (x.t - t0) + 'ms');
    await idle(page);
    net.delay = 0;
    // The one-time "Add to home screen" sheet opens 600ms after the first figures land.
    await page.waitForSelector('.sheet.a2.open', { timeout: 2500 }).catch(() => {});
    await page.keyboard.press('Escape');
    await page.waitForTimeout(450);
    const realHero = await shape(page, '.body > *');
    await shot(page, dev + '--02-dashboard-landed');
    say('  01 first open: requests fired ' + spread.join(', ') + ' (all within ' + Math.max(...net.log.map((x) => x.t - t0)) + 'ms: parallel)');
    say('     dashboard .body children heights, bars ' + JSON.stringify(skHero) + ' vs data ' + JSON.stringify(realHero));
    say('     X-OA-CSRF on every GET but state: ' + net.log.filter((x) => x.method === 'GET').every((x) => x.key === (x.ep !== 'state')));

    // 2. Back after one minute: no bars, values swap in place, passive.
    net.delay = 1800;
    net.log.length = 0;
    await comeBack(page, 60000);
    await page.waitForTimeout(150);
    const heroAt150 = await page.$('.card.hero');
    await shot(page, dev + '--03-reopen-after-1-min-silent');
    await page.waitForTimeout(2600);
    await idle(page);
    const seen1 = await page.evaluate(() => window.__skSeen);
    say('  03 reopen after 1 min: real hero on screen at 150ms: ' + !!heroAt150 + '; grey bars ever shown: ' + (seen1 > 0) + '; requests ' + JSON.stringify(counts(net.log))
      + '; every one X-OA-Passive: ' + net.log.every((x) => x.passive));

    // 3. Back after 40 minutes: bars on this screen, everything in parallel, not passive.
    await bars(page, net, dev + '--04-reopen-after-40-min-bars', '', '.body > *');

    // 4. Sync now: idle, spinning, taps mid-sync, failure.
    const btn = '.view [data-sync]';
    const geo = await page.evaluate((s) => {
      const b = document.querySelector(s), r = b.getBoundingClientRect();
      const hitL = document.elementFromPoint(r.left - 1.5, r.top + r.height / 2), hitT = document.elementFromPoint(r.left + r.width / 2, r.top - 1.5);
      return { w: r.width, h: r.height, x: Math.round(r.left), y: Math.round(r.top), label: b.getAttribute('aria-label'), edgeHits: !!(hitL && hitL.closest('[data-sync]')) && !!(hitT && hitT.closest('[data-sync]')) };
    }, btn);
    const clip = { x: 0, y: 0, width: d.w, height: d.w < 700 ? 130 : 110 };
    await shot(page, dev + '--05-sync-icon-idle', clip);
    net.delay = 2200;
    net.log.length = 0;
    await page.click(btn);
    await page.waitForTimeout(120);
    await page.click(btn);
    await page.click(btn);
    await page.waitForTimeout(500);
    const spin = await page.evaluate((s) => { const b = document.querySelector(s); return { on: b.classList.contains('on'), anim: getComputedStyle(b.querySelector('.i')).animationName, busy: b.getAttribute('aria-busy'), overlay: !!document.querySelector('.rf, .scrim.show') }; }, btn);
    await shot(page, dev + '--06-sync-icon-spinning', clip);
    await page.waitForTimeout(2600);
    await idle(page);
    const after = await page.evaluate((s) => document.querySelector(s).classList.contains('on'), btn);
    say('  05/06 Sync now: ' + geo.w + 'x' + geo.h + 'px icon at (' + geo.x + ',' + geo.y + '), label "' + geo.label + '", 2px outside its edge still hits it (44px target): ' + geo.edgeHits);
    say('     3 taps in 0.6s -> requests ' + JSON.stringify(counts(net.log)) + '; spinning ' + spin.on + ' (' + spin.anim + ', aria-busy ' + spin.busy + '), overlay ' + spin.overlay + '; stopped after: ' + !after
      + '; passive ' + net.log.filter((x) => x.passive).length);

    net.fail = /\/api\/dashboard/;
    await page.click(btn);
    await page.waitForSelector('.toast.bad', { timeout: 5000 });
    const kept = await page.evaluate(() => ({ hero: !!document.querySelector('.card.hero'), toast: document.querySelector('.toast').textContent }));
    await shot(page, dev + '--07-sync-failed-keeps-data');
    net.fail = null;
    say('  07 Sync now with /api/dashboard down: toast "' + kept.toast + '"; dashboard figures kept: ' + kept.hero);
    await page.waitForTimeout(2700);

    // 5. Bars in each screen's shape (40 minutes away on that screen).
    if (d.w < 768) {
      await bars(page, net, dev + '--08-orders-bars', '#/orders', '.list .row');
      const oid = await page.$$eval('.row.ord', (rs) => rs.map((r) => r.getAttribute('data-o')));
      await bars(page, net, dev + '--09-order-bars', '#/orders/' + oid[0], '.body > *');
    } else {
      await bars(page, net, dev + '--08-orders-split-bars', '#/orders', '.pane-l .list .row');
    }
    await bars(page, net, dev + '--10-products-bars', '#/products', '.list .row');
    const pid = await page.$$eval('.list a.row', (rs) => rs.map((r) => r.getAttribute('href')));
    await bars(page, net, dev + '--11-product-bars', pid[1] || pid[0], '.body > *');
    await bars(page, net, dev + '--12-customers-bars', '#/customers', '.list .row');
    const cid = await page.$$eval('.list a.row', (rs) => rs.map((r) => r.getAttribute('href')));
    await bars(page, net, dev + '--13-customer-bars', cid[0], '.body > *');
    await bars(page, net, dev + '--14-notifications-bars', '#/notifications', '.list .row');

    // 6. Tab to a screen already held and fresh: drawn at once, no bars.
    await go(page, '#/orders');
    await page.evaluate(() => { window.__skSeen = 0; location.hash = '#/products'; });
    await page.waitForTimeout(60);
    const instant = await page.evaluate(() => ({ rows: document.querySelectorAll('.list a.row').length, sk: window.__skSeen }));
    await idle(page);
    say('  tab to Products (held, fresh): ' + instant.rows + ' rows on screen within 60ms, bars shown ' + instant.sk + ' times');
    await ctx.close();
  }

  // 7. The header at every width: no layout shift, nothing scrolls sideways.
  say('\n== header with "Sync now", per width (top header height, title box, sync icon, document scrollWidth)');
  const { ctx, page } = await newPage(browser, { w: 360, h: 800, ua: ANDROID, dpr: 1 });
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.click('[data-enrol] button[type=submit]');
  await idle(page);
  await page.waitForSelector('.sheet.a2.open', { timeout: 2500 }).catch(() => {});
  await page.keyboard.press('Escape');
  await page.waitForTimeout(450);
  for (const w of [360, 390, 412, 430, 768, 820, 1180]) {
    await page.setViewportSize({ width: w, height: w < 700 ? 800 : 1000 });
    const row = [];
    for (const h of ['', '#/orders', '#/products', '#/customers', '#/notifications', '#/more']) {
      await go(page, h || '#/');
      const m = await page.evaluate(() => {
        const hd = document.querySelector('.view .top:not(.selhdr), .view .lt'), b = document.querySelector('.view [data-sync]');
        const t = hd.querySelector('h3, h2'), r = b.getBoundingClientRect();
        return { h: Math.round(hd.getBoundingClientRect().height), title: Math.round(t.getBoundingClientRect().width), cut: t.scrollWidth > t.clientWidth + 1, sync: Math.round(r.width) + '@' + Math.round(r.right), sw: document.documentElement.scrollWidth, n: document.querySelectorAll('[data-sync]').length };
      });
      if (w === 360 && h === '#/notifications') await shot(page, 'phone-360--15-notifications-header', { x: 0, y: 0, width: 360, height: 140 });
      row.push((h || '#/') + ' h' + m.h + ' title' + m.title + (m.cut ? '(ellipsis)' : '') + ' sync' + m.sync + ' x' + m.n + ' sw' + m.sw);
    }
    say('  ' + w + ': ' + row.join(' | '));
  }
  await ctx.close();

  // 8. Reduced motion: bars and icon hold still.
  const rm = await newPage(browser, DEVICES['phone-390'], { reducedMotion: 'reduce' });
  await rm.page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await rm.page.fill('input[name=email]', 'owner@example.com');
  await rm.page.fill('input[name=pin]', '482615');
  rm.net.delay = 1500;
  await rm.page.click('[data-enrol] button[type=submit]');
  await rm.page.waitForSelector('[data-sk]');
  const rmSk = await rm.page.evaluate(() => getComputedStyle(document.querySelector('.sk'), '::after').animationName);
  await idle(rm.page);
  await rm.page.waitForSelector('.sheet.a2.open', { timeout: 2500 }).catch(() => {});
  await rm.page.keyboard.press('Escape');
  await rm.page.waitForTimeout(450);
  rm.net.delay = 1500;
  await rm.page.click('.view [data-sync]');
  await rm.page.waitForTimeout(300);
  const rmSpin = await rm.page.evaluate(() => { const b = document.querySelector('.view [data-sync]'); return getComputedStyle(b.querySelector('.i')).animationName + ', colour ' + getComputedStyle(b).color + ', on ' + b.classList.contains('on'); });
  say('\n== prefers-reduced-motion: shimmer animation ' + rmSk + '; spinning icon animation ' + rmSpin);
  await rm.ctx.close();

  await browser.close();
  fs.writeFileSync(path.join(OUT, 'numbers.txt'), 'Lane OA2 — owner app refresh, measured by tools/oa2-shots.cjs (' + new Date().toISOString() + ')\n' + lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
