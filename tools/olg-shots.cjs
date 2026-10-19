/*
 * Lane OLG -- the order's language (EN / AR) on Store -> Orders, the admin
 * order screen and the owner app, at 1280 and 390, with the numbers that prove
 * nothing else moved: the header row, every column's width, scrollWidth, the
 * owner app's row heights, console errors and failed requests.
 *
 *   sh tools/olg-preview.sh            # prints the port and the pid
 *   BASE=http://127.0.0.1:<port> APP=/<owner-app-path> OUT=docs/lane-olg/after node tools/olg-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE;
const APP = process.env.APP;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-olg';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };

function watch(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console ' + m.text()); });
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
  return errors;
}

async function tallShot(page, width, path) {
  const h = await page.evaluate(() => Math.ceil(document.querySelector('#content .wrap').getBoundingClientRect().bottom + 40));
  await page.setViewportSize({ width, height: Math.max(900, Math.min(h, 3000)) });
  await page.waitForTimeout(300);
  await page.screenshot({ path });
  await page.setViewportSize({ width, height: 900 });
}

async function admin(browser, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: width < 700 ? IPHONE : undefined, deviceScaleFactor: width < 700 ? 2 : 1 });
  const page = await ctx.newPage();
  const errors = watch(page);
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin#orders', { waitUntil: 'networkidle' });
  await page.evaluate(() => window.go('orders'));
  await page.waitForSelector('#content .odlscroll table', { timeout: 20000 });
  await page.waitForTimeout(500);

  const m = await page.evaluate(() => {
    const ths = [...document.querySelectorAll('#content .odlscroll thead th')];
    const tr = document.querySelector('#content tr[data-olrow]');
    return {
      sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
      table: Math.round(document.querySelector('#content .odlscroll table').getBoundingClientRect().width),
      heads: ths.map((t) => t.textContent.trim() + ':' + Math.round(t.getBoundingClientRect().width)),
      rowH: tr ? Math.round(tr.getBoundingClientRect().height) : null,
      first: [...document.querySelectorAll('#content tr[data-olrow]')].slice(0, 4).map((r) => [...r.querySelectorAll('td')].map((td) => td.textContent.replace(/\s+/g, ' ').trim()).slice(1, 5).join(' | ')),
    };
  });
  say(`admin list ${width}: scrollWidth ${m.sw} / clientWidth ${m.cw}; table ${m.table}px; first row ${m.rowH}px`);
  say(`admin list ${width}: headers ${JSON.stringify(m.heads)}`);
  say(`admin list ${width}: rows ${JSON.stringify(m.first)}`);
  await tallShot(page, width, `${OUT}/admin-orders-${width}.png`);

  // The order screen, newest first: the AR one, then the EN one.
  for (const [nth, tag] of [[0, 'ar'], [1, 'en']]) {
    await page.evaluate(() => window.go('orders'));
    await page.waitForSelector('#content tr[data-olrow] [data-olview]', { timeout: 20000 });
    await page.locator('#content tr[data-olrow] [data-olview]').nth(nth).click();
    await page.waitForSelector('#content .odgrid', { timeout: 20000 });
    await page.waitForTimeout(400);
    const d = await page.evaluate(() => ({
      sw: document.documentElement.scrollWidth,
      head: [...document.querySelectorAll('#content .wrap > p, #content .wrap > .page-head')].slice(0, 4).map((p) => p.textContent.replace(/\s+/g, ' ').trim()),
      grid: Math.round(document.querySelector('#content .odgrid').getBoundingClientRect().top),
    }));
    say(`admin order ${tag} ${width}: scrollWidth ${d.sw}; grid top ${d.grid}px; top lines ${JSON.stringify(d.head)}`);
    await page.setViewportSize({ width, height: 900 });
    await page.screenshot({ path: `${OUT}/admin-order-${tag}-${width}.png` });
  }
  say(`admin ${width}: errors ${JSON.stringify(errors)}`);
  await ctx.close();
}

async function ownerApp(browser, width) {
  const mobile = width < 700;
  const ctx = await browser.newContext({ viewport: { width, height: mobile ? 844 : 900 }, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, hasTouch: mobile, userAgent: mobile ? IPHONE : undefined });
  await ctx.addInitScript(() => { try { localStorage.setItem('oa.a2', '1'); } catch (e) {} });
  await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
  const page = await ctx.newPage();
  const errors = watch(page);
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'olg-' + width);
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/api/enrol')), page.click('[data-enrol] button[type=submit]')]);
  await page.waitForTimeout(1500);
  await page.evaluate(() => { location.hash = '#/orders'; });
  await page.waitForSelector('.row.ord', { timeout: 20000 });
  await page.waitForTimeout(800);
  const m = await page.evaluate(() => ({
    sw: document.documentElement.scrollWidth,
    rows: [...document.querySelectorAll('.row.ord')].slice(0, 8).map((r) => Math.round(r.getBoundingClientRect().height) + 'px ' + r.querySelector('small').textContent.replace(/\s+/g, ' ').trim()),
  }));
  say(`owner app list ${width}: scrollWidth ${m.sw}`);
  m.rows.forEach((r) => say(`  ${r}`));
  await page.screenshot({ path: `${OUT}/owner-app-orders-${width}.png` });

  for (const [nth, tag] of [[0, 'ar'], [1, 'en']]) {
    await page.evaluate(() => { location.hash = '#/orders'; });
    await page.waitForSelector('.row.ord a.rm', { timeout: 20000 });
    await page.waitForTimeout(500);
    await page.locator('.row.ord a.rm').nth(nth).click();
    await page.waitForSelector('.od-sum', { timeout: 15000 });
    await page.waitForTimeout(900);
    const d = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, ln: (document.querySelector('.od-sum .ln') || {}).textContent, h: Math.round(document.querySelector('.od-sum').getBoundingClientRect().height) }));
    say(`owner app order ${tag} ${width}: scrollWidth ${d.sw}; summary ${d.h}px; line ${JSON.stringify(d.ln)}`);
    await page.screenshot({ path: `${OUT}/owner-app-order-${tag}-${width}.png` });
  }
  say(`owner app ${width}: errors ${JSON.stringify(errors)}`);
  await ctx.close();
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const w of [1280, 390]) await admin(browser, w);
  if (APP) for (const w of [390, 1280]) await ownerApp(browser, w);
  await browser.close();
  fs.writeFileSync(`${OUT}/numbers.txt`, lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
