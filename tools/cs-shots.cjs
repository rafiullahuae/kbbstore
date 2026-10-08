/*
 * Lane CS: screenshots and measurements for Appearance -> Coming Soon page,
 * against a running tools/cs-preview.sh (wiring applied with tools/cs-wire.php).
 *
 *   node tools/cs-shots.cjs <port>
 *
 * Writes docs/CS-coming-soon-shots/*.png and numbers.json:
 *   - the Coming Soon page, EN and AR, at 390 and 1280 (kbeautybliss.com, a visitor);
 *   - the admin screen at 1280 and 390;
 *   - the real shop on kbeautybliss.com for a signed-in admin, with the notice,
 *     plus a check that the notice never takes a click (elementFromPoint);
 *   - the Domain switch wizard's new line;
 *   - console errors, scrollWidth, response status and bytes for each page.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '11470';
const APP = path.resolve(__dirname, '..');
const OUT = path.join(APP, 'docs/CS-coming-soon-shots');
const EXE = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const NEW = `http://kbeautybliss.com:${PORT}`;
const OLD = `http://extrabeauty.ae:${PORT}`;
fs.mkdirSync(OUT, { recursive: true });

const numbers = {};
const errors = [];

function ctxFor(browser, width) {
  const mobile = width < 600;
  return browser.newContext({ viewport: { width, height: mobile ? 844 : 860 }, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, hasTouch: mobile });
}

function watch(page, label) {
  page.on('console', (m) => { if (m.type() === 'error') errors.push(`${label}: ${m.text()}`); });
  page.on('pageerror', (e) => errors.push(`${label}: ${e.message}`));
}

async function login(page, origin) {
  await page.goto(origin + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, args: [
    '--no-proxy-server',
    '--host-resolver-rules=MAP extrabeauty.ae 127.0.0.1,MAP www.extrabeauty.ae 127.0.0.1,MAP kbeautybliss.com 127.0.0.1,MAP www.kbeautybliss.com 127.0.0.1',
  ] });

  /* 1. the page a visitor sees */
  for (const width of [390, 1280]) {
    for (const lang of ['en', 'ar']) {
      const ctx = await ctxFor(browser, width);
      const page = await ctx.newPage();
      watch(page, `page-${lang}-${width}`);
      const requests = [];
      page.on('request', (r) => requests.push(r.url()));
      const res = await page.goto(NEW + (lang === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
      const body = await res.body();
      const m = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        dir: document.documentElement.dir,
        h1: getComputedStyle(document.querySelector('h1')).fontSize,
        message: getComputedStyle(document.querySelector('.m')).fontSize,
        scripts: document.scripts.length,
        langLinkHit: (() => { const a = document.querySelector('.lang'); const r = a.getBoundingClientRect(); return document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) === a; })(),
      }));
      numbers[`page-${lang}-${width}`] = { status: res.status(), headers: { 'cache-control': res.headers()['cache-control'], 'retry-after': res.headers()['retry-after'], 'x-robots-tag': res.headers()['x-robots-tag'] }, bytes: body.length, requests: requests.length, ...m };
      await page.screenshot({ path: path.join(OUT, `page-${lang}-${width}.png`) });
      await ctx.close();
    }
  }

  /* 2. the admin screen, signed in on extrabeauty.ae */
  for (const width of [1280, 390]) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    watch(page, `admin-${width}`);
    await login(page, OLD);
    const loaded = page.waitForResponse((r) => r.url().includes('/admin-api/coming-soon/preview'));
    await page.goto(OLD + '/admin?go=comingsoon', { waitUntil: 'networkidle' });
    await loaded;
    await page.waitForTimeout(400);
    numbers[`admin-${width}`] = await page.evaluate(() => ({
      title: document.querySelector('#ptitle') && document.querySelector('#ptitle').textContent,
      crumb: document.querySelector('#crumb') && document.querySelector('#crumb').textContent,
      status: document.querySelector('[data-csx-status]') && document.querySelector('[data-csx-status]').innerText,
      navRow: !!document.querySelector('.nav-item[data-go="comingsoon"]'),
      scrollWidth: document.documentElement.scrollWidth,
    }));
    if (width === 1280) {
      // A live link on the screen, as the owner sees it after saving "On".
      const minted = page.waitForResponse((r) => r.url().includes('/admin-api/coming-soon/link'));
      page.once('dialog', (d) => d.accept());
      await page.click('[data-csx-rotate]');
      await minted;
      await page.waitForTimeout(300);
      await page.setViewportSize({ width: 1280, height: 1900 });
      await page.screenshot({ path: path.join(OUT, 'admin-1280-full.png') });
      await page.setViewportSize({ width: 1280, height: 860 });
    }
    await page.screenshot({ path: path.join(OUT, `admin-${width}.png`) });

    if (width === 1280) {
      // The Arabic tab and the preview frame in Arabic, phone width.
      await page.click('[data-csx-tlang="ar"]');
      const ar = page.waitForResponse((r) => r.url().includes('/admin-api/coming-soon/preview'));
      await page.click('[data-csx-plang="ar"]');
      await ar;
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(OUT, 'admin-ar-tab-1280.png') });

      // The Domain switch wizard's line.
      await page.goto(OLD + '/admin?go=domainswitch', { waitUntil: 'networkidle' });
      await page.waitForSelector('[data-dw-coming-soon]', { timeout: 15000 });
      const el = await page.$('.dw-head');
      numbers['domainswitch-line'] = await page.evaluate(() => document.querySelector('[data-dw-coming-soon]').innerText);
      await el.screenshot({ path: path.join(OUT, 'domain-switch-line-1280.png') });
    }
    await ctx.close();
  }

  /* 3. the real shop on kbeautybliss.com for a signed-in admin, with the notice */
  for (const width of [390, 1280]) {
    const ctx = await ctxFor(browser, width);
    const page = await ctx.newPage();
    watch(page, `admin-shop-${width}`);
    await login(page, NEW);
    const res = await page.goto(NEW + '/', { waitUntil: 'networkidle' });
    numbers[`admin-shop-${width}`] = {
      status: res.status(),
      'cache-control': res.headers()['cache-control'],
      'x-robots-tag': res.headers()['x-robots-tag'],
      ...(await page.evaluate(() => {
        const n = document.querySelector('[data-kbb-coming-soon-notice]');
        const r = n.getBoundingClientRect();
        const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
        return { notice: n.textContent, noticeTakesClick: n.contains(hit), pointerEvents: getComputedStyle(n).pointerEvents, scrollWidth: document.documentElement.scrollWidth, noticeHeight: Math.round(r.height) };
      })),
    };
    await page.screenshot({ path: path.join(OUT, `admin-shop-notice-${width}.png`) });
    await ctx.close();
  }

  /* 4. extrabeauty.ae for a visitor: the shop, no notice */
  {
    const ctx = await ctxFor(browser, 390);
    const page = await ctx.newPage();
    watch(page, 'old-visitor');
    const res = await page.goto(OLD + '/', { waitUntil: 'networkidle' });
    numbers['extrabeauty-visitor-390'] = { status: res.status(), notice: await page.$('[data-kbb-coming-soon-notice]') !== null, comingSoon: res.headers()['x-kbb-coming-soon'] || null };
    await ctx.close();
  }

  numbers.consoleErrors = errors;
  fs.writeFileSync(path.join(OUT, 'numbers.json'), JSON.stringify(numbers, null, 2) + '\n');
  console.log(JSON.stringify(numbers, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
