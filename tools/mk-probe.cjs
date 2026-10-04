// Lane MK — quick probe: log in, open a Marketing Emails view, report errors and widths.
const { chromium } = require('playwright');
const BASE = process.env.MK_BASE || ('http://127.0.0.1:' + require('fs').readFileSync(__dirname + '/../storage/framework/testing/mk-preview/port', 'utf8').trim());
const [,, step = 'campaigns', width = '1280', out = ''] = process.argv;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const w = parseInt(width, 10);
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1100 : 844 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  page.on('pageerror', e => console.log('pageerror', String(e)));
  page.on('console', m => { if (m.type() === 'error') console.log('console', m.text()); });
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.evaluate(() => window.go('mkt-email'));
  await page.waitForTimeout(1500);
  if (step !== 'campaigns') {
    await page.evaluate((s) => { const t = document.querySelector('[data-mke-tab="' + s + '"]'); if (t) t.click(); }, step);
    await page.waitForTimeout(2500);
  }
  const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, text: (document.querySelector('#content') || {}).innerText?.slice(0, 600) }));
  console.log(JSON.stringify(m));
  if (out) await page.screenshot({ path: out, fullPage: true });
  await b.close();
})();
