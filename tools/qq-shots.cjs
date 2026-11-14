/*
 * Lane QQB -- Store -> Quiqup and Schedule with Quiqup, photographed at 1280
 * and 390 against tools/qq-preview.sh (fake Quiqup, nothing leaves the
 * machine). Output: docs/lane-qq-shots/*.png and MEASUREMENTS.json
 * (scrollWidth vs width, console errors, request counts).
 *
 *   NODE_PATH=/home/user/kbbstore/node_modules QQ_BASE=http://127.0.0.1:8931 node tools/qq-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.QQ_BASE || 'http://127.0.0.1:8931';
const OUT = process.env.QQ_OUT || path.join(__dirname, '..', 'docs', 'lane-qq-shots');
const EXE = process.env.QQ_CHROME || '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome';
fs.mkdirSync(OUT, { recursive: true });

const M = {};
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

async function shot(page, name, w, opts = {}) {
  await wait(250);
  const sw = await page.evaluate(() => document.documentElement.scrollWidth);
  M[name + '-' + w] = { width: w, scrollWidth: sw };
  await page.screenshot({ path: path.join(OUT, name + '-' + w + '.png'), fullPage: !!opts.full });
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  for (const w of (process.env.QQ_WIDTHS || '1280,390').split(',').map(Number)) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('response', (r) => { if (r.status() === 404) errors.push('404 ' + r.url()); });
    page.on('dialog', (d) => d.accept());

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);

    // Store -> Quiqup
    await page.evaluate(() => window.go('quiqup'));
    await page.waitForSelector('.qq-seg');
    await shot(page, 'settings-connection-testing', w, { full: true });

    for (const [tab, name] of [['pick', 'settings-pickup'], ['test', 'settings-test-customers'], ['stat', 'settings-statuses'], ['act', 'settings-activity']]) {
      await page.click(`[data-qq-tab="${tab}"]`);
      if (tab === 'act') await page.waitForSelector('.qq-kpis .qq-kpi');
      await shot(page, name, w, { full: true });
    }
    await page.click('[data-qq-tab="conn"]');

    // Go Live, not ready: the checklist with what is missing.
    await page.click('[data-qq-mode="live"]');
    await page.waitForSelector('dialog.qq-dlg');
    await shot(page, 'golive-not-ready', w);
    await page.click('dialog.qq-dlg [data-i="0"]');

    // Paste the live key + secret, save (toast), test (verified).
    await page.fill('#qq_live_api_key', 'preview_fake_live_key_51c0');
    await page.fill('#qq_live_webhook_secret', 'preview_live_whsec_e2aa');
    await page.click('[data-qq-savekeys="live"]');
    await page.waitForSelector('.qq-toast');
    await shot(page, 'toast-saved', w);
    await page.click('[data-qq-test="live"]');
    await page.waitForSelector('.qq-env.live .qq-chip.live:has-text("OK")');
    await shot(page, 'toast-test-ok', w);

    // Go Live, ready: every tick; confirm; Live chip.
    await page.click('[data-qq-mode="live"]');
    await page.waitForSelector('dialog.qq-dlg');
    await shot(page, 'golive-ready', w);
    await page.click('dialog.qq-dlg .qq-btn.go');
    await page.waitForSelector('.qq-chip.live:has-text("Live")');
    await shot(page, 'mode-live', w);

    // Back to Off (one confirmed click), then Internal testing.
    await page.click('[data-qq-mode="off"]');
    await page.waitForSelector('dialog.qq-dlg');
    await shot(page, 'mode-off-confirm', w);
    await page.click('dialog.qq-dlg .qq-btn.danger');
    await page.waitForSelector('.qq-chip.off');
    await shot(page, 'mode-off', w);
    await page.click('[data-qq-mode="testing"]');
    await page.waitForSelector('dialog.qq-dlg');
    await page.click('dialog.qq-dlg .qq-btn.pri');
    await page.waitForSelector('.qq-hero .qq-chip.testing');
    await shot(page, 'mode-testing', w);
    await page.evaluate(() => document.querySelectorAll('.qq-toast button').forEach((b) => b.click()));

    // Store -> Orders: tick, the bar, the sheet.
    await page.evaluate(() => window.go('orders'));
    await page.waitForSelector('[data-olsel]');
    const n = await page.$$eval('#content [data-olsel]', (b) => b.length);
    for (let i = 0; i < n; i++) await page.locator('#content [data-olsel]').nth(i).click();
    await page.waitForSelector('[data-qqo-open]');
    await shot(page, 'orders-selection-bar', w);

    await page.click('[data-qqo-open]');
    await page.waitForSelector('.qqo-row');
    await shot(page, 'sheet-review', w);
    M['sheet-rows-' + w] = await page.$$eval('.qqo-row', (rs) => rs.length);

    await page.click('[data-qqo-go]');
    await page.waitForSelector('[data-qqo-close].pri', { timeout: 30000 });
    await shot(page, 'sheet-results', w);
    await page.click('.qqo-foot [data-qqo-close]');

    // The Delivery card on a booked order.
    await page.evaluate(() => window.go('orders'));
    await page.waitForSelector('[data-olview]');
    await page.locator('[data-olview]').nth(4).click();
    await page.waitForSelector('[data-qqo-card]', { timeout: 15000 });
    await page.evaluate(() => document.querySelector('[data-qqo-card]').scrollIntoView());
    await shot(page, 'order-delivery-card', w);

    // Back to the start state for the next width.
    await page.evaluate(async () => {
      const x = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
      await fetch(location.pathname.replace(/\/[^/]*$/, '') + '/admin-api/quiqup-mode', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(x[1]) }, body: JSON.stringify({ mode: 'testing', confirm: true }) });
    });
    M['console-errors-' + w] = errors;
    await ctx.close();
  }
  await browser.close();
  const mf = path.join(OUT, 'MEASUREMENTS.json');
  let prev = {}; try { prev = JSON.parse(fs.readFileSync(mf, 'utf8')); } catch (e) {}
  fs.writeFileSync(mf, JSON.stringify(Object.assign(prev, M), null, 2));
  console.log(JSON.stringify(M, null, 2));
})().catch((e) => { console.error(e); process.exit(1); });
