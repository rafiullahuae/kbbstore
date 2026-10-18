/*
 * "Keep the Arabic shop in Arabic" -- the admin row, and the Arabic page's
 * own head. Against a tools/arf-preview.sh server (preview DB only).
 *   BASE=http://127.0.0.1:<port> node tools/ka-shots.cjs
 */
const fs = require('fs');
const path = require('path');
let pw;
try { pw = require('playwright'); } catch (e) { pw = require(require('child_process').execSync('npm root -g').toString().trim() + '/playwright'); }
const BASE = process.env.BASE || 'http://127.0.0.1:9461';
const OUT = path.join(__dirname, '..', 'docs', 'lane-ka');
fs.mkdirSync(OUT, { recursive: true });
(async () => {
  const browser = await pw.chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.evaluate(() => window.go && window.go('tr-settings'));
    await page.waitForSelector('text=Keep the Arabic shop in Arabic', { timeout: 15000 });
    const row = page.locator('text=Keep the Arabic shop in Arabic').first();
    await row.scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, -160));
    await page.screenshot({ path: path.join(OUT, `admin-keep-arabic-${w}.png`) });
    await ctx.close();
  }
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  const r = {};
  for (const u of ['/ar/', '/']) {
    await page.goto(BASE + u, { waitUntil: 'domcontentloaded' });
    r[u] = await page.evaluate(() => ({ translate: document.documentElement.getAttribute('translate'), meta: !!document.querySelector('meta[name=google][content=notranslate]'), trEn: !!(window.KBB && window.KBB.trEn) }));
  }
  fs.writeFileSync(path.join(OUT, 'measure.json'), JSON.stringify(r, null, 2));
  console.log(JSON.stringify(r));
  await browser.close();
})();
