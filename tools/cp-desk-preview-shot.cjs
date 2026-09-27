/* The Desktop tab's preview, which sits BELOW the controls at full width. */
const { chromium } = require('playwright');
(async () => {
  const [out, w] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: +w, height: 900 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  await p.goto('http://127.0.0.1:8957/admin/login', { waitUntil: 'networkidle' });
  await p.fill('input[name=email]', 'owner@preview.test');
  await p.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.click('button[type=submit]')]);
  await p.goto('http://127.0.0.1:8957/admin', { waitUntil: 'networkidle' });
  await p.waitForTimeout(900);
  await p.evaluate(() => window.go('cartpanel'));
  await p.waitForTimeout(1500);
  await p.click('[data-cpp-tab="desktop"]');
  await p.waitForTimeout(600);
  const card = await p.$('[data-cpp-preview]');
  await card.scrollIntoViewIfNeeded();
  await p.waitForTimeout(300);
  await card.screenshot({ path: out });
  console.log(JSON.stringify(await p.evaluate(() => ({
    ruler: document.querySelector('.cpv-ruler').textContent,
    panelDrawnWidth: Math.round(document.querySelector('.cpv-panel').getBoundingClientRect().width),
  })), null, 1));
  await b.close();
})();
