// Lane MP: Marketing Pixels tabs, Check results, Custom code, Guide, feed and
// shop pages, at 1280 and 390.  node tools/mp-shots.cjs [port]
// Needs tools/mp-preview.sh running (it fakes the platforms; mp-fake.txt flips ok/bad).
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const PORT = process.argv[2] || 10460;
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-mp-shots');
const FAKE = path.join(__dirname, '..', 'storage', 'framework', 'testing', 'mp-preview', 'webroot', 'mp-fake.txt');
const report = {};

async function login(page, email) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}
async function tab(page, id) {
  await page.click(`[data-mpx-tab="${id}"]`);
  await page.waitForTimeout(700);
}
async function shot(page, name) {
  const el = page.locator('#content');
  const box = await el.boundingBox();
  const inner = await page.evaluate(() => { const c = document.querySelector('#content .wrap'); return c ? c.scrollHeight + 40 : 0; });
  await page.screenshot({ path: path.join(OUT, `${name}.png`), clip: { x: box.x, y: box.y, width: box.width, height: Math.min(box.height, Math.max(400, inner)) } });
}
async function fill(page, key, value) { await page.fill(`[data-mpxk="${key}"]`, value); }
async function check(page, what, mode) {
  fs.writeFileSync(FAKE, mode);
  await page.click(`[data-mpx-check="${what}"]`);
  await page.waitForFunction(() => { const u = document.getElementById('mpxRes'); return u && !/Checking/.test(u.textContent) && u.children.length; }, null, { timeout: 20000 });
  await page.waitForTimeout(200);
}
async function save(page) { await page.click('[data-mpx-save]'); await page.waitForTimeout(1200); }

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const [w, h] of [[1280, 2600], [390, 3200]]) {
    // Tall viewports: the console scrolls inside #content, so an element shot
    // of a normal-height window would cut each tab off at the fold.
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await login(page, 'owner@preview.test');
    await page.goto(`${BASE}/admin?go=pixels`, { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-mpx-tab]');

    await tab(page, 'meta');
    await shot(page, `${w}-meta-wizard-empty`);
    if (w === 1280) {
      await fill(page, 'meta_id', '111122223333444');
      await fill(page, 'meta_capi_token', 'EAAGtokenAbCdEfGhIjKlMnOpQrStUvWxYz0123WXYZ');
      await fill(page, 'meta_test_code', 'TEST4242');
      await fill(page, 'facebook_domain_verification', '<meta name="facebook-domain-verification" content="abc123def456ghi789" />');
      await save(page);
      await tab(page, 'google');
      await fill(page, 'ga4_id', 'G-TESTMP12');
      await fill(page, 'ga4_api_secret', 'gaSecretAbCd1234');
      await fill(page, 'ads_id', 'AW-123456789');
      await fill(page, 'ads_label', 'AbCdEfGh');
      await save(page);
      await tab(page, 'tiktok');
      await fill(page, 'tiktok_id', 'CQ1ABCDEFGHIJ');
      await fill(page, 'tiktok_token', 'tiktokTokenAbCdEfGhIjKlMn9876');
      await fill(page, 'tiktok_test_code', 'TEST5151');
      await save(page);
    }
    await tab(page, 'meta');
    await check(page, 'meta', 'ok');
    await shot(page, `${w}-meta-check-green`);
    await check(page, 'meta', 'bad');
    await shot(page, `${w}-meta-check-red`);

    await tab(page, 'google');
    await check(page, 'ga4', 'ok');
    await shot(page, `${w}-google-check-green`);
    await check(page, 'ga4', 'bad');
    await shot(page, `${w}-google-check-red`);
    await check(page, 'feed', 'ok');
    await shot(page, `${w}-google-feed-check`);
    await check(page, 'live', 'ok');
    await shot(page, `${w}-google-live-check`);

    await tab(page, 'tiktok');
    await check(page, 'tiktok', 'ok');
    await shot(page, `${w}-tiktok-check-green`);
    await check(page, 'tiktok', 'bad');
    await shot(page, `${w}-tiktok-check-red`);

    await tab(page, 'custom');
    if (w === 1280) {
      const boxes = page.locator('[data-cc]');
      await boxes.nth(0).locator('[data-cco]').check();
      await boxes.nth(0).locator('[data-ccc]').fill('<script>document.write("<p>old vendor snippet</p>")</script>');
      await boxes.nth(2).locator('[data-cco]').check();
      await boxes.nth(2).locator('[data-ccc]').fill('<script>window.kbbCcRan=(window.kbbCcRan||0)+1</script>\n<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};})(window,document,"clarity","script","abcdefghij");</script>');
      await page.click('[data-cc-save]');
      await page.waitForTimeout(1200);
    }
    await shot(page, `${w}-custom-code`);
    await tab(page, 'events');
    await shot(page, `${w}-last-events`);
    await tab(page, 'guide');
    await shot(page, `${w}-guide`);
    await tab(page, 'pixels');
    await shot(page, `${w}-pixels-tab-unchanged`);
    report[`admin-${w}-pageerrors`] = errors;
    report[`admin-${w}-scrollWidth`] = await page.evaluate(() => document.documentElement.scrollWidth);
    await ctx.close();

    // The manager: no custom code.
    const mctx = await browser.newContext({ viewport: { width: w, height: 900 } });
    const mp = await mctx.newPage();
    await login(mp, 'manager@preview.test');
    await mp.goto(`${BASE}/admin?go=pixels`, { waitUntil: 'networkidle' });
    await mp.waitForSelector('[data-mpx-tab]');
    await tab(mp, 'custom');
    await mp.waitForTimeout(600);
    await shot(mp, `${w}-custom-code-manager-403`);
    await mctx.close();
  }

  // Shop pages with everything configured: no JS errors, no sideways scroll,
  // the custom code ran once, after load, and its template is gone.
  for (const [w, h] of [[1280, 900], [390, 844]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    for (const [label, url] of [['home', '/'], ['product', '/product/seo-product-5/'], ['brand', '/brands/seo-lab/'], ['category', '/collections/seo-skincare/seo-toners/'], ['shop', '/shop/']]) {
      const errs = [];
      page.on('pageerror', (e) => errs.push(String(e)));
      const res = await page.goto(BASE + url, { waitUntil: 'load' });
      await page.waitForTimeout(3500);
      const m = await page.evaluate(() => ({
        sw: document.documentElement.scrollWidth,
        ran: window.kbbCcRan || 0,
        templatesLeft: document.querySelectorAll('template[data-kbb-cc]').length,
        fbq: typeof window.fbq, gtag: typeof window.gtag, ttq: typeof window.ttq,
      }));
      report[`shop-${label}-${w}`] = { status: res.status(), ...m, pageerrors: errs.slice() };
      page.removeAllListeners('pageerror');
      if (label === 'product') await page.screenshot({ path: path.join(OUT, `${w}-shop-product-all-configured.png`) });
    }
    await ctx.close();
  }

  const feed = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await feed.goto(`${BASE}/feeds/meta-catalog.xml`);
  await feed.screenshot({ path: path.join(OUT, '1280-feed-meta-catalog.png') });
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 1).slice(0, 3000));
})();
