/*
 * Lane ER -- Growth & Marketing → Marketing Emails → Reports, photographed and
 * measured at 1280 and 390.
 *
 *   sh tools/er-preview.sh            (prints the port)
 *   node tools/er-shots.cjs <port>
 *
 * Writes docs/lane-er-shots/*.png and docs/lane-er-shots/measurements.json.
 * Measured by the harness (the screen measures nothing): scrollWidth vs
 * clientWidth, the KPI number size, bar counts, console errors, and the Open
 * tracking switch before and after.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '10460';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-er-shots');
fs.mkdirSync(OUT, { recursive: true });

const measure = (page) => page.evaluate(() => {
  const k = document.querySelector('.mkr-kpis .mke-kpi b');
  const content = document.querySelector('#content');
  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    contentScrollWidth: content ? content.scrollWidth : null,
    contentClientWidth: content ? content.clientWidth : null,
    kpiFont: k ? getComputedStyle(k).fontSize : null,
    kpis: document.querySelectorAll('.mkr-kpis .mke-kpi').length,
    bars: document.querySelectorAll('.mkr-col').length,
    rows: document.querySelectorAll('.mke-tbl tbody tr').length,
    title: (document.querySelector('#ptitle') || {}).textContent || null,
  };
});

async function run(browser, w, h) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + JSON.stringify(m.location())); });
  page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
  page.on('requestfailed', (r) => errors.push('failed ' + r.url()));
  await page.goto(`${BASE}/admin/login`);
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit]')]);
  await page.waitForTimeout(600);
  const out = { width: w };
  const tall = async (px) => page.setViewportSize({ width: w, height: px });

  await page.evaluate(() => window.go('mkt-email'));
  await page.waitForSelector('#mket_main_reports');
  await page.click('#mket_main_reports');
  await page.waitForSelector('[data-mke-r]');
  await page.waitForTimeout(250);
  out.list = await measure(page);
  await page.screenshot({ path: path.join(OUT, `${w}-1-reports-list.png`), fullPage: true });

  await page.click('[data-mke-r]');
  await page.waitForSelector('[data-mkr-pane]');
  await page.waitForTimeout(300);
  await tall(w < 600 ? 3300 : 2100);
  out.summary = await measure(page);
  await page.screenshot({ path: path.join(OUT, `${w}-2-report-summary.png`), fullPage: true });

  await page.click('[data-mkr-pane="links"]');
  await page.waitForTimeout(200);
  out.links = await measure(page);
  await tall(w < 600 ? 1900 : 1300);
  await page.screenshot({ path: path.join(OUT, `${w}-3-links-products.png`), fullPage: true });

  await page.click('[data-mkr-pane="people"]');
  await page.waitForSelector('.mkr-pager');
  await page.waitForTimeout(200);
  await tall(w < 600 ? 2600 : 1900);
  out.people = await measure(page);
  await page.screenshot({ path: path.join(OUT, `${w}-4-recipients.png`), fullPage: true });

  // Search and filter: Enter in the box, then the "Ordered" filter.
  await page.selectOption('[data-mkr-form] select', 'ordered');
  await page.click('[data-mkr-form] button[type=submit]');
  await page.waitForTimeout(500);
  out.ordered = await page.evaluate(() => (document.querySelector('.mkr-pager span') || {}).textContent);
  await tall(w < 600 ? 1500 : 1100);
  await page.screenshot({ path: path.join(OUT, `${w}-5-recipients-ordered.png`), fullPage: true });

  if (w === 1280) {
    // The CSV: an ordinary download for the owner.
    const [dl] = await Promise.all([page.waitForEvent('download'), page.click('[data-mkr-csv]')]);
    const file = await dl.path();
    const csv = fs.readFileSync(file, 'utf8');
    out.csv = { name: dl.suggestedFilename(), lines: csv.trim().split('\n').length, header: csv.split('\n')[0] };

    // Open tracking: before and after the switch.
    await page.click('[data-mke="reports"]');
    await page.waitForSelector('[data-mkr-track]');
    await tall(900);
    out.switchBefore = await page.evaluate(() => ({ checked: document.querySelector('[data-mkr-track]').checked, text: document.querySelector('.mkr-switch .mke-h').textContent }));
    await page.screenshot({ path: path.join(OUT, `${w}-6-open-tracking-on.png`), clip: { x: 0, y: 0, width: w, height: 420 } });
    await page.click('[data-mkr-track]');
    await page.waitForTimeout(600);
    out.switchAfter = await page.evaluate(() => ({ checked: document.querySelector('[data-mkr-track]').checked, text: document.querySelector('.mkr-switch .mke-h').textContent }));
    await page.screenshot({ path: path.join(OUT, `${w}-7-open-tracking-off.png`), clip: { x: 0, y: 0, width: w, height: 420 } });
    await page.click('[data-mkr-track]');
    await page.waitForTimeout(600);
  }

  out.errors = errors;
  await ctx.close();
  return out;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const results = [await run(browser, 1280, 900), await run(browser, 390, 844)];
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'measurements.json'), JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results, null, 2));
})();
