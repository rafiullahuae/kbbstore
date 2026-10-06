/*
 * Lane UA: App → Site App → App update, photographed against the preview from
 * tools/ua-preview.sh. Usage: node tools/ua-shots.cjs <base>
 *
 * The installed app is EMULATED, and every shot says so in a label drawn by
 * this harness: Chromium's display-mode is set to standalone through the
 * DevTools protocol (the shop's CSS and script both see it), and the iPhone
 * one also gets navigator.standalone = true, which is what Safari sets on a
 * Home Screen app. The phone's stored update number is seeded as an app that
 * was installed before anything was published (kbb.sa.up = 0).
 * Writes docs/ua-shots/*.png and prints the numbers as JSON. The measuring is
 * the HARNESS's, never the shop's.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.argv[2];
const OUT = __dirname + '/../docs/ua-shots';
fs.mkdirSync(OUT, { recursive: true });

const UA = {
  iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
  android: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36',
};

const measure = () => {
  const r = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return b.height ? +b.height.toFixed(1) : 0; };
  const row = document.querySelector('.kfa'), b = document.querySelector('.kfa-tx b'), bt = document.querySelector('.kfa-bt');
  const wa = document.getElementById('kbbWa');
  return {
    standalone: matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
    clientWidth: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth,
    rowDisplay: row ? getComputedStyle(row).display : 'not printed', rowHidden: row ? row.hidden : null,
    updateMode: row ? row.classList.contains('kfa-up') : null,
    row: r('.kfa'), glass: r('.kfa-g'), button: r('.kfa-bt'),
    headline: b ? b.textContent : null, headlineFont: b ? getComputedStyle(b).fontSize : null,
    line: (document.querySelector('.kfa-ty') || {}).textContent || null,
    lineFont: document.querySelector('.kfa-ln') ? getComputedStyle(document.querySelector('.kfa-ln')).fontSize : null,
    buttonText: bt ? bt.textContent.trim() : null, hint: (document.querySelector('.kfa-hn') || {}).textContent || null,
    stored: localStorage.getItem('kbb.sa.up'), waVisibility: wa ? getComputedStyle(wa).visibility : 'no button',
  };
};

async function open(browser, { w, ua, lang = '', app = true, stored = '0' }) {
  const mobile = w < 800;
  const ctx = await browser.newContext({
    viewport: { width: w, height: mobile ? 844 : 900 }, deviceScaleFactor: mobile ? 2 : 1,
    isMobile: mobile, hasTouch: mobile, userAgent: ua || (mobile ? UA.iphone : undefined),
  });
  await ctx.addInitScript(({ app, stored, ios }) => {
    if (app && ios) Object.defineProperty(navigator, 'standalone', { value: true });
    // Chromium here ignores the protocol's display-mode feature, so the
    // script's question is answered too (the harness's emulation, labelled).
    if (app) {
      const real = window.matchMedia.bind(window);
      window.matchMedia = (q) => (/display-mode:\s*standalone/.test(q) ? { matches: true, media: q, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} } : real(q));
    }
    // Lane NT's notification sheet would cover the footer in every app shot.
    try { localStorage.setItem('kbb.sa.np', String(Date.now())); } catch (e) {}
    if (stored !== null && localStorage.getItem('kbb.sa.up') === null) localStorage.setItem('kbb.sa.up', stored);
  }, { app, stored, ios: /iPhone/.test(ua || UA.iphone) });
  const page = await ctx.newPage();
  if (app) {
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'display-mode', value: 'standalone' }] });
  }
  await page.goto(BASE + (lang ? '/' + lang + '/' : '/'), { waitUntil: 'networkidle' });
  return { ctx, page };
}

async function shot(page, name, label) {
  await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
  await page.waitForTimeout(500);
  const m = await page.evaluate(measure);
  await page.evaluate((label) => {
    const d = document.createElement('div');
    d.textContent = label;
    d.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;background:#111;color:#fff;font:600 12px/1.3 system-ui;padding:6px 10px;direction:ltr;text-align:left';
    document.body.appendChild(d);
  }, label);
  const clip = await page.evaluate(() => {
    const f = document.querySelector('footer.kft').getBoundingClientRect();
    const n = document.querySelector('.kft-name') || document.querySelector('.kft-main');
    const top = Math.max(0, n.getBoundingClientRect().top - 40) + scrollY;
    return { x: 0, y: top, width: document.documentElement.clientWidth, height: f.bottom + scrollY - top };
  });
  await page.evaluate(() => { const l = document.body.lastChild; l.style.position = 'absolute'; l.style.top = (document.querySelector('.kft-name') || document.querySelector('.kft-main')).getBoundingClientRect().top + scrollY - 40 + 'px'; });
  await page.screenshot({ path: `${OUT}/${name}.png`, clip, fullPage: true });
  await page.evaluate(() => document.body.lastChild.remove());
  return m;
}

async function admin(browser, w, name, publish) {
  const ctx = await browser.newContext({ viewport: { width: w, height: w < 800 ? 844 : 1000 }, deviceScaleFactor: w < 800 ? 2 : 1, isMobile: w < 800, hasTouch: w < 800 });
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.go('siteapp'));
  await page.waitForTimeout(1500);
  const card = page.locator('.sap-card', { has: page.locator('h3', { hasText: 'App update' }) });
  if (publish) {
    await page.fill('#sapUpEn', 'A fresh new look is ready');
    await card.locator('button', { hasText: 'Publish an update to installed apps' }).click();
    await page.waitForTimeout(1500);
  }
  await card.scrollIntoViewIfNeeded();
  const m = await card.evaluate((c) => ({
    crumb: (document.querySelector('#crumb') || {}).textContent, title: (document.querySelector('#ptitle') || {}).textContent,
    stamp: (c.querySelector('.sap-stamp') || {}).textContent, switchOn: (c.querySelector('[role=switch]') || {}).getAttribute('aria-checked'),
    height: Math.round(c.getBoundingClientRect().height), scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
  }));
  await card.screenshot({ path: `${OUT}/${name}.png` });
  await ctx.close();
  return m;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};
  let s;

  // BEFORE: nothing published. The installed app shows no row; the tab shows Install App.
  s = await open(browser, { w: 390 });
  out.before_app_390 = await shot(s.page, '01-before-app-390', 'Installed app (standalone, EMULATED) · iPhone 390 · nothing published: no row');
  await s.ctx.close();
  out.admin_before_1280 = await admin(browser, 1280, '02-admin-before-1280', false);

  // PUBLISH from the admin card itself (the end-to-end path the owner takes).
  out.admin_after_390 = await admin(browser, 390, '03-admin-after-publish-390', true);
  out.admin_after_1280 = await admin(browser, 1280, '04-admin-after-publish-1280', false);

  // AFTER: the installed app shows Update App; the tab is unchanged.
  s = await open(browser, { w: 390 });
  out.after_app_390 = await shot(s.page, '05-after-app-390', 'Installed app (standalone, EMULATED) · iPhone 390 · update 1 published');
  // The tap: update the worker and reload; the row is gone afterwards.
  await Promise.all([s.page.waitForNavigation({ waitUntil: 'networkidle' }), s.page.click('.kfa-bt')]);
  out.after_tap_390 = await shot(s.page, '06-after-tap-390', 'Installed app (standalone, EMULATED) · after tapping Update App (reloaded)');
  await s.ctx.close();

  s = await open(browser, { w: 390, ua: UA.android, lang: 'ar' });
  out.after_app_ar_390 = await shot(s.page, '07-after-app-ar-390', 'Installed app (standalone, EMULATED) · Android 390 · Arabic · update 1');
  await s.ctx.close();

  s = await open(browser, { w: 390, app: false, stored: null });
  out.after_tab_390 = await shot(s.page, '08-after-browser-tab-390', 'Browser tab (NOT installed) · 390 · update 1 published: Install App row as before');
  await s.ctx.close();

  s = await open(browser, { w: 1280, ua: UA.android });
  out.after_app_1280 = await shot(s.page, '09-after-app-1280', 'Installed app (standalone, EMULATED) · 1280 · update 1');
  await s.ctx.close();

  // A NEW NAME, then update 2 from the card (its box ticks itself): an
  // iPhone that missed it gets the one-line tip; Android does not.
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    const page = await ctx.newPage();
    page.on('dialog', (d) => d.accept());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('siteapp'));
    await page.waitForTimeout(1500);
    await page.fill('#sapName', 'KB Glow');
    await Promise.all([page.waitForResponse((r) => r.url().endsWith('/admin-api/site-app') && r.request().method() === 'POST'),
      page.locator('.sap-card').first().locator('button.sap-btn.is-primary').click()]);
    await page.waitForTimeout(800);
    out.icon_box_ticked = await page.isChecked('#sapUpIcon');
    const card = page.locator('.sap-card', { has: page.locator('h3', { hasText: 'App update' }) });
    await Promise.all([page.waitForResponse((r) => r.url().endsWith('/admin-api/site-app/update')),
      card.locator('button', { hasText: 'Publish an update to installed apps' }).click()]);
    await page.waitForTimeout(800);
    out.update2_stamp = await card.locator('.sap-stamp').textContent();
    out.update2_banner = await page.locator('.sap-note').allTextContents();
    await card.screenshot({ path: `${OUT}/10-admin-update-2-1280.png` });
    await ctx.close();
  }
  s = await open(browser, { w: 390 });
  out.icon_iphone_390 = await shot(s.page, '11-iphone-icon-tip-390', 'Installed app (standalone, EMULATED) · iPhone 390 · update 2 changed the name: iPhone tip');
  await s.ctx.close();
  s = await open(browser, { w: 390, ua: UA.android });
  out.icon_android_390 = await shot(s.page, '12-android-no-tip-390', 'Installed app (standalone, EMULATED) · Android 390 · update 2: no tip (Android refreshes itself)');
  await s.ctx.close();

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
