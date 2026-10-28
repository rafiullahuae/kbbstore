/*
 * Lane OAG -- Free gifts in the owner app, at 390, and the desk's conflict
 * message at 1280, with the numbers behind them.
 *
 *   sh tools/oag-preview.sh            # prints the port and the owner-app path
 *   BASE=http://127.0.0.1:<port> APP=/<owner-app-path> OUT=docs/oag-shots node tools/oag-shots.cjs
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE;
const APP = process.env.APP;
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/oag-shots';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const say = (s) => process.stdout.write(s + '\n');

function watch(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console ' + m.text()); });
  page.on('response', (r) => { if (r.status() >= 400 && r.status() !== 409) errors.push(r.status() + ' ' + r.url()); });
  return errors;
}
const pause = (p, ms) => p.waitForTimeout(ms);
async function shot(page, name, tall) {
  if (tall) {
    // The scroller is .body; a tall viewport shows all of it in one picture.
    const h = await page.evaluate(() => { const b = document.querySelector('#oa-view .body'); return b ? b.scrollHeight + 160 : 844; });
    await page.setViewportSize({ width: 390, height: Math.max(844, Math.min(h, 4000)) });
    await pause(page, 350);
  }
  await page.screenshot({ path: `${OUT}/${name}.png` });
  const sw = await page.evaluate(() => document.documentElement.scrollWidth);
  say(`${name}: scrollWidth ${sw}`);
  if (tall) await page.setViewportSize({ width: 390, height: 844 });
}

/* Another device saves: the app's own API, from this page, with the revision the server has now. */
async function otherDevice(page, rangeIndex) {
  return page.evaluate(async ({ app, i }) => {
    const h = { 'X-OA': '1', 'X-OA-CSRF': sessionStorage.getItem('oa.k'), Accept: 'application/json', 'Content-Type': 'application/json' };
    const d = await (await fetch(app + '/api/gifts', { headers: h })).json();
    const r = d.ranges.slice().sort((a, b) => a.min - b.min)[i];
    const body = { min: r.min, max: r.max, label_en: r.label_en, label_ar: r.label_ar, enabled: r.enabled, starts_on: r.starts_on, ends_on: r.ends_on, needs: r.needs, revision: d.revision,
      gifts: r.gifts.map((g, n) => Object.assign({ product_id: g.product_id, quantity: n === 0 ? g.quantity + 1 : g.quantity, kind: g.kind, role: g.role }, g.variant_id ? { variant_id: g.variant_id } : {})) };
    const res = await fetch(app + '/api/gifts/ranges/' + r.id, { method: 'POST', headers: h, body: JSON.stringify(body) });
    return res.status;
  }, { app: BASE + APP, i: rangeIndex });
}

async function ownerApp(browser) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE });
  await ctx.addInitScript(() => { try { localStorage.setItem('oa.ic', String(Date.now())); localStorage.setItem('oa.bo', '1'); localStorage.setItem('oa.np', String(Date.now())); } catch (e) {} });
  await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
  const page = await ctx.newPage();
  const errors = watch(page);
  const api = [];
  page.on('request', (r) => { if (r.url().includes('/api/gifts')) api.push(r.method() + ' ' + r.url().replace(BASE + APP, '')); });
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'oag-390');
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/api/enrol')), page.click('[data-enrol] button[type=submit]')]);
  await pause(page, 1500);
  await page.keyboard.press('Escape');
  await page.evaluate(() => { location.hash = '#/more'; });
  await page.waitForSelector('a[href="#/gifts"]');
  await pause(page, 500);
  await shot(page, '01-more-entry-390');

  // Gifts home: mode, things to check, ranges.
  await page.click('a[href="#/gifts"]');
  await page.waitForSelector('.g-modes');
  await pause(page, 600);
  await shot(page, '02-gifts-home-390', true);
  await shot(page, '02b-gifts-home-top-390');

  // The refresh icon: one GET on tap, then "Up to date · just now".
  const before = api.length;
  await page.click('.top [data-sync]');
  await pause(page, 250);
  await shot(page, '03-refresh-spinning-390');
  await pause(page, 1200);
  say('refresh: requests to /api/gifts on tap = ' + api.slice(before).filter((u) => u.startsWith('GET /api/gifts')).length + ' · sub "' + (await page.textContent('[data-gsub]')) + '"');
  await shot(page, '03b-refresh-done-390');

  // Mode: Live asks first.
  await page.click('[data-gmode="live"]');
  await pause(page, 500);
  await shot(page, '04-mode-confirm-390');
  await page.click('.sheet [data-close]');
  await pause(page, 400);

  // A range's gifts.
  await page.click('a.g-rr >> nth=1');
  await page.waitForSelector('.g-box');
  await pause(page, 600);
  await shot(page, '05-range-390', true);
  await shot(page, '05b-range-top-390');

  // Add gift: search (typed like a person; count the requests).
  const n0 = api.length;
  await page.click('[data-gpick=""]');
  await page.waitForSelector('[data-pkq]');
  await pause(page, 700);
  await page.type('[data-pkq]', 'sheet mask', { delay: 80 });
  await pause(page, 900);
  const searches = api.slice(n0).filter((u) => u.includes('/api/gifts/products'));
  say('picker: requests while typing "sheet mask" (10 keys) = ' + searches.length + ' -> ' + JSON.stringify(searches));
  await shot(page, '06-add-product-search-390');

  // Choose variation.
  await page.fill('[data-pkq]', '');
  await page.type('[data-pkq]', 'tint', { delay: 80 });
  await page.waitForSelector('[data-pkp]');
  await pause(page, 600);
  await page.click('[data-pkp]');
  await page.waitForSelector('[data-pkv]');
  await pause(page, 400);
  await shot(page, '07-choose-variation-390');
  await page.click('[data-pkv] >> nth=0');   // Red: no remembered type, opened from "Add gift" -> Which box?
  await pause(page, 300);
  await shot(page, '07b-which-box-390');
  await page.click('[data-pkask="mini"]');
  await pause(page, 400);
  await shot(page, '07c-variation-added-390');
  await page.click('.g-pkfoot [data-close]');
  await pause(page, 500);
  await shot(page, '08-range-unsaved-390', true);

  // Save: the console's own rule.
  await page.click('[data-gsavebtn]');
  await pause(page, 1200);
  await shot(page, '09-saved-390');

  // Override protection: edit here, another device saves, then Save here.
  await page.click('[data-gq][data-d="1"] >> nth=0');
  await pause(page, 300);
  say('other device save: HTTP ' + await otherDevice(page, 0));
  const [res] = await Promise.all([page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/api/gifts/ranges/')), page.click('[data-gsavebtn]')]);
  say('stale save from this phone: HTTP ' + res.status());
  await page.waitForSelector('[data-grefresh]');
  await pause(page, 500);
  await shot(page, '10-changed-on-another-device-390');
  await page.click('[data-grefresh]');
  await pause(page, 1200);
  await shot(page, '10b-after-refresh-390');
  say('owner app errors ' + JSON.stringify(errors));
  await ctx.close();
}

async function desk(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  const errors = watch(page);
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.evaluate(() => window.go('freegifts'));
  await page.waitForSelector('#fg-tab-ranges');
  await page.click('#fg-tab-ranges');
  await page.waitForSelector('.fg-gi');
  await pause(page, 500);
  // Edit here, then the phone saves, then Save here.
  await page.click('.fg-gi .fg-step button:last-child >> nth=0');
  // The phone: a second session, through the app's API.
  const p2 = await ownerSession(browser);
  say('desk: phone save meanwhile HTTP ' + await otherDevice(p2, 1));
  await page.click('[data-fk^="save-"]');
  await pause(page, 1200);
  await page.screenshot({ path: `${OUT}/11b-desk-conflict-toast-1280.png` });
  await page.evaluate(() => document.querySelector('.fg-note').scrollIntoView({ block: 'start' }));
  await pause(page, 300);
  await page.screenshot({ path: `${OUT}/11-desk-conflict-1280.png` });
  say('desk banner: ' + (await page.textContent('.fg-note')));
  say('desk errors ' + JSON.stringify(errors));
  await ctx.close();
}

async function ownerSession(browser) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-enrol]');
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=pin]', '482615');
  await page.fill('input[name=device_name]', 'oag-second');
  await Promise.all([page.waitForResponse((r) => r.url().endsWith('/api/enrol')), page.click('[data-enrol] button[type=submit]')]);
  await pause(page, 800);
  return page;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  await ownerApp(browser);
  await desk(browser);
  await browser.close();
})();
