const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8983';

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
  await page.waitForTimeout(900);
  // The Orders screen first, then open the row: renderOrderDetail() is the
  // SPA's own entry point for the drawer and is not exported on window, so it
  // is reached by pressing the row's own View button, as an operator does.
  await page.evaluate(() => window.go('orders'));
  await page.waitForTimeout(1800);
  const opened = await page.evaluate(id => {
    const b = document.querySelector('[data-olview="' + id + '"]');
    if (!b) return false;
    b.click();
    return true;
  }, orderId);
  if (!opened) { console.log('COULD NOT FIND THE ROW BUTTON for order ' + orderId); }
  await page.waitForTimeout(2400);

  const m = await page.evaluate(() => {
    const txt = (s) => { const e = document.querySelector(s); return e ? e.innerText.trim() : null; };
    const btn = document.getElementById('odCaptureGo');
    // The money panel is the block that mentions capture.
    const panel = [...document.querySelectorAll('div')]
      .filter(d => /captur/i.test(d.innerText || '') && d.children.length === 0)
      .pop()
      || [...document.querySelectorAll('div')]
          .filter(d => /capturable|Not captured|Captured/i.test(d.innerText || ''))
          .sort((a,b) => a.innerText.length - b.innerText.length)[0];
    return {
      orderHeading: (document.querySelector('#content h1, #content h2, .odhead, #odTitle') || {}).innerText || null,
      bodyHasOrderNumber: /O1-RELEASED/.test(document.body.innerText),
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      captureButtonPresent: !!btn,
      captureButtonLabel: btn ? btn.innerText.trim() : null,
      captureButtonBox: btn ? (() => { const r = btn.getBoundingClientRect(); return {w: Math.round(r.width), h: Math.round(r.height)}; })() : null,
      moneyPanelText: panel ? panel.innerText.replace(/\n/g, ' | ').slice(0, 300) : null,
      moneyPanelFontSize: panel ? Math.round(parseFloat(getComputedStyle(panel).fontSize)) : null,
      refundPanelPresent: !!document.getElementById('odRefundGo'),
    };
  });

  console.log(JSON.stringify(m, null, 1));
  await page.screenshot({ path: out, fullPage: true });
  await browser.close();
})();
