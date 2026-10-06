/* Lane SP: Appearance -> Site layout -> Page speed, at 1280 and 390.
 *   node tools/spd-admin-shots.cjs LABEL OUTDIR
 */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const D = path.join(path.dirname(__dirname), 'storage/framework/testing/lane-spd-' + process.argv[2]);
const base = 'http://127.0.0.1:' + fs.readFileSync(D + '/port', 'utf8').trim();
const OUT = process.argv[3];
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [w, h] of [[1280, 900], [390, 844]]) {
    const page = await (await b.newContext({ viewport: { width: w, height: h }, isMobile: w < 600, hasTouch: w < 600, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' })).newPage();
    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await page.click('button[type=submit], input[type=submit]');
    // The preview runs APP_ENV=production, so the redirect after login is to
    // https://, which php -S cannot speak; the session is set, so go by hand.
    await page.waitForTimeout(2000);
    await page.goto(base + '/admin', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof window.go === 'function', null, { timeout: 30000 });
    await page.evaluate(() => window.go('sitelayout'));
    await page.waitForSelector('[data-sls-tab="speed"]', { timeout: 20000 });
    await page.click('[data-sls-tab="speed"]');
    await page.waitForTimeout(800);
    await page.screenshot({ path: `${OUT}/admin-site-layout-page-speed-${w}.png` });
    console.log(w, await page.evaluate(() => ['Page speed', 'Open pages instantly', 'Fade between pages'].map((t) => t + '=' + document.body.innerText.includes(t)).join(' ')),
      'sw', await page.evaluate(() => document.documentElement.scrollWidth));
  }
  await b.close();
})();
