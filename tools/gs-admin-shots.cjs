/* Lane GS: Growth & Marketing -> Free gifts -> Gift products -> the inline GIFT STOCK, at 1280 and 390.
   Types a gift stock (Enter), then +, then −, then "Use shop stock", reading the row after each: one request per
   change, the shop stock never moves, the gift stock shows in the row's badge. Measures the controls (clickable on
   the first try via elementFromPoint), scrollWidth, console errors.
   sh tools/fg-preview.sh 11640
   BASE=http://127.0.0.1:11640 node tools/gs-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-gs');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch();
  const report = [];
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = []; let writes = 0;
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('/gift-stock')) writes++; });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('freegifts'));
    await page.waitForSelector('.fg-tabs button, [role=tab]', { timeout: 20000 }).catch(() => {});
    await page.locator('button', { hasText: 'Gift products' }).first().click();
    await page.waitForSelector('[data-fk^="gs-"]', { timeout: 20000 });
    await page.waitForTimeout(400);
    const first = await page.evaluate(() => { const i = document.querySelector('input[data-fk^="gs-"]'); return { id: i.getAttribute('data-fk').slice(3), ph: i.placeholder, name: i.getAttribute('aria-label') }; });
    const row = () => page.evaluate((id) => {
      const i = document.querySelector('input[data-fk="gs-' + id + '"]'), r = i.closest('.fg-pr');
      const hit = (el) => { const b = el.getBoundingClientRect(), h = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return h === el || el.contains(h); };
      const [minus, plus] = r.querySelectorAll('.fg-gs-b');
      return { value: i.value, badge: (r.querySelector('.pill') || {}).textContent, off: !!r.querySelector('.fg-gs-off'), note: (r.querySelector('.fg-gs-note') || {}).textContent || '',
        clickable: hit(i) && hit(plus) && (minus.disabled || hit(minus)), h: Math.round(i.getBoundingClientRect().height), scrollW: document.documentElement.scrollWidth };
    }, first.id);
    const shot = async (n) => { const r = page.locator('input[data-fk="gs-' + first.id + '"]').locator('xpath=ancestor::div[contains(@class,"fg-pr")]'); await r.scrollIntoViewIfNeeded(); await page.screenshot({ path: `${OUT}/gs-${n}-${w}.png`, fullPage: false }); };
    const before = await row(); await shot('1-before');
    await page.fill('input[data-fk="gs-' + first.id + '"]', '6'); await page.press('input[data-fk="gs-' + first.id + '"]', 'Enter');
    await page.waitForFunction((id) => document.querySelector('input[data-fk="gs-' + id + '"]')?.value === '6' && document.querySelector('.fg-gs-off'), first.id, { timeout: 15000 });
    const set6 = await row(); await shot('2-set-6');
    await page.click('input[data-fk="gs-' + first.id + '"] + .fg-gs-b');
    await page.waitForFunction((id) => document.querySelector('input[data-fk="gs-' + id + '"]')?.value === '7', first.id, { timeout: 15000 });
    const plus = await row();
    await page.locator('input[data-fk="gs-' + first.id + '"]').locator('xpath=preceding-sibling::button').click();
    await page.waitForFunction((id) => document.querySelector('input[data-fk="gs-' + id + '"]')?.value === '6', first.id, { timeout: 15000 });
    const minus = await row(); await shot('3-after-plus-minus');
    await page.click('[data-fk="gs-off-' + first.id + '"]');
    await page.waitForFunction((id) => document.querySelector('input[data-fk="gs-' + id + '"]')?.value === '', first.id, { timeout: 15000 });
    const back = await row(); await shot('4-back-to-shop');
    report.push({ w, product: first.name, placeholder: first.ph, before, set6, plus, minus, back, writes, errors });
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
