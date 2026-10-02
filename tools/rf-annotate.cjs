/*
 * Lane RF — the laptop product page annotated: what Desktop sections can move
 * (the four full-width blocks) and what stays put (the two columns, and the
 * blocks inside the buy column). Outlines and labels are drawn by THIS harness
 * onto a screenshot of the real page; nothing here ships.
 *
 *   RF_BASE=http://127.0.0.1:9860 node tools/rf-annotate.cjs
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.RF_BASE || 'http://127.0.0.1:9860';
const CHROME = process.env.RF_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.goto(`${BASE}/product/pdp-heartleaf-toner/`, { waitUntil: 'networkidle' });
  await page.evaluate(() => {
    const mark = (sel, label, colour, inset) => {
      document.querySelectorAll(sel).forEach((e) => {
        e.style.outline = `3px ${inset ? 'dashed' : 'solid'} ${colour}`;
        e.style.outlineOffset = inset ? '-3px' : '2px';
        e.style.position = e.style.position || 'relative';
        const t = document.createElement('div');
        t.textContent = label;
        t.style.cssText = `position:absolute;top:4px;inset-inline-end:4px;z-index:50;background:${colour};color:#fff;font:700 12px/1.3 system-ui;padding:4px 8px;border-radius:6px;pointer-events:none;max-width:60%`;
        e.appendChild(t);
      });
    };
    mark('.pdp-page > .pdp', 'FIXED: photo + buy column stay on top', '#475569');
    mark('.buybox .pm-sec, .buybox .pts-del, .buybox .pts-stack', 'inside the buy column: not reordered on laptop', '#94a3b8', true);
    mark('.pdp-page > .kbb-fbt', 'MOVABLE 1 · Buy these together', '#E0567B');
    mark('.pdp-page > .pm-details', 'MOVABLE 2 · Product details + tabs', '#E0567B');
    mark('.pdp-page > .sr', 'MOVABLE 3 · Reviews', '#E0567B');
    mark('.pdp-page > .ymal', 'MOVABLE 4 · You may also like', '#E0567B');
  });
  await page.screenshot({ path: path.join(__dirname, '..', 'docs', 'rf-shots', 'desktop-annotated-1280.png'), fullPage: true });
  await browser.close();
})();
