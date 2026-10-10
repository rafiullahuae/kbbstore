/* Lane OR: the admin sidebar (Orders fourth) and Store -> Cart Tracking with
   browser + device, at 1280 and 390, measured.
   BASE=http://127.0.0.1:PORT OUT=docs/lane-or-shots node tools/or-shots.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT;
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const w of [1280, 390]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 } })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e))); page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com'); await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    const top = await page.evaluate(() => [...document.querySelectorAll('#nav > .nav-item, #nav .nav-flat .nav-item, .side .nav-item')].slice(0, 5).map((b) => (b.dataset.go || '') + ':' + b.innerText.trim()));
    if (w === 390) { await page.evaluate(() => document.querySelector('#side')?.classList.add('open')); await page.waitForTimeout(400); }
    await page.screenshot({ path: `${OUT}/sidebar-${w}.png` });
    const hit = await page.evaluate(() => { const b = document.querySelector('.side .nav-item[data-go="orders"]'); if (!b) return null; const r = b.getBoundingClientRect(); const at = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2); return at === b || b.contains(at); });
    await page.evaluate(() => window.go('orders')); await page.waitForTimeout(1500);
    const ordersTitle = await page.evaluate(() => [document.querySelector('#crumb')?.textContent, document.querySelector('#ptitle')?.textContent, document.querySelector('.side .nav-item.on')?.dataset.go]);
    await page.evaluate(() => window.go('carttracking'));
    await page.waitForSelector('.ctk-agent', { timeout: 20000 });
    const m = await page.evaluate(() => ({
      agents: [...document.querySelectorAll('.ctk-agent')].slice(0, 4).map((e) => e.textContent),
      tiles: [...document.querySelectorAll('.ctk-tile')].map((t) => { const r = t.getBoundingClientRect(); return t.querySelector('.k').textContent + ' ' + Math.round(r.width) + 'x' + Math.round(r.height); }),
      sw: document.documentElement.scrollWidth, vw: document.documentElement.clientWidth }));
    await page.screenshot({ path: `${OUT}/cart-tracking-${w}.png` });
    out.push({ w, top, ordersClickable: hit, ordersTitle, ...m, errors });
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
