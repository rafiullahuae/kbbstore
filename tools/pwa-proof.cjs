/*
 * Lane PW: prove the shop's Home Screen app in a real Chromium, against the
 * wired preview (sh tools/pwa-shop-preview.sh prints the URL).
 *
 *   PW_BASE=http://127.0.0.1:8731 node tools/pwa-proof.cjs
 *
 * Writes docs/pwa-shots/*.png and prints one JSON report. Every claim in the
 * report is MEASURED here; the harness reads layout because it is checking
 * the shop, which never does (CLAUDE.md rule 4 is about shipped code).
 *
 *   installable   CDP Page.getInstallabilityErrors / getAppManifest
 *   worker        registered after load, controls the page, passes pages
 *                 through, steps aside on cart / checkout / account
 *   caches        after browsing (with a basket): no page, no cart, no
 *                 checkout, no admin, nothing but the offline page(s)
 *   offline       a page offline -> the offline page; /cart offline -> the
 *                 browser's own error (the worker stayed out of it)
 *   update        a changed worker takes over and leaves exactly one shell
 *   off           App -> Site App off: manifest link gone, worker unregisters
 *   standalone    display-mode: standalone at iPhone SE / 15 / Pro Max and
 *                 iPad sizes: fixed elements inside the screen, no sideways
 *                 scroll
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PW_BASE || 'http://127.0.0.1:8731';
const OUT = path.resolve(__dirname, '../docs/pwa-shots');
const COPY = path.resolve(__dirname, '../storage/framework/testing/lane-pwa-preview/app');
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1';
const IPAD = 'Mozilla/5.0 (iPad; CPU OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1';
fs.mkdirSync(OUT, { recursive: true });

const report = {};
const consoleProblems = [];

async function phone(browser, ua = ANDROID, w = 390, h = 844) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: ua, serviceWorkers: 'allow' });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) consoleProblems.push(m.type() + ' ' + page.url() + ' ' + m.text().slice(0, 200)); });
  page.on('pageerror', (e) => consoleProblems.push('pageerror ' + page.url() + ' ' + e.message));
  page.on('response', (r) => { if (r.status() >= 400) consoleProblems.push('http ' + r.status() + ' ' + r.request().method() + ' ' + r.url()); });
  return { ctx, page };
}

async function controlled(page) {
  await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller, null, { timeout: 15000 });
}

async function cacheDump(page) {
  return page.evaluate(async () => {
    const out = [];
    for (const name of await caches.keys()) {
      const c = await caches.open(name);
      for (const req of await c.keys()) {
        const res = await c.match(req);
        out.push({ cache: name, url: new URL(req.url).pathname + new URL(req.url).search, type: res.headers.get('content-type') || '' });
      }
    }
    return out;
  });
}

async function setSiteApp(browser, on) {
  // Through the real admin endpoint, as the owner: the switch itself is under test.
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login');
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
  const res = await page.evaluate(async (on) => {
    const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
    const r = await fetch('/admin-api/site-app', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ on }) });
    return { status: r.status, body: await r.json() };
  }, on);
  await ctx.close();
  return res;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  // ---------- installable ---------------------------------------------------
  // A persistent profile: Chrome never offers to install from an incognito
  // window, and every newContext() is one.
  {
    const dir = fs.mkdtempSync(path.join(require('os').tmpdir(), 'kbb-pwa-'));
    const ctx = await chromium.launchPersistentContext(dir, { executablePath: '/opt/pw-browsers/chromium', viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: ANDROID });
    const page = ctx.pages()[0] || await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'load' });
    await controlled(page);
    const cdp = await ctx.newCDPSession(page);
    const inst = await cdp.send('Page.getInstallabilityErrors');
    const man = await cdp.send('Page.getAppManifest');
    report.installable = {
      errors: inst.installabilityErrors,
      manifestUrl: man.url,
      manifestErrors: man.errors,
      name: JSON.parse(man.data || '{}').name,
    };
    // Registered AFTER load, never during it.
    report.registration = await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0];
      const js = performance.getEntriesByType('resource').find((e) => e.name.includes('/site-app.js'));
      const fcp = performance.getEntriesByName('first-contentful-paint')[0];
      return { loadEventEnd: Math.round(nav.loadEventEnd), siteAppJsStart: js ? Math.round(js.startTime) : null, siteAppJsBytes: js ? js.transferSize : null, fcp: fcp ? Math.round(fcp.startTime) : null, scope: navigator.serviceWorker.controller ? 'controlled' : 'not' };
    });
    await ctx.close();
    fs.rmSync(dir, { recursive: true, force: true });
  }

  // ---------- the worker on real navigations (with a basket) ---------------
  {
    const { ctx, page } = await phone(browser);
    await page.goto(BASE + '/', { waitUntil: 'load' });
    await controlled(page);
    const via = {};
    const visit = async (p) => {
      const r = await page.goto(BASE + p, { waitUntil: 'load' });
      via[p] = { status: r.status(), fromServiceWorker: r.fromServiceWorker() };
    };
    await visit('/');
    await visit('/shop/');
    const productHref = await page.evaluate(() => (document.querySelector('a[href^="/product/"]') || {}).getAttribute && document.querySelector('a[href^="/product/"]').getAttribute('href'));
    await visit(productHref);
    // A basket, through the shop's own button.
    // A basket, through the shop's own Add button on a listing card (the
    // demo's first product is sold out, so its page button is disabled).
    await page.goto(BASE + '/shop/', { waitUntil: 'load' });
    const btn = page.locator('a[data-kbb-add]').first();
    let added = false;
    if (await btn.count()) {
      await btn.click().catch(() => {});
      await page.waitForTimeout(2000);
      added = true;
    }
    await visit('/cart/');
    const basket = await page.evaluate(() => !document.body.textContent.includes('Your bag is empty') && !!document.querySelector('#cartInner, .cartInner, [id*="cartInner"]'));
    await page.screenshot({ path: OUT + '/cart-390.png' });
    for (const p of ['/checkout/', '/my-account/', '/my-wishlist/', '/track-my-order/', '/admin/login', '/blog/']) await visit(p);
    report.navigations = { basketAttempted: added, basketInCart: basket, via };
    report.cachesAfterBrowsing = await cacheDump(page);
    // POST: a form post to the newsletter endpoint from the page; the worker must not answer it.
    consoleProblems.push('--- a deliberate POST without a CSRF token: its 419 proves it reached the server, not the worker ---');
    report.post = await page.evaluate(async () => {
      const r = await fetch('/api/subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email: 'x' }) });
      return { status: r.status };
    });

    // ---------- offline ------------------------------------------------------
    await ctx.setOffline(true);
    const off = {};
    try {
      await page.goto(BASE + '/shop/', { waitUntil: 'load' });
      off.shop = { title: await page.title(), h1: await page.textContent('h1').catch(() => null) };
      await page.screenshot({ path: OUT + '/offline-390.png' });
    } catch (e) { off.shop = { error: e.message.slice(0, 120) }; }
    try {
      await page.goto(BASE + '/cart/', { waitUntil: 'load', timeout: 8000 });
      off.cart = { title: await page.title(), note: 'loaded?!' };
    } catch (e) { off.cart = { browserError: e.message.split('\n')[0].slice(0, 120) }; }
    await ctx.setOffline(false);
    report.offline = off;
    await ctx.close();
  }

  // ---------- update: a changed worker takes over, one shell left ---------
  {
    const { ctx, page } = await phone(browser);
    await page.goto(BASE + '/', { waitUntil: 'load' });
    await controlled(page);
    const before = await page.evaluate(async () => (await caches.keys()).filter((k) => k.startsWith('kbb-shell-')));
    const swFile = COPY + '/resources/site-app/sw.js';
    const original = fs.readFileSync(swFile, 'utf8');
    fs.writeFileSync(swFile, original + '\n// a new release\n');
    try {
      await page.evaluate(async () => { const r = await navigator.serviceWorker.getRegistration(); await r.update(); });
      await page.waitForFunction(async () => {
        const k = (await caches.keys()).filter((x) => x.startsWith('kbb-shell-'));
        return k.length === 1;
      }, null, { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(1500);
      await page.reload({ waitUntil: 'load' });
      const after = await page.evaluate(async () => (await caches.keys()).filter((k) => k.startsWith('kbb-shell-')));
      report.update = { before, after, changed: before[0] !== after[0] && after.length === 1 };
    } finally {
      fs.writeFileSync(swFile, original);
    }
    await ctx.close();
  }

  // ---------- standalone layout at real device sizes -----------------------
  // The installed app's viewport is the screen minus the status bar (and, on
  // iPhones with a home indicator, minus its safe area: the shop's viewport
  // tag does not ask to cover it, so iOS keeps the page out of it).
  const devices = [
    ['iphone-se', 375, 647, IPHONE], ['iphone-14', 390, 763, IPHONE], ['iphone-15', 393, 759, IPHONE], ['iphone-pro-max', 430, 839, IPHONE],
    ['ipad', 820, 1156, IPAD], ['ipad-pro', 1024, 1342, IPAD],
  ];
  report.standalone = {};
  for (const [name, w, h, ua] of devices) {
    const { ctx, page } = await phone(browser, ua, w, h);
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'display-mode', value: 'standalone' }] }).catch((e) => { report.standaloneEmulation = e.message; });
    const measure = async (label) => page.evaluate((label) => {
      const vw = document.documentElement.clientWidth, vh = window.innerHeight;
      const box = (sel) => { const e = document.querySelector(sel); if (!e) return null; const s = getComputedStyle(e); if (s.display === 'none' || s.visibility === 'hidden') return null; const r = e.getBoundingClientRect(); return { l: Math.round(r.left), t: Math.round(r.top), r: Math.round(r.right), b: Math.round(r.bottom) }; };
      const parts = { header: box('header'), whatsapp: box('#kbbWa .kbw-a'), tabbar: box('.tabbar'), sticky: box('#stickybar.show') };
      const outside = Object.entries(parts).filter(([, b]) => b && (b.l < 0 || b.r > vw || (label !== 'scrolled' && b.t < 0) || b.b > vh)).map(([k]) => k);
      return { label, standalone: matchMedia('(display-mode: standalone)').matches, vw, vh, scrollWidth: document.documentElement.scrollWidth, parts, outside };
    }, label);
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    const home = await measure('home');
    if ([390, 430, 820, 1024].includes(w)) await page.screenshot({ path: `${OUT}/standalone-${name}-${w}.png` });
    // The cart drawer, opened as a shopper opens it.
    const opener = page.locator('[data-kbb-open="cart"]').first();
    let drawer = null;
    if (await opener.count()) {
      await opener.click().catch(() => {});
      await page.waitForTimeout(700);
      drawer = await page.evaluate(() => {
        const d = [...document.querySelectorAll('aside, .drawer, [class*="cart-drawer"], #cartDrawer, .kbb-drawer')].find((e) => { const r = e.getBoundingClientRect(); return r.width > 100 && r.height > 100 && getComputedStyle(e).visibility !== 'hidden'; });
        if (!d) return null;
        const r = d.getBoundingClientRect();
        return { l: Math.round(r.left), t: Math.round(r.top), r: Math.round(r.right), b: Math.round(r.bottom), vw: document.documentElement.clientWidth, vh: innerHeight };
      });
      if (w === 390) await page.screenshot({ path: `${OUT}/standalone-${name}-cart.png` });
    }
    report.standalone[name] = { home, drawer };
    await ctx.close();
  }

  // ---------- off: the switch removes the app cleanly ----------------------
  {
    const { ctx, page } = await phone(browser);
    await page.goto(BASE + '/', { waitUntil: 'load' });
    await controlled(page);
    consoleProblems.push('--- switching the app OFF: a 404 for the manifest after this line is the switch working ---');
    const saved = await setSiteApp(browser, false);
    await page.goto(BASE + '/shop/', { waitUntil: 'load' });
    await page.evaluate(async () => { const r = await navigator.serviceWorker.getRegistration(); if (r) await r.update(); });
    await page.waitForFunction(async () => !(await navigator.serviceWorker.getRegistration()), null, { timeout: 15000 }).catch(() => {});
    await page.goto(BASE + '/', { waitUntil: 'load' });
    report.off = {
      saved: saved.status,
      manifestLink: await page.evaluate(() => !!document.querySelector('link[rel="manifest"]')),
      registrations: await page.evaluate(async () => (await navigator.serviceWorker.getRegistrations()).length),
      kbbCaches: await page.evaluate(async () => (await caches.keys()).filter((k) => k.startsWith('kbb-'))),
      manifestStatus: (await page.request.get(BASE + '/manifest.webmanifest')).status(),
    };
    report.on = (await setSiteApp(browser, true)).status;
    await ctx.close();
  }

  report.consoleProblems = consoleProblems;
  await browser.close();
  console.log(JSON.stringify(report, null, 1));
})();
