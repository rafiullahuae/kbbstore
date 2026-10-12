/* Lane CPL: does Appearance -> Cart panel -> Mobile -> Panel width save? BASE=... SCREEN=... node tools/cpw-admin.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, SCREEN = process.env.SCREEN;
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
  const posts = []; page.on('request', (r) => { if (r.method() !== 'GET' && /cart-panel/.test(r.url())) posts.push(r.method() + ' ' + r.url().replace(BASE, '') + ' ' + (r.postData() || '').slice(0, 400)); });
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.evaluate((s) => window.go(s), SCREEN);
  await page.waitForTimeout(2000);
  await page.getByRole('tab', { name: 'Mobile', exact: true }).first().click().catch(async () => { await page.getByText('Mobile', { exact: true }).first().click(); });
  await page.waitForTimeout(800);
  const slider = page.locator('input[type=range]').first();
  const before = await slider.inputValue();
  await slider.evaluate((el) => { el.value = '72'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
  await page.getByRole('button', { name: 'Save', exact: true }).first().click();
  await page.waitForTimeout(2000);
  console.log(JSON.stringify({ before, label: await page.locator('input[type=range]').first().evaluate((el) => el.closest('[class]')?.innerText?.slice(0, 80)), posts, errors }));
  await browser.close();
})();
