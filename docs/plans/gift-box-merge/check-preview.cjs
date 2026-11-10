// Checks preview.html: no horizontal scroll at 390/1280, no console errors, the clocks tick.
// NODE_PATH=/home/user/kbbstore/node_modules node docs/plans/gift-box-merge/check-preview.cjs
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    const errs = [];
    p.on('pageerror', (e) => errs.push(e.message));
    p.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
    await p.goto('file://' + path.join(__dirname, 'preview.html'));
    const c1 = await p.textContent('[data-gbm-clk]');
    await p.waitForTimeout(2200);
    const c2 = await p.textContent('[data-gbm-clk]');
    console.log(w, 'scrollWidth', await p.evaluate(() => document.documentElement.scrollWidth), c1, '->', c2, errs);
    await p.screenshot({ path: path.join(__dirname, 'shots', `preview-page-${w}.png`), fullPage: w === 1280 });
  }
  await b.close();
})();
