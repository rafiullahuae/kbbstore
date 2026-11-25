/* Lane GST: Owner app -> Free gifts -> Gift stock, at 390 (and the same app at 1280): the section, then + on a
   product (one request, the shop stock never moves), a typed number + Enter, and "Use shop stock". Measures the
   controls (clickable on the first try via elementFromPoint), scrollWidth, console errors.
   sh tools/oag-preview.sh   # prints the port and the owner-app path
   BASE=http://127.0.0.1:<port> APP=/<owner-app-path> node tools/gstock-oa-shots.cjs */
const fs = require('node:fs'), path = require('node:path');
const { chromium } = require('playwright');
const BASE = process.env.BASE, APP = process.env.APP, OUT = path.resolve(__dirname, '../docs/lane-gstock');
fs.mkdirSync(OUT, { recursive: true });
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

(async () => {
  const browser = await chromium.launch();
  const report = [];
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1, userAgent: w === 390 ? IPHONE : undefined, isMobile: w === 390, hasTouch: w === 390 });
    await ctx.addInitScript(() => { delete Element.prototype.requestFullscreen; Object.defineProperty(Document.prototype, 'fullscreenEnabled', { get: () => false }); });
    const page = await ctx.newPage();
    const errors = []; let writes = 0;
    page.on('pageerror', (e) => errors.push('pageerror ' + e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push('console ' + m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('/gift-stock')) writes++; });
    await page.goto(BASE + APP + '/', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-enrol]');
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=pin]', '482615');
    await page.fill('input[name=device_name]', 'gst-' + w);
    await Promise.all([page.waitForResponse((r) => r.url().endsWith('/api/enrol')), page.click('[data-enrol] button[type=submit]')]);
    await page.waitForTimeout(1500);
    await page.keyboard.press('Escape');
    await page.evaluate(() => { location.hash = '#/gifts'; });
    await page.waitForSelector('[data-gstock] [data-gsi]', { timeout: 20000 });
    await page.waitForTimeout(500);
    const id = await page.evaluate(() => document.querySelector('[data-gstock] [data-gsi]').getAttribute('data-gsi'));
    const row = () => page.evaluate((id) => {
      const r = document.querySelector('[data-gsrow="' + id + '"]'), i = r.querySelector('[data-gsi]'), [m, p] = r.querySelectorAll('[data-gsd]');
      const hit = (el) => { const b = el.getBoundingClientRect(), h = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return h === el || el.contains(h); };
      return { value: i.value, placeholder: i.placeholder, pill: r.querySelector('.pill').textContent, off: !!r.querySelector('[data-gso]'), clickable: hit(i) && hit(p) && (m.disabled || hit(m)), scrollW: document.documentElement.scrollWidth };
    }, id);
    const shot = async (n) => { await page.locator('[data-gsrow="' + id + '"]').scrollIntoViewIfNeeded(); await page.waitForTimeout(250); await page.screenshot({ path: `${OUT}/oa-${n}-${w}.png` }); };
    const before = await row(); await shot('1-gift-stock-section');
    await page.click('[data-gsrow="' + id + '"] [data-gsd][data-d="1"]');
    await page.waitForFunction((id) => document.querySelector('[data-gsi="' + id + '"]')?.value === '1', id, { timeout: 15000 });
    const plus = await row();
    await page.fill('[data-gsi="' + id + '"]', '12'); await page.press('[data-gsi="' + id + '"]', 'Enter');
    await page.waitForFunction((id) => document.querySelector('[data-gsi="' + id + '"]')?.value === '12', id, { timeout: 15000 });
    const typed = await row(); await shot('2-gift-stock-12');
    await page.click('[data-gso="' + id + '"]');
    await page.waitForFunction((id) => document.querySelector('[data-gsi="' + id + '"]')?.value === '', id, { timeout: 15000 });
    const back = await row(); await shot('3-back-to-shop');
    report.push({ w, before, plus, typed, back, writes, errors });
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'oa-report.json'), JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
})().catch((e) => { console.error(e); process.exit(1); });
