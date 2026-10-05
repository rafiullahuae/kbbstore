/*
 * Lane NT screenshots: "Allow notifications" on open, in the owner app and the
 * shop app (English and Arabic), and both admin controls, at 390 and 1280.
 *
 *   sh tools/mac-preview.sh <port>     (with routes/site-app-push.php required
 *                                       in routes/web.php for the run)
 *   KBB_BASE=http://127.0.0.1:<port> KBB_APP=/<secret> node tools/nt-shots.cjs
 *
 * STANDALONE IS EMULATED, and says so: an init script makes
 * matchMedia('(display-mode: standalone)') match, gives Notification a
 * 'default' permission whose requestPermission() grants, and gives
 * pushManager a fake subscription carrying a real P-256 key (headless
 * Chromium has no push service). Everything else is the real page.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10610';
const APP = process.env.KBB_APP;
const OUT = path.join(__dirname, '..', 'docs', 'nt-shots');
fs.mkdirSync(OUT, { recursive: true });
const say = (s) => process.stdout.write(s + '\n');

const jwk = crypto.generateKeyPairSync('ec', { namedCurve: 'prime256v1' }).publicKey.export({ format: 'jwk' });
const b64u = (b) => Buffer.from(b).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const P256 = b64u(Buffer.concat([Buffer.from([4]), Buffer.from(jwk.x, 'base64'), Buffer.from(jwk.y, 'base64')]));
const AUTH = b64u(crypto.randomBytes(16));

function init({ standalone, p256dh, auth }) {
  if (standalone) {
    const mm = window.matchMedia.bind(window);
    window.matchMedia = (q) => (/display-mode:\s*standalone/.test(q)
      ? { matches: true, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} } : mm(q));
  }
  let perm = 'default';
  window.__asked = 0;
  const N = function () {};
  Object.defineProperty(N, 'permission', { get: () => perm });
  N.requestPermission = () => { window.__asked++; perm = 'granted'; return Promise.resolve('granted'); };
  window.Notification = N;
  let sub = null;
  if (window.PushManager) {
    window.PushManager.prototype.getSubscription = async () => sub;
    window.PushManager.prototype.subscribe = async (o) => {
      sub = { options: o, endpoint: 'https://fcm.googleapis.com/fcm/send/nt-preview-' + Date.now(),
        toJSON() { return { endpoint: this.endpoint, keys: { p256dh, auth } }; }, unsubscribe: async () => true };
      return sub;
    };
  }
}

async function ctxFor(browser, w, standalone) {
  const mobile = w < 700;
  const ctx = await browser.newContext({ viewport: { width: w, height: mobile ? 844 : 900 }, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, hasTouch: mobile,
    userAgent: mobile ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1' : undefined });
  await ctx.addInitScript(init, { standalone, p256dh: P256, auth: AUTH });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => say('  PAGE ERROR ' + e.message));
  return { ctx, page };
}

const jpg = (page, name) => page.screenshot({ path: path.join(OUT, name + '.jpg'), type: 'jpeg', quality: 80 });

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });

  say('== owner app, installed (emulated), first unlock');
  for (const w of [390, 1280]) {
    const { ctx, page } = await ctxFor(browser, w, true);
    await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-enrol]');
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=pin]', '482615');
    await page.fill('input[name=device_name]', 'nt-' + w);
    await page.click('[data-enrol] button[type=submit]');
    await page.waitForSelector('.sheet.open [data-allow]', { timeout: 8000 });
    await page.waitForTimeout(450);
    const m = await page.evaluate(() => {
      const s = document.querySelector('.sheet.open'), r = s.getBoundingClientRect(), b = s.querySelector('[data-allow]').getBoundingClientRect();
      return { sheet: Math.round(r.width) + 'x' + Math.round(r.height), allowH: Math.round(b.height), asked: window.__asked, sw: document.documentElement.scrollWidth, vw: innerWidth };
    });
    await jpg(page, 'owner-' + w + '--a-sheet');
    await page.click('.sheet.open [data-allow]');
    await page.waitForSelector('.toast', { timeout: 5000 });
    const toast = await page.textContent('.toast');
    await page.waitForTimeout(350);
    await jpg(page, 'owner-' + w + '--b-allowed');
    const asked = await page.evaluate(() => window.__asked);
    say('  ' + w + 'px: sheet ' + m.sheet + ', Allow ' + m.allowH + 'px tall, browser asked before tap ' + m.asked + ', after tap ' + asked + '; toast "' + toast.trim() + '"; scrollWidth ' + m.sw + '/' + m.vw);
    await ctx.close();
  }

  say('\n== shop app, installed (emulated): English and Arabic');
  for (const [lang, url] of [['en', '/'], ['ar', '/ar/']]) {
    for (const w of [390, 1280]) {
      const { ctx, page } = await ctxFor(browser, w, true);
      const calls = [];
      page.on('request', (r) => { if (r.url().includes('/api/site-app/push')) calls.push(r.method() + ' ' + r.url().replace(BASE, '')); });
      await page.goto(BASE + url, { waitUntil: 'load' });
      await page.waitForSelector('.kbb-np', { timeout: 15000 });
      await page.waitForTimeout(300);
      const m = await page.evaluate(() => {
        const s = document.querySelector('.kbb-np'), r = s.getBoundingClientRect(), b = s.querySelector('.kbb-np-allow').getBoundingClientRect(), h = getComputedStyle(s.querySelector('h2'));
        return { box: Math.round(r.width) + 'x' + Math.round(r.height), allowH: Math.round(b.height), h2: h.fontSize, dir: s.getAttribute('dir'), title: s.querySelector('h2').textContent, asked: window.__asked, sw: document.documentElement.scrollWidth, vw: innerWidth };
      });
      await jpg(page, 'shop-' + lang + '-' + w + '--a-sheet');
      let after = '';
      if (w === 390) {
        await page.click('.kbb-np-allow');
        await page.waitForResponse((r) => r.url().includes('/api/site-app/push') && r.request().method() === 'POST', { timeout: 8000 });
        await page.waitForTimeout(200);
        after = '; tapped Allow -> asked ' + (await page.evaluate(() => window.__asked)) + ', sheet gone ' + !(await page.$('.kbb-np'));
        await jpg(page, 'shop-' + lang + '-' + w + '--b-allowed');
      }
      say('  ' + lang + ' ' + w + 'px: "' + m.title + '" dir=' + m.dir + ', sheet ' + m.box + ', Allow ' + m.allowH + 'px, h2 ' + m.h2 + ', asked before tap ' + m.asked + after + '; requests ' + JSON.stringify(calls) + '; scrollWidth ' + m.sw + '/' + m.vw);
      await ctx.close();
    }
  }

  say('\n== shop in a browser tab (not installed): no sheet, no request');
  {
    const { ctx, page } = await ctxFor(browser, 390, false);
    const calls = [];
    page.on('request', (r) => { if (r.url().includes('/api/site-app/push')) calls.push(r.url()); });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    say('  sheet ' + !!(await page.$('.kbb-np')) + ', push requests ' + calls.length);
    await ctx.close();
  }

  say('\n== admin');
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 844 : 900 }, deviceScaleFactor: w < 700 ? 2 : 1 });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => say('  PAGE ERROR ' + e.message));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);

    await page.goto(BASE + '/admin?go=ownerapp', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-set="ask_push"]', { timeout: 15000 });
    const card = page.locator('.rl-card', { has: page.locator('[data-set="ask_push"]') });
    await card.scrollIntoViewIfNeeded();
    const was = await page.isChecked('[data-set="ask_push"]');
    await card.screenshot({ path: path.join(OUT, 'admin-owner-' + w + '--a-on.jpg'), type: 'jpeg', quality: 80 });
    await page.uncheck('[data-set="ask_push"]');
    await page.click('[data-oa="settings"]');
    await page.waitForSelector('.rl-ok', { timeout: 5000 });
    const now = await page.isChecked('[data-set="ask_push"]');
    await page.locator('.rl-card', { has: page.locator('[data-set="ask_push"]') }).screenshot({ path: path.join(OUT, 'admin-owner-' + w + '--b-off-saved.jpg'), type: 'jpeg', quality: 80 });
    await page.check('[data-set="ask_push"]');
    await page.click('[data-oa="settings"]');
    await page.waitForSelector('.rl-ok', { timeout: 5000 });
    const sw1 = await page.evaluate(() => document.documentElement.scrollWidth + '/' + innerWidth);

    await page.goto(BASE + '/admin?go=siteapp', { waitUntil: 'networkidle' });
    const sw = page.locator('.sap-sw', { hasText: 'Ask shoppers for notifications' });
    await sw.waitFor({ timeout: 15000 });
    const on = await sw.getAttribute('aria-checked');
    await page.locator('.sap-card').first().screenshot({ path: path.join(OUT, 'admin-siteapp-' + w + '--a-on.jpg'), type: 'jpeg', quality: 80 });
    await sw.click();
    await page.click('.sap-btn.is-primary');
    await page.waitForTimeout(800);
    const off = await page.locator('.sap-sw', { hasText: 'Ask shoppers for notifications' }).getAttribute('aria-checked');
    await page.locator('.sap-card').first().screenshot({ path: path.join(OUT, 'admin-siteapp-' + w + '--b-off-saved.jpg'), type: 'jpeg', quality: 80 });
    await page.locator('.sap-sw', { hasText: 'Ask shoppers for notifications' }).click();
    await page.click('.sap-btn.is-primary');
    await page.waitForTimeout(800);
    const sw2 = await page.evaluate(() => document.documentElement.scrollWidth + '/' + innerWidth);
    say('  ' + w + 'px: Owner App settings ask_push as found ' + was + ', after unchecking + Save ' + now + ' (restored), scrollWidth ' + sw1
      + '; Site App switch as found ' + on + ', after toggle + Save ' + off + ' (restored), scrollWidth ' + sw2);
    await ctx.close();
  }

  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
