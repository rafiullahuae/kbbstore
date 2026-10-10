/* Lane RL: Catalog -> Reorder on a parent that lists its sub-categories, at 1280 and 390.
   BASE=http://127.0.0.1:PORT OUT=docs/lane-rl-shots node tools/rl-shots.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT;
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const w of [1280, 390]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 } })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com'); await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('catalog', 'reorder'));
    await page.waitForSelector('#rePerPage', { timeout: 30000 });
    await page.selectOption('#reCat', '1'); await page.waitForTimeout(2500);
    const m = await page.evaluate(() => ({ head: (document.querySelector('#catBody .toolbar span:last-child') || {}).innerText, rows: document.querySelectorAll('#rlist .ritem').length, sw: document.documentElement.scrollWidth }));
    await page.screenshot({ path: `${OUT}/reorder-parent-${w}.png` });
    out.push({ w, ...m, errors });
  }
  console.log(JSON.stringify(out));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
