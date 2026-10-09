/*
 * Lane AN — the Analytics board (design B) in the console at 1280 and 390, the
 * owner app's Live tab at 390, the Orders list's Source column and the order
 * screen's Source panel; plus the measurements that go with them: page width,
 * console errors, and the live poll's behaviour (runs only while the screen is
 * open and the tab visible; a hidden tab or another screen stops it).
 *
 *   BASE=http://127.0.0.1:10461 APP=/<owner-app-path> node tools/an-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10461';
const APP = process.env.APP;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-an-shots';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const say = (s) => process.stdout.write(s + '\n');
const sleep = (ms) => new Promise((ok) => setTimeout(ok, ms));

async function hide(page, hidden) {
  await page.evaluate((h) => {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => (h ? 'hidden' : 'visible') });
    document.dispatchEvent(new Event('visibilitychange'));
  }, hidden);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: width < 700 ? IPHONE : undefined });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + JSON.stringify(m.location())); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    const live = [];
    page.on('request', (r) => { if (r.url().includes('/admin-api/site-analytics/live')) live.push(Date.now()); });

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin#site-analytics', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('site-analytics'));
    await page.waitForSelector('[data-an="strip"] .card', { timeout: 15000 });
    await page.waitForTimeout(900);
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
      active: document.querySelector('[data-an="active"]').textContent, feed: document.querySelectorAll('[data-an="feed"] li').length,
      bars: document.querySelectorAll('[data-an="bars"] rect').length, cards: document.querySelectorAll('[data-an="rest"] .card').length,
      huge: getComputedStyle(document.querySelector('.anb .huge')).fontSize }));
    say(`console ${width}: scrollWidth ${m.sw} / clientWidth ${m.cw}; active ${m.active}; feed rows ${m.feed}; bars ${m.bars}; cards ${m.cards}; count font ${m.huge}`);
    // The console scrolls inside its own column, so a full-page shot is a tall viewport.
    const tall = await page.evaluate(() => Math.ceil(document.querySelector('[data-an]').getBoundingClientRect().height + 200));
    await page.setViewportSize({ width, height: tall });
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/admin-analytics-${width}.png` });
    await page.setViewportSize({ width, height: 900 });
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/admin-analytics-live-${width}.png` });

    if (width === 1280) {
      // The window picker: 25 minutes, one request, remembered.
      const before = live.length;
      await page.click('[data-an-win="25"]');
      await page.waitForTimeout(700);
      const w25 = await page.evaluate(() => [document.querySelector('[data-an="active"]').textContent, localStorage.getItem('kbb_an_win')]);
      say(`window 25: active ${w25[0]}, stored ${w25[1]}, requests for the switch ${live.length - before}`);
      await page.screenshot({ path: `${OUT}/admin-analytics-window25-1280.png` });
      await page.click('[data-an-win="10"]');
      await page.waitForTimeout(500);

      // Sleep: hidden tab -> no request; visible -> one catch-up; another screen -> none.
      live.length = 0;
      await hide(page, true);
      await sleep(16500);
      const whileHidden = live.length;
      await hide(page, false);
      await sleep(800);
      const onWake = live.length;
      await sleep(15500);
      const afterTick = live.length;
      await page.evaluate(() => window.go('dash'));
      await sleep(800);
      const leftAt = live.length;
      await sleep(16000);
      say(`poll: hidden 16.5 s -> ${whileHidden} requests; on return -> ${onWake - whileHidden} (catch-up); next 15.5 s -> ${afterTick - onWake}; after leaving the screen 16 s -> ${live.length - leftAt}`);

      // Orders list with the Source column, and an order's Source panel.
      await page.evaluate(() => window.go('orders'));
      await page.waitForSelector('table', { timeout: 15000 });
      await page.waitForTimeout(900);
      await page.screenshot({ path: `${OUT}/admin-orders-source-1280.png` });
      const id = process.env.ORDER || await page.evaluate(async () => {
        const r = await fetch('/admin-api/orders-list?source=instagram_ads', { headers: { Accept: 'application/json' } });
        const j = await r.json(); return j.orders[0] && j.orders[0].id;
      });
      if (id) {
        await page.goto(BASE + '/admin?o=' + id + '#orders/' + id, { waitUntil: 'networkidle' });
        await page.waitForTimeout(1500);
        await page.evaluate(() => { const c = document.getElementById('odAttr'); if (c) c.scrollIntoView(); });
        await page.waitForTimeout(300);
        await page.screenshot({ path: `${OUT}/admin-order-source-panel-1280.png` });
      }
    }
    say(`console ${width}: errors ${errors.length ? errors.join(' | ') : 'none'}`);
    await ctx.close();
  }

  if (APP) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE });
    await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const live = [];
    page.on('request', (r) => { if (r.url().includes('/api/analytics/live')) live.push(Date.now()); });
    await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-enrol]');
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=pin]', '482615');
    await page.fill('input[name=device_name]', 'an-390');
    await page.click('[data-enrol] button[type=submit]');
    await page.waitForTimeout(2500);
    await page.goto(BASE + APP + '/#/analytics');
    await page.waitForSelector('.an-live', { timeout: 15000 });
    await page.waitForTimeout(1500);
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, tabs: [...document.querySelectorAll('.nav [data-tab]')].map((t) => t.textContent.trim()) }));
    say(`owner app 390: scrollWidth ${m.sw}; tabs ${m.tabs.join(' / ')}`);
    await page.screenshot({ path: `${OUT}/owner-app-analytics-390.png` });
    const tallA = await page.evaluate(() => Math.ceil(document.querySelector('.view').scrollHeight + 160));
    await page.setViewportSize({ width: 390, height: tallA });
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/owner-app-analytics-390-full.png` });
    await page.setViewportSize({ width: 390, height: 844 });
    // The by-source cards further down: scroll the app's own scroller.
    await page.evaluate(() => { const s = document.querySelector('.an-strip'); if (s) s.scrollIntoView(); });
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/owner-app-analytics-sources-390.png` });
    live.length = 0;
    await page.goto(BASE + APP + '/#/orders');
    await page.waitForTimeout(1500);
    await page.screenshot({ path: `${OUT}/owner-app-orders-source-390.png` });
    await sleep(16000);
    say(`owner app: live requests in 16 s after leaving Analytics: ${live.length}; errors ${errors.length ? errors.join(' | ') : 'none'}`);
    await ctx.close();
  }
  await browser.close();
})();
