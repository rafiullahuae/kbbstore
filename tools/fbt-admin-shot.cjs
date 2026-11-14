/*
 * Lane FBT: Appearance -> Product page -> Buy these together, at 1280, as the
 * owner sees it. FBT_BASE / FBT_OUT / FBT_TAG as tools/fbt-shots.cjs.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.FBT_BASE || 'http://127.0.0.1:9347';
const OUT = process.env.FBT_OUT || 'docs/plans/frequently-bought-together/shots';
const TAG = process.env.FBT_TAG || 'after';
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  const browser = await chromium.launch({ executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const page = await browser.newPage({ viewport: { width: 1280, height: 1400 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.evaluate(() => window.renderProductPage());
  await page.waitForSelector('[data-btptab]', { timeout: 15000 });
  await page.click('[data-btptab]');
  await page.waitForTimeout(800);
  await page.screenshot({ path: path.join(OUT, `${TAG}-admin-1280.png`) });
  const labels = await page.$$eval('#content .mmbody', (els) => els.map((e) => e.innerText.split('\n').filter(Boolean).slice(0, 40)));
  console.log(JSON.stringify({ errors, labels: labels[0] }, null, 1));
  await browser.close();
})();
