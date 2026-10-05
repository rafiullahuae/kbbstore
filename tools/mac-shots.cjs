/*
 * LANE MAC — the owner app on the device matrix, every screen, with the
 * horizontal-overflow check measured beside each picture.
 *
 *   KBB_BASE=http://127.0.0.1:10220 KBB_APP=/<secret> node tools/mac-shots.cjs [device,device]
 *
 * Playwright against tools/mac-preview.sh, seeded by tools/mac-seed.php. The
 * measuring below is the HARNESS measuring the page; the app itself measures
 * nothing. Results land in docs/mac-shots/ (JPEG) and docs/mac-shots/report.json.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10220';
const APP = process.env.KBB_APP;
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OUT = process.env.KBB_SHOTS || path.join(__dirname, '..', 'docs', 'mac-shots');
fs.mkdirSync(OUT, { recursive: true });

const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';
const SAMSUNG = 'Mozilla/5.0 (Linux; Android 14; SM-S928B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
const ANDROID_TAB = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

const DEVICES = {
  'android-360': { w: 360, h: 800, ua: ANDROID, dpr: 1.5 },
  'android-412': { w: 412, h: 915, ua: SAMSUNG, dpr: 1.5 },
  'iphone-se-375': { w: 375, h: 667, ua: IPHONE, dpr: 1.5, ios: true },
  'iphone-15-390': { w: 390, h: 844, ua: IPHONE, dpr: 1.5, ios: true },
  'iphone-promax-430': { w: 430, h: 932, ua: IPHONE, dpr: 1.5, ios: true },
  'tablet-768': { w: 768, h: 1024, ua: IPAD, dpr: 1, ios: true },
  'tablet-820': { w: 820, h: 1180, ua: ANDROID_TAB, dpr: 1 },
  'tablet-1180-landscape': { w: 1180, h: 820, ua: ANDROID_TAB, dpr: 1 },
};

const report = fs.existsSync(path.join(OUT, 'report.json')) ? JSON.parse(fs.readFileSync(path.join(OUT, 'report.json'), 'utf8')) : {};
const wanted = (process.argv[2] || Object.keys(DEVICES).join(',')).split(',');

async function overflow(page) {
  return page.evaluate(() => {
    const vw = window.innerWidth;
    const doc = document.documentElement.scrollWidth;
    const inner = Array.from(document.querySelectorAll('.body, .sheet, .pane-l, .pane-d, .pin'))
      .filter((e) => e.scrollWidth > e.clientWidth + 1).map((e) => e.className + ' ' + e.scrollWidth + '>' + e.clientWidth);
    return { vw, scrollWidth: doc, ok: doc === vw && inner.length === 0, inner };
  });
}

async function shot(page, dev, name, results) {
  await page.waitForTimeout(350);
  const file = dev + '--' + name + '.jpg';
  await page.screenshot({ path: path.join(OUT, file), type: 'jpeg', quality: 80 });
  const o = await overflow(page);
  results[name] = Object.assign({ file }, o);
  process.stdout.write((o.ok ? '  ok ' : '  !! ') + dev + ' ' + name + ' ' + o.scrollWidth + '/' + o.vw + (o.inner.length ? ' ' + o.inner.join('; ') : '') + '\n');
}

const go = async (page, hash) => { await page.evaluate((h) => { location.hash = h; }, hash); await page.waitForLoadState('networkidle'); await page.waitForTimeout(250); };
const closeSheets = async (page) => { await page.keyboard.press('Escape'); await page.waitForTimeout(400); };

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  for (const dev of wanted) {
    const d = DEVICES[dev];
    if (!d) continue;
    const ctx = await browser.newContext({ viewport: { width: d.w, height: d.h }, deviceScaleFactor: d.dpr, isMobile: d.w < 700, hasTouch: true, userAgent: d.ua });
    // iPhone Safari has no element full screen (requestFullscreen is absent on
    // iPhone). Chromium with an iPhone user agent still has it, so the iOS rows
    // remove it, which is what the real browser presents to the app.
    if (d.ios && d.w < 700) {
      await ctx.addInitScript(() => {
        delete Element.prototype.requestFullscreen;
        Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false });
      });
    }
    const page = await ctx.newPage();
    const results = {};
    page.on('pageerror', (e) => process.stdout.write('  PAGE ERROR ' + dev + ': ' + e.message + '\n'));
    page.on('console', (m) => { if (m.type() === 'error') process.stdout.write('  console ' + dev + ': ' + m.text() + '\n'); });

    // First sight: sign in with email + PIN.
    await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-enrol]');
    await shot(page, dev, '00-sign-in', results);
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=pin]', '4826');
    await page.fill('input[name=device_name]', dev);
    await page.click('[data-enrol] button[type=submit]');
    await page.waitForSelector('.rf', { timeout: 5000 }).catch(() => {});
    await page.waitForSelector('.a2', { timeout: 6000 }).catch(() => {});
    await page.waitForTimeout(700);
    if (await page.$('.sheet.a2.open')) await shot(page, dev, '03-add-to-home', results);
    await closeSheets(page);
    await page.waitForSelector('.rf', { state: 'detached', timeout: 5000 }).catch(() => {});
    await shot(page, dev, '04-dashboard', results);

    // The "Refreshing the app" overlay is gone (Lane OA2): the grey loading
    // bars and the silent refresh are photographed by tools/oa2-shots.cjs.

    await go(page, '#/notifications'); await shot(page, dev, '05-notifications', results);
    await go(page, '#/more'); await shot(page, dev, '06-more', results);

    await go(page, '#/orders');
    await page.waitForSelector('.row.ord');
    if (d.w < 768) {
      await shot(page, dev, '07-orders', results);
      const sels = await page.$$('.row.ord .sel');
      for (const s of sels.slice(0, 3)) await s.click();
      await page.waitForTimeout(350);
      await shot(page, dev, '08-orders-bulk', results);
      await page.click('.selhdr [data-act="selnone"]');
      const first = await page.$$eval('.row.ord', (rs) => rs.map((r) => r.getAttribute('data-o')));
      await go(page, '#/orders/' + first[0]);
      await shot(page, dev, '09-order-detail', results);
      await page.evaluate(() => { const b = document.querySelector('.body'); b.scrollTop = 520; });
      await shot(page, dev, '09b-order-payment', results);
      await page.evaluate(() => { const b = document.querySelector('.body'); b.scrollTop = 99999; });
      await shot(page, dev, '09c-order-notes', results);
      await page.click('[data-act="status"]');
      await shot(page, dev, '09d-status-sheet', results);
      await closeSheets(page);
    } else {
      await shot(page, dev, '15-tablet-split', results);
      const sels = await page.$$('.row.ord .sel');
      for (const s of sels.slice(0, 2)) await s.click();
      await shot(page, dev, '15b-tablet-bulk', results);
      await page.click('.selhdr [data-act="selnone"]');
      await page.click('.pane-d [data-act="status"]');
      await shot(page, dev, '15c-tablet-status-sheet', results);
      await closeSheets(page);
    }

    await go(page, '#/products'); await shot(page, dev, '10-products', results);
    const pid = await page.$$eval('.list a.row', (rs) => rs.map((r) => r.getAttribute('href')));
    await go(page, pid[1] || pid[0]); await shot(page, dev, '11-product-detail', results);
    await page.click('[data-ed="inv"]'); await shot(page, dev, '12-product-inventory', results); await closeSheets(page);
    await page.click('[data-ed="price"]'); await shot(page, dev, '12b-product-price', results); await closeSheets(page);
    await page.click('[data-ed="cat"]'); await page.waitForTimeout(400); await shot(page, dev, '12c-product-categories', results); await closeSheets(page);
    await page.click('[data-ed="desc"]'); await shot(page, dev, '12d-product-description', results); await closeSheets(page);

    await go(page, '#/customers'); await shot(page, dev, '13-customers', results);
    const sab = await page.$$eval('.list a.row', (rs) => rs.filter((r) => /Sabina/.test(r.textContent)).map((r) => r.getAttribute('href')));
    await go(page, sab[0]); await shot(page, dev, '14-customer-detail', results);
    await page.evaluate(() => { const b = document.querySelector('.body'); b.scrollTop = 600; });
    await shot(page, dev, '14b-customer-history', results);

    // Lock, then the PIN pad.
    await go(page, '#/more');
    await page.click('[data-lock]');
    await page.waitForSelector('.pad');
    await shot(page, dev, '01-pin', results);
    for (const k of ['4', '8', '2']) await page.click('[data-k="' + k + '"]');
    await shot(page, dev, '01b-pin-typing', results);
    await page.click('[data-k="6"]');
    await page.waitForSelector('.rf', { timeout: 5000 }).catch(() => {});
    await page.waitForSelector('.rf', { state: 'detached', timeout: 8000 }).catch(() => {});
    await shot(page, dev, '01c-after-unlock', results);

    report[dev] = { viewport: d.w + 'x' + d.h, ua: d.ua, screens: results };
    await ctx.close();
  }
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
