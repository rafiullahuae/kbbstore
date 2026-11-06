// Lane OHB: Appearance -> Occasion banner, shot at 1280 and 390, and driven:
// pick an occasion and a layout, type a title, and count the requests the
// screen made while doing it (the preview is built in the browser: expect 0).
//   node tools/ohb-admin-shots.cjs http://127.0.0.1:11900
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const BASE = process.argv[2];
const OUT = path.join(__dirname, '..', 'docs', 'lane-ohb-shots');
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, isMobile: width < 700, hasTouch: width < 700 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    let api = 0;
    page.on('request', (r) => { if (r.url().includes('/admin-api/occasion-banner')) api++; });

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.waitForTimeout(600);
    const row = await page.$('#nav [data-go="occbanner"]');
    await page.evaluate(() => window.go('occbanner'));
    await page.waitForSelector('[data-screen="occbanner"] .obx-card', { timeout: 15000 });
    await page.waitForTimeout(1200);
    await page.screenshot({ path: path.join(OUT, `admin-${width}-top.jpg`), type: 'jpeg', quality: 78 });
    for (const [sel, name] of [['.obx-g2', 'words'], ['.obx-cols', 'colours'], ['.obx-pool', 'products']]) {
      await page.evaluate((q) => document.querySelector(q).scrollIntoView({ block: 'start' }), sel);
      await page.waitForTimeout(300);
      await page.screenshot({ path: path.join(OUT, `admin-${width}-${name}.jpg`), type: 'jpeg', quality: 74 });
    }

    // Drive it: occasion UAE, layout H, a typed title; count requests meanwhile.
    const before = api;
    await page.click('[data-a="template"][data-v="uae"]');
    await page.click('[data-a="layout"][data-v="H"]');
    const box = await page.$('[data-k="en.title"]');
    await box.fill('');
    await box.type('NATIONAL DAY 55', { delay: 25 });
    await page.waitForTimeout(400);
    const during = api - before;
    const pv = await page.evaluate(() => {
      const f = document.querySelector('[data-obx-frame]');
      const d = f && f.contentDocument;
      return d ? { title: (d.querySelector('.ohb-title') || {}).textContent, cls: (d.querySelector('.ohb') || {}).className, products: d.querySelectorAll('.ohb-p').length } : null;
    });
    await page.evaluate(() => document.querySelector('.obx-pv').scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(OUT, `admin-${width}-after-picking-uae-H.jpg`), type: 'jpeg', quality: 78 });
    console.log(JSON.stringify({ width, navRow: !!row, requestsWhilePickingAndTyping: during, preview: pv,
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors }));
    await ctx.close();
  }
  await browser.close();
})();
