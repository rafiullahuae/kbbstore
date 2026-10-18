/*
 * Lane BT: Appearance -> Site layout -> Large phone text, at 1280 and 390.
 * Against a tools/arr-preview.sh server (preview owner login, preview DB only).
 *   BASE=http://127.0.0.1:<port> node tools/bt-admin-shots.cjs
 */
const fs = require('fs');
const path = require('path');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const BASE = process.env.BASE || 'http://127.0.0.1:9732';
const OUT = path.join(__dirname, '..', 'docs', 'lane-bt');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const b = await pw.chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [1280, 390]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.evaluate(() => window.go('sitelayout'));
    await page.waitForSelector('[data-sls-tab="bigtext"]');
    await page.click('[data-sls-tab="bigtext"]');
    await page.waitForTimeout(500);
    const info = await page.evaluate(() => ({
      tabs: [...document.querySelectorAll('[data-sls-tab]')].map((t) => t.textContent.trim()),
      selected: (document.querySelector('[data-sls-tab][aria-selected="true"]') || {}).textContent,
      options: [...document.querySelectorAll('#content select option')].map((o) => o.textContent.trim()),
      scrollWidth: document.documentElement.scrollWidth,
    }));
    await page.screenshot({ path: path.join(OUT, `admin-large-phone-text-${w}.png`) });
    console.log(w, JSON.stringify({ ...info, errors }));
    await ctx.close();
  }
  await b.close();
})();
