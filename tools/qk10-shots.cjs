/*
 * Lane QK10 -- the console sidebar with Cart Tracking third at the top (1280
 * and 390), and the owner app's Cart tracking screen at 390 (More, the list,
 * one cart's sheet), with the numbers: scrollWidth, console errors, the row a
 * real click lands on, and how many /api/carts requests opening the screen makes.
 *
 *   sh tools/qk10-preview.sh            # prints the port and the app path
 *   BASE=http://127.0.0.1:11610 APP=/<owner-app-path> node tools/qk10-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:11610';
const APP = process.env.APP;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-qk10-shots';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const say = (s) => process.stdout.write(s + '\n');

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: width < 700 ? IPHONE : undefined });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error' && !/status of 4\d\d/.test(m.text())) errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.waitForTimeout(800);
    if (width < 700) { await page.click('.menubtn'); await page.waitForTimeout(500); }
    const m = await page.evaluate(() => {
      const top = [...document.querySelectorAll('#nav > .nav-item')].map((b) => b.dataset.go);
      const b = document.querySelector('#nav [data-go="carttracking"]');
      const r = b.getBoundingClientRect();
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, top,
        n: document.querySelectorAll('#nav [data-go="carttracking"]').length, inGroup: !!b.closest('.nav-group'),
        box: [Math.round(r.top), Math.round(r.height)], hits: !!hit && hit.closest('[data-go]') === b };
    });
    await page.screenshot({ path: `${OUT}/admin-sidebar-${width}.png` });
    await page.click('#nav [data-go="carttracking"]');
    await page.waitForTimeout(1500);
    const after = await page.evaluate(() => ({ crumb: (document.querySelector('#crumb') || {}).textContent, title: (document.querySelector('#ptitle') || {}).textContent,
      on: (document.querySelector('#nav .nav-item.on') || {}).dataset?.go, hash: location.hash, sw: document.documentElement.scrollWidth }));
    if (width >= 700) await page.screenshot({ path: `${OUT}/admin-cart-tracking-open-${width}.png` });
    say(`console ${width}: scrollWidth ${m.sw}/${m.cw}; top rows ${m.top.join(', ')}; carttracking rows ${m.n}; inside a group ${m.inGroup}; box top/height ${m.box}; elementFromPoint hits it ${m.hits}`
      + `; after click: crumb "${after.crumb}", title "${after.title}", marked ${after.on}, scrollWidth ${after.sw}; errors ${errors.length ? errors.join(' | ') : 'none'}`);
    await ctx.close();
  }

  if (APP) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE });
    await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    const carts = [];
    page.on('request', (r) => { if (r.url().includes('/api/carts')) carts.push(r.url().split('/api/')[1]); });
    await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-enrol]');
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=pin]', '482615');
    await page.fill('input[name=device_name]', 'qk10-390');
    await page.click('[data-enrol] button[type=submit]');
    await page.waitForTimeout(2500);
    await page.goto(BASE + APP + '/#/more');
    await page.waitForSelector('a[href="#/carts"]', { timeout: 15000 });
    await page.waitForTimeout(600);
    const more = await page.evaluate(() => ({ first: document.querySelector('.plain-list .row b').textContent, tabs: [...document.querySelectorAll('.nav [data-tab]')].map((t) => t.textContent.trim()) }));
    await page.screenshot({ path: `${OUT}/owner-app-more-390.png` });
    carts.length = 0;
    await page.click('a[href="#/carts"]');
    await page.waitForSelector('.ct-row', { timeout: 15000 });
    await page.waitForTimeout(1500);
    const list = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, rows: document.querySelectorAll('.ct-row').length,
      sub: document.querySelector('[data-sub]').textContent, tiles: [...document.querySelectorAll('[data-sum] .card')].map((c) => c.textContent.trim()),
      more: [...document.querySelectorAll('.nav [data-tab]')].filter((t) => t.classList.contains('on')).map((t) => t.textContent.trim()) }));
    const opened = carts.length;
    await page.screenshot({ path: `${OUT}/owner-app-carts-390.png` });
    const tall = await page.evaluate(() => Math.ceil(document.querySelector('.view').scrollHeight + 160));
    await page.setViewportSize({ width: 390, height: tall });
    await page.waitForTimeout(400);
    await page.screenshot({ path: `${OUT}/owner-app-carts-390-full.png` });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.click('[data-ctb="no"]');
    await page.waitForTimeout(1200);
    const filtered = await page.evaluate(() => document.querySelectorAll('.ct-row').length);
    await page.click('[data-ctb=""]');
    await page.waitForTimeout(1200);
    const bought = await page.$('.ct-row:has(.pill)');
    await (bought || page.locator('.ct-row').first()).click();
    await page.waitForSelector('.sheet.open', { timeout: 5000 });
    await page.waitForTimeout(600);
    const before = carts.length;
    await page.screenshot({ path: `${OUT}/owner-app-cart-sheet-390.png` });
    const sheetReq = carts.length - before;
    await page.waitForTimeout(30000);   // nothing polls: no /api/carts request in 30 s
    say(`owner app 390: tabs ${more.tabs.join(' / ')}; More's first row "${more.first}"; opening the screen made ${opened} /api/carts request(s) (${carts.slice(0, opened).join(', ')}); `
      + `rows ${list.rows}; sub "${list.sub}"; tiles ${list.tiles.join(' | ')}; tab marked ${list.more}; scrollWidth ${list.sw}; not-bought filter rows ${filtered}; `
      + `opening a cart made ${sheetReq} request(s); requests in 30 s idle after: ${carts.length - before}; errors ${errors.length ? errors.join(' | ') : 'none'}`);
    await ctx.close();
  }
  await browser.close();
})();
