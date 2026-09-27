/* One picture of the tap-target warning: the four sliders that go below 44 and
   the line that appears under each of them when they do. */
const { chromium } = require('playwright');
(async () => {
  const [out, w] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: +w, height: 1100 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8957/admin/login', { waitUntil: 'networkidle' });
  await p.fill('input[name=email]', 'owner@preview.test');
  await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
  await p.goto('http://127.0.0.1:8957/admin', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  await p.evaluate(() => window.go('cartpanel'));
  await p.waitForTimeout(1500);
  await p.click('[data-cpp-tab="mobile"]');
  await p.waitForTimeout(600);
  const el = await p.$('#cpp-rm_tap_m');
  await el.scrollIntoViewIfNeeded();
  await p.waitForTimeout(300);
  console.log(JSON.stringify(await p.evaluate(() => ({
    warnings: [...document.querySelectorAll('.cpp-warn')].map((n) => n.textContent),
    values: ['rm_tap_m', 'x_size_m', 'tab_h_m', 'btn_h_m'].map((k) => k + '=' + document.querySelector('#cpp-' + k).value),
    mins: ['rm_tap_m', 'x_size_m', 'tab_h_m', 'btn_h_m'].map((k) => k + ' min=' + document.querySelector('#cpp-' + k).min),
  })), null, 1));
  await p.screenshot({ path: out });
  await b.close();
})();
