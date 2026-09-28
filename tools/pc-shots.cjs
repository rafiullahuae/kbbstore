/* Lane PC: the Payment figures on an order, at two widths.
   Store → Orders → <order> → the Items card's totals block and the Captured
   panel beneath it. Measures rather than asserts (CLAUDE.md rules 2 and 4):
   the numbers the report quotes come out of here, including scrollWidth against
   the viewport so nothing here introduced a sideways scroll. */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8974';

(async () => {
  const [out, w, h, orderId] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await page.evaluate(() => window.go('orders'));
  await page.waitForTimeout(1800);
  await page.waitForSelector('[data-olview="' + orderId + '"]', { timeout: 15000 });
  await page.click('[data-olview="' + orderId + '"]');
  await page.waitForSelector('#odItems', { timeout: 15000 });
  await page.waitForTimeout(600);

  const m = await page.evaluate(() => {
    const card = document.querySelector('#odItems');
    const text = (el) => el ? el.textContent.replace(/\s+/g, ' ').trim() : null;
    const rows = [...card.querySelectorAll('.between')].map(text);
    const px = (e, p) => e ? Math.round(parseFloat(getComputedStyle(e)[p])) : null;
    const captured = [...card.querySelectorAll('div')].find(d => /^Captured · AED/.test(text(d) || ''));
    const refundable = [...card.querySelectorAll('span')].find(s => /still refundable/.test(text(s) || ''));
    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
      totalsRows: rows,
      capturedPanel: text(captured),
      capturedPanelHeight: captured ? Math.round(captured.getBoundingClientRect().height) : null,
      capturedPanelFontSize: px(captured, 'fontSize'),
      refundableLine: text(refundable),
      refundableFontSize: px(refundable, 'fontSize'),
      cardWidth: Math.round(card.getBoundingClientRect().width),
      latestNote: text(document.querySelector('#content .odcard:last-of-type')).slice(0, 260),
    };
  });

  console.log(JSON.stringify(m, null, 1));
  await page.locator('#odItems').screenshot({ path: out });
  await browser.close();
})();
