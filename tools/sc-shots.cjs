/*
 * Lane SC: Catalog -> Shop by Concern, at 1280 and 390, before and after
 * Sync (rule-based, no key saved), the settings panel, and after Apply.
 *   node tools/sc-shots.cjs <out-dir> [port] [1280|390]
 * Apply changes the database, so run each width on a fresh preview.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const [out, port = '9660', only = ''] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;

async function run(browser, w, h) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const errors = [];
  const requests = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url().replace(BASE, '')); });
  page.on('request', (r) => { if (r.url().includes('/admin-api/concern-sync')) requests.push(r.method() + ' ' + r.url().replace(BASE, '')); });

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.evaluate(() => window.go('concernsync'));
  await page.waitForSelector('[data-csy-sync]');
  await page.screenshot({ path: `${out}/sc-${w}-1-open.png` });

  await page.click('[data-csy-sync]');
  await page.waitForSelector('.csy-p');
  await page.screenshot({ path: `${out}/sc-${w}-2-synced.png` });
  await page.screenshot({ path: `${out}/sc-${w}-2-synced-full.png`, fullPage: true });

  // Untick one suggestion, as the owner would.
  const firstTicked = page.locator('.csy-p input[type=checkbox]:checked').first();
  await firstTicked.uncheck();
  const footer = await page.locator('.csy-foot span').innerText();

  await page.click('[data-csy-apply]');
  await page.waitForSelector('.csy-msg');
  await page.screenshot({ path: `${out}/sc-${w}-3-applied.png` });

  await page.evaluate(() => { document.querySelector('details.csy-set').open = true; document.querySelector('details.csy-set').scrollIntoView(); });
  await page.screenshot({ path: `${out}/sc-${w}-4-settings.png` });

  const m = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    cards: document.querySelectorAll('.csy-p').length,
    message: (document.querySelector('.csy-msg') || {}).textContent,
    keyType: document.getElementById('csyKey').type,
    keyAutocomplete: document.getElementById('csyKey').getAttribute('autocomplete'),
    chipFont: getComputedStyle(document.querySelector('.csy-chip') || document.body).fontSize,
  }));
  await ctx.close();
  return { width: w, footerBeforeApply: footer, ...m, requests, errors };
}

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = [];
  if (only !== '390') results.push(await run(browser, 1280, 900));
  if (only !== '1280') results.push(await run(browser, 390, 844));
  fs.writeFileSync(`${out}/measurements-${only || 'all'}.json`, JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results, null, 2));
  await browser.close();
})();
