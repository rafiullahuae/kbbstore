// Lane MK — probe a sub-view: node tools/mk-probe2.cjs <js expression> <width> <out.png>
const { chromium } = require('playwright');
const BASE = process.env.MK_BASE || ('http://127.0.0.1:' + require('fs').readFileSync(__dirname + '/../storage/framework/testing/mk-preview/port', 'utf8').trim());
const [,, expr, width = '1280', out = ''] = process.argv;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const w = parseInt(width, 10);
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 1100 : 844 } });
  const page = await ctx.newPage();
  page.on('pageerror', e => console.log('pageerror', String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.evaluate(() => window.go('mkt-email'));
  await page.waitForTimeout(800);
  await page.evaluate(expr);
  await page.waitForTimeout(3500);
  const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth }));
  console.log(JSON.stringify(m));
  if (out) await page.screenshot({ path: out, fullPage: true });
  await b.close();
})();
