/* Lane AP-API: Growth & Marketing -> Ads Autopilot at 1280 and 390.
   BASE=http://127.0.0.1:PORT node tools/apapi-shots.cjs
   Writes docs/apapi-shots/*.png and prints measurements + console errors. */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/apapi-shots');
require('fs').mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome' });
  const out = {};
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } });
    const page = await ctx.newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('adsautopilot'));
    await page.waitForSelector('[data-aap-cost]', { state: 'attached', timeout: 30000 });
    await page.screenshot({ path: `${OUT}/${w}-screen.png`, fullPage: true });
    // The console scrolls inside its own pane, so the lower cards get shots of their own.
    for (const [sel, name] of [['.aap-sw', 'measurement'], ['[data-aap-cost]', 'cost-prices']]) {
      await page.locator(sel).first().scrollIntoViewIfNeeded();
      await page.screenshot({ path: `${OUT}/${w}-${name}.png` });
    }

    // Create a key: it is shown once.
    await page.fill('[data-aap-name]', 'Preview Mac');
    await page.click('[data-aap-create]');
    await page.waitForSelector('[data-aap-token]');
    const token = await page.textContent('[data-aap-token]');
    await page.screenshot({ path: `${OUT}/${w}-key-created.png` });

    // The key reads the API; then press "I have copied it" and it is gone from the page.
    const api = await page.evaluate(async (t) => (await fetch('/autopilot-api/v1/ping', { headers: { Authorization: 'Bearer ' + t } })).status, token);
    await page.click('[data-aap-hide]');
    const gone = !(await page.content()).includes(token);

    // Switch "retry" off and back on: before / after.
    await page.click('[data-aap-m="capi_retry"][data-v="0"]');
    await page.waitForTimeout(600);
    const sw = page.locator('.aap-sw').nth(1);
    await sw.screenshot({ path: `${OUT}/${w}-retry-off.png` });
    await page.click('[data-aap-m="capi_retry"][data-v="1"]');
    await page.waitForTimeout(600);
    await sw.screenshot({ path: `${OUT}/${w}-retry-on.png` });

    out[w] = {
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
      keyShape: /^kbbap_[A-Za-z0-9]{40}$/.test(token), apiWithKey: api, keyGoneAfterHide: gone,
      keys: await page.locator('.aap-tbl').first().locator('tbody tr').count(),
      createBtnFont: await page.$eval('[data-aap-create]', (b) => getComputedStyle(b).fontSize),
      h2Font: await page.$eval('.aap-head h2', (h) => getComputedStyle(h).fontSize),
      errors,
    };
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
