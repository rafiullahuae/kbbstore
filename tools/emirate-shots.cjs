/*
 * Lane EM -- Store -> Orders with the Emirate column and the "Orders by
 * emirate" strip, at 1280 and 390; a chip click filtering the list (before /
 * after / cleared); a column layout saved BEFORE the column existed; and the
 * owner app's orders, emirate chips and an order, at 390. Plus the numbers:
 * scrollWidth, console errors, failed requests, first-try clickability.
 *
 *   sh tools/emirate-preview.sh 10870
 *   BASE=http://127.0.0.1:10870 APP=/<owner-app-path> node tools/emirate-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10870';
const APP = process.env.APP;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-emirate-shots';
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

async function ordersReady(page) {
  await page.waitForSelector('#content .odlscroll table, #content .odlscroll p', { timeout: 20000 });
  await page.waitForTimeout(500);
}

async function tallShot(page, width, path) {
  const h = await page.evaluate(() => Math.ceil(document.querySelector('#content .wrap').getBoundingClientRect().bottom + 40));
  await page.setViewportSize({ width, height: Math.max(900, Math.min(h, 4000)) });
  await page.waitForTimeout(300);
  await page.screenshot({ path });
  await page.setViewportSize({ width, height: 900 });
}

async function admin(browser, width) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, userAgent: width < 700 ? IPHONE : undefined, deviceScaleFactor: width < 700 ? 2 : 1 });
  // A column layout saved before this lane: every old key, no `emirate`.
  await ctx.addInitScript(() => {
    try {
      if (!sessionStorage.getItem('em-seeded')) {
        localStorage.setItem('kbb_ord_cols', JSON.stringify({ status: true, items: true, total: true, refunded: false, payment: true, source: false, placed: true, location: true, contact: false, wc: false }));
        sessionStorage.setItem('em-seeded', '1');
      }
    } catch (e) {}
  });
  const page = await ctx.newPage();
  const errors = watch(page);

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin#orders', { waitUntil: 'networkidle' });
  await page.evaluate(() => window.go('orders'));
  await ordersReady(page);

  const m = await page.evaluate(() => {
    const ths = [...document.querySelectorAll('#content .odlscroll thead th')].map((t) => t.textContent.trim());
    const strip = [...document.querySelectorAll('#content .olem .chip')].map((c) => c.textContent.replace(/\s+/g, ' ').trim());
    const pill = document.querySelector('#content .odlscroll tbody td .pill.grey[title]');
    return { sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, ths, strip,
      pill: pill ? { text: pill.textContent, title: pill.title, font: getComputedStyle(pill).fontSize } : null,
      rows: document.querySelectorAll('#content tr[data-olrow]').length };
  });
  say(`admin ${width}: scrollWidth ${m.sw} / clientWidth ${m.cw}; rows ${m.rows}; headers ${JSON.stringify(m.ths)}`);
  say(`admin ${width}: strip ${JSON.stringify(m.strip)}`);
  say(`admin ${width}: first pill ${JSON.stringify(m.pill)}  (layout saved before the column existed: Emirate still shown = ${m.ths.includes('Emirate')})`);
  await tallShot(page, width, `${OUT}/admin-orders-${width}.png`);

  // First-try clickability of every strip chip: the chip itself is what is under its centre.
  const hit = await page.evaluate(() => [...document.querySelectorAll('#content .olem .chip[data-ole]')].map((c) => {
    c.scrollIntoView({ block: 'center' });
    const r = c.getBoundingClientRect();
    const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return c.contains(el);
  }));
  say(`admin ${width}: strip chips answering elementFromPoint ${hit.filter(Boolean).length}/${hit.length}`);

  // Click DXB: the list filters.
  const t0 = Date.now();
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('/admin-api/orders-list') && r.url().includes('emirate=')),
    page.click('#content .olem .chip[data-ole="Dubai"]'),
  ]);
  await ordersReady(page);
  const f = await page.evaluate(() => ({
    codes: [...new Set([...document.querySelectorAll('#content tbody tr[data-olrow] td .pill.grey[title]')].map((p) => p.textContent))],
    on: (document.querySelector('#content .olem .chip.on') || {}).textContent,
    tile: document.querySelector('#content .odlkpi .v').textContent,
    pager: document.querySelector('#content .odlpager span').textContent,
  }));
  say(`admin ${width}: after DXB click (${Date.now() - t0} ms): codes in list ${JSON.stringify(f.codes)}; chip on ${JSON.stringify(f.on)}; tile "${f.tile}"; pager "${f.pager}"`);
  await tallShot(page, width, `${OUT}/admin-orders-${width}-dxb.png`);

  // Click again: cleared.
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin-api/orders-list')), page.click('#content .olem .chip[data-ole="Dubai"]')]);
  await ordersReady(page);
  const c = await page.evaluate(() => ({ on: !!document.querySelector('#content .olem .chip.on'), pager: document.querySelector('#content .odlpager span').textContent }));
  say(`admin ${width}: clicked again: a chip still on = ${c.on}; pager "${c.pager}"`);

  // Al Ain on its own.
  await Promise.all([page.waitForResponse((r) => r.url().includes('emirate=Al')), page.click('#content .olem .chip[data-ole="Al Ain"]')]);
  await ordersReady(page);
  if (width === 1280) await tallShot(page, width, `${OUT}/admin-orders-${width}-alain.png`);
  const al = await page.evaluate(() => [...new Set([...document.querySelectorAll('#content tbody tr[data-olrow] td .pill.grey[title]')].map((p) => p.textContent + '/' + p.parentElement.querySelector('.pbrand').textContent))]);
  say(`admin ${width}: ALAIN filter shows ${JSON.stringify(al)}`);

  say(`admin ${width}: errors ${JSON.stringify(errors)}`);
  await ctx.close();
}

async function ownerApp(browser) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE });
  await ctx.addInitScript(() => { try { localStorage.setItem('oa.a2', '1'); } catch (e) {} });
  await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
  const page = await ctx.newPage();
  const errors = watch(page);
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'em-390');
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/api/enrol')), page.click('[data-enrol] button[type=submit]')]);
  await page.waitForTimeout(1500);
  await page.evaluate(() => { location.hash = '#/orders'; });
  await page.waitForSelector('[data-emchips] button', { timeout: 20000 });
  await page.waitForTimeout(800);
  const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, chips: [...document.querySelectorAll('[data-emchips] button')].map((b) => b.textContent),
    codes: [...document.querySelectorAll('.row.ord .em')].slice(0, 6).map((b) => b.textContent) }));
  say(`owner app 390: scrollWidth ${m.sw}; emirate chips ${JSON.stringify(m.chips)}; first rows' codes ${JSON.stringify(m.codes)}`);
  await page.screenshot({ path: `${OUT}/owner-app-orders-390.png` });

  await Promise.all([page.waitForResponse((r) => r.url().includes('emirate=Al')), page.click('[data-emchips] button[data-em="Al Ain"]')]);
  await page.waitForTimeout(900);
  const f = await page.evaluate(() => ({ codes: [...new Set([...document.querySelectorAll('.row.ord .em')].map((b) => b.textContent))], rows: document.querySelectorAll('.row.ord').length,
    on: (document.querySelector('[data-emchips] button.on') || {}).textContent }));
  say(`owner app 390: after ALAIN tap: rows ${f.rows}, codes ${JSON.stringify(f.codes)}, chip on ${JSON.stringify(f.on)}`);
  await page.screenshot({ path: `${OUT}/owner-app-orders-390-alain.png` });

  await page.click('.row.ord a.rm');
  await page.waitForSelector('.od-sum', { timeout: 15000 });
  await page.waitForTimeout(900);
  await page.evaluate(() => { const s = [...document.querySelectorAll('h4.sh')].find((h) => h.textContent.startsWith('Shipping')); if (s) s.scrollIntoView({ block: 'center' }); });
  await page.waitForTimeout(300);
  const tag = await page.evaluate(() => { const s = [...document.querySelectorAll('h4.sh')].find((h) => h.textContent.startsWith('Shipping')); return s ? s.textContent : null; });
  say(`owner app 390: order screen Shipping heading "${tag}"`);
  await page.screenshot({ path: `${OUT}/owner-app-order-390.png` });
  say(`owner app 390: errors ${JSON.stringify(errors)}`);
  await ctx.close();
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const w of [1280, 390]) await admin(browser, w);
  if (APP) await ownerApp(browser);
  await browser.close();
  fs.writeFileSync(`${OUT}/numbers.txt`, lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
