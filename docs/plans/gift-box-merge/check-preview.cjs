// Checks preview.html: no horizontal scroll at 390/1280, no console errors, the clocks tick,
// and (owner, 15 Nov) when a clock reaches zero the bonus leaves the box with NO trace:
// no bonus element, no "expired"/"Ended" text, and the box ends exactly as tall as the
// static expired box (a box that never had a bonus) -- so no gap is left behind.
// NODE_PATH=/home/user/kbbstore/node_modules node docs/plans/gift-box-merge/check-preview.cjs
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const CHROME = process.env.GB_CHROME || ['/opt/pw-browsers/chromium-1243/chrome-linux64/chrome', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'].find((f) => fs.existsSync(f));
(async () => {
  const b = await chromium.launch({ executablePath: CHROME });
  let bad = 0;
  for (const w of [390, 1280]) {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    const errs = [];
    p.on('pageerror', (e) => errs.push(e.message));
    p.on('console', (m) => m.type() === 'error' && errs.push(m.text()));
    await p.goto('file://' + path.join(__dirname, 'preview.html'));
    const c1 = await p.textContent('[data-gbm-clk]');
    await p.waitForTimeout(2200);
    const c2 = await p.textContent('[data-gbm-clk]');
    await p.waitForTimeout(9500); // the "watch it end" boxes start at 10 s
    const end = await p.evaluate(() => [...document.querySelectorAll('section')].map((s) => {
      const demo = s.querySelector('fieldset[data-gbm-demo]'), stat = s.querySelectorAll('.pv')[2].querySelector('fieldset');
      return { h2: s.querySelector('h2').textContent.slice(0, 1), bonusLeft: demo.querySelectorAll('[data-gbm-bonus], [role=timer]').length,
        words: /expired|ended/i.test(demo.textContent + stat.textContent), demoH: Math.round(demo.getBoundingClientRect().height), staticH: Math.round(stat.getBoundingClientRect().height) };
    }));
    const sw = await p.evaluate(() => document.documentElement.scrollWidth);
    const ok = sw === w && !errs.length && c1 !== c2 && end.every((e) => !e.bonusLeft && !e.words && e.demoH === e.staticH);
    if (!ok) bad++;
    console.log(w, ok ? 'PASS' : 'FAIL', 'scrollWidth', sw, 'clock', c1, '->', c2, 'errors', errs, 'ended', JSON.stringify(end));
    await p.screenshot({ path: path.join(__dirname, 'shots', `preview-page-${w}.png`), fullPage: w === 1280 });
  }
  await b.close();
  process.exit(bad ? 1 : 0);
})();
