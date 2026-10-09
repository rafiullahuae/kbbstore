/*
 * Lane QK: where the first slide's text box ends against the bottom of the
 * banner, and where the slider bars are -- px from the frame's bottom edge.
 * Harness only; nothing here reaches the shop.
 *   QK_BASE=http://127.0.0.1:10530 QK_TAG=before QK_SHOTS=dir node tools/qk-banner-bottom.cjs
 */
const { chromium } = require('playwright');
const BASE = process.env.QK_BASE, TAG = process.env.QK_TAG || 'x', SHOTS = process.env.QK_SHOTS || '';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of (process.env.QK_WIDTHS || '390,1280').split(',').map(Number)) {
    const page = await b.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    const r = await page.evaluate(() => {
      const vp = document.querySelector('.kbbs-vp').getBoundingClientRect();
      const box = document.querySelector('.kbbs-s .hb-box');
      const bx = box ? box.getBoundingClientRect() : null;
      const bar = document.querySelector('.kbbs-bar');
      const line = document.querySelector('.kbbs-line');
      const btn = box && box.querySelector('.hb-btn');
      let hit = null;
      if (btn) { const c = btn.getBoundingClientRect(); const e = document.elementFromPoint(c.left + c.width / 2, c.top + c.height / 2); hit = !!(e && btn.contains(e)); }
      let barHit = null;
      if (bar) { const c = bar.getBoundingClientRect(); const e = document.elementFromPoint(c.left + c.width / 2, c.top + c.height / 2); barHit = !!(e && bar.contains(e)); }
      const up = (y) => Math.round((vp.bottom - y) * 10) / 10;
      return {
        frameH: Math.round(vp.height), cls: document.querySelector('.kbbs').className,
        boxBottomGap: bx ? up(bx.bottom) : null, boxTop: bx ? Math.round((bx.top - vp.top) * 10) / 10 : null,
        barHitTop: bar && bar.offsetParent ? up(bar.getBoundingClientRect().top) : null,
        barLineTop: line && line.offsetParent ? up(line.getBoundingClientRect().top) : null,
        btnClick: hit, barClick: barHit, scrollWidth: document.documentElement.scrollWidth,
      };
    });
    console.log(JSON.stringify({ tag: TAG, width, ...r, errors }));
    if (SHOTS) {
      const frame = await page.$('.kbbs');
      await frame.screenshot({ path: `${SHOTS}/${TAG}-${width}.png` });
    }
    await page.close();
  }
  await b.close();
})();
