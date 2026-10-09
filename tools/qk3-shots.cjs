/*
 * Lane QK3: the cart panel's coupon hint, photographed and measured.
 *
 *   sh tools/qk3-preview.sh 10970
 *   node tools/qk3-shots.cjs http://127.0.0.1:10970
 *
 * Writes docs/lane-qk3-shots/*.png and report.json. For each of 390 and 1280:
 * the open panel with the line, the "Copied" state after a REAL click on the
 * pill (and the line's box before/after, to prove nothing moved), a real click
 * on Checkout that navigates, elementFromPoint over both, the admin tab.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10970';
const OUT = path.join(__dirname, '..', 'docs', 'lane-qk3-shots');
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const SIZES = { 390: { width: 390, height: 844 }, 1280: { width: 1280, height: 860 } };
// The cart API answers real browsers only, so the contexts say they are one.
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const CTX = {
  390: { viewport: SIZES[390], deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE },
  1280: { viewport: SIZES[1280], deviceScaleFactor: 1, userAgent: MAC },
};

fs.mkdirSync(OUT, { recursive: true });

const box = (page, sel) => page.evaluate((s) => {
  const el = document.querySelector(s);
  if (!el) return null;
  const r = el.getBoundingClientRect();
  return { x: Math.round(r.x * 10) / 10, y: Math.round(r.y * 10) / 10, w: Math.round(r.width * 10) / 10, h: Math.round(r.height * 10) / 10 };
}, sel);

const hit = (page, sel) => page.evaluate((s) => {
  const el = document.querySelector(s);
  const r = el.getBoundingClientRect();
  const at = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
  return at === el || el.contains(at);
}, sel);

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};

  for (const size of Object.keys(SIZES)) {
    const ctx = await browser.newContext({ ...CTX[size], permissions: ['clipboard-read', 'clipboard-write'] });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('response', (res) => { if (res.status() >= 400) errors.push(res.status() + ' ' + new URL(res.url()).pathname + ' (on ' + new URL(page.url()).pathname + ')'); });

    await page.goto(BASE + '/product/barrier-repair-cream/', { waitUntil: 'networkidle' });
    await page.evaluate(async () => {
      for (const id of [20, 3]) {
        await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
      }
    });
    await page.goto(BASE + '/product/barrier-repair-cream/', { waitUntil: 'networkidle' });
    await page.click('[data-kbb-open="cart"]');
    await page.waitForTimeout(700);

    const r = { size: Number(size) };
    r.line = await box(page, '.kc-cch');
    r.pill = await box(page, '.kc-cc');
    r.subtotal = await box(page, '#kcCart .sumrow.tot');
    r.lineText = await page.evaluate(() => document.querySelector('.kc-cch')?.textContent);
    r.lineStyle = await page.evaluate(() => {
      const cs = getComputedStyle(document.querySelector('.kc-cch'));
      return { fontSize: cs.fontSize, lineHeight: cs.lineHeight, color: cs.color };
    });
    r.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    // How wide the sentence is on one line, against the room the panel gives it.
    r.naturalWidth = await page.evaluate(() => {
      const el = document.querySelector('.kc-cch');
      el.style.whiteSpace = 'nowrap'; el.style.width = 'max-content';
      const w = el.getBoundingClientRect().width;
      el.style.whiteSpace = ''; el.style.width = '';
      return Math.round(w * 10) / 10;
    });
    r.pillHit = await hit(page, '.kc-cc');
    r.checkoutHit = await hit(page, '#kcCart .cobtn');
    await page.screenshot({ path: path.join(OUT, `panel-${size}.png`) });

    await page.click('.kc-cc');
    await page.waitForTimeout(150);
    r.copiedClass = await page.evaluate(() => document.querySelector('.kc-cc').classList.contains('is-done'));
    r.clipboard = await page.evaluate(() => navigator.clipboard.readText().catch((e) => 'ERR ' + e));
    r.lineAfterCopy = await box(page, '.kc-cch');
    r.pillAfterCopy = await box(page, '.kc-cc');
    await page.screenshot({ path: path.join(OUT, `copied-${size}.png`) });
    const zoom = r.line;
    await page.screenshot({ path: path.join(OUT, `copied-${size}-zoom.png`), clip: { x: Math.max(0, zoom.x - 12), y: Math.max(0, zoom.y - 40), width: Math.min(SIZES[size].width - Math.max(0, zoom.x - 12), zoom.w + 24), height: zoom.h + 130 } });

    // The cart updates (a quantity step) and the line does not move.
    await page.waitForTimeout(1500);
    await page.click('#kcCart [data-kcq][data-d="1"]');
    await page.waitForTimeout(1200);
    r.lineAfterUpdate = await box(page, '.kc-cch');

    // A real click on Checkout navigates.
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('#kcCart .cobtn')]);
    r.checkoutNavigatedTo = new URL(page.url()).pathname;
    r.consoleErrors = errors;
    report[size] = r;

    // At 320px the line wraps to two lines at most.
    if (size === '390') {
      await page.setViewportSize({ width: 320, height: 700 });
      await page.goto(BASE + '/product/barrier-repair-cream/', { waitUntil: 'networkidle' });
      await page.click('[data-kbb-open="cart"]');
      await page.waitForTimeout(700);
      const b = await box(page, '.kc-cch');
      const lh = await page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.kc-cch')).lineHeight));
      report['320'] = { line: b, lines: Math.round(b.h / lh), scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) };
      await page.screenshot({ path: path.join(OUT, 'panel-320.png') });
    }
    await ctx.close();

    // The admin: Appearance -> Cart panel -> Coupon hint.
    const c3 = await browser.newContext(CTX[size]);
    const p3 = await c3.newPage();
    await p3.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await p3.fill('input[name=email]', 'owner@example.com');
    await p3.fill('input[name=password]', 'preview-password');
    await Promise.all([p3.waitForNavigation({ waitUntil: 'networkidle' }), p3.click('button[type=submit]')]);
    await p3.waitForTimeout(800);
    await p3.evaluate(() => window.go('cartpanel'));
    await p3.waitForSelector('[data-cpp-tab="coupon"]');
    await p3.click('[data-cpp-tab="coupon"]');
    await p3.waitForTimeout(500);
    report[size].adminOptions = await p3.evaluate(() => [...document.querySelectorAll('#cpp-coupon_id option')].map((o) => (o.selected ? '* ' : '  ') + o.textContent));
    await p3.screenshot({ path: path.join(OUT, `admin-${size}.png`), fullPage: true });
    await p3.evaluate(() => document.querySelector('[data-cpp-preview]').scrollIntoView({ block: 'end' }));
    await p3.waitForTimeout(300);
    await p3.screenshot({ path: path.join(OUT, `admin-${size}-preview.png`) });
    await c3.close();
  }

  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1) + '\n');
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
