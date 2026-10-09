/*
 * Lane HB4: every part of the first slide's text box, as rectangles relative
 * to the banner frame, so two builds can be compared to the pixel; plus the
 * button's first-click and console errors. Harness only.
 *   HB_BASE=... HB_TAG=after node tools/hb4-rects.cjs
 */
const { chromium } = require('playwright');
const BASE = process.env.HB_BASE, TAG = process.env.HB_TAG || 'x';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of (process.env.HB_WIDTHS || '390,1280').split(',').map(Number)) {
    for (const path of (process.env.HB_PATHS || '/').split(',')) {
      const page = await b.newPage({ viewport: { width, height: 900 } });
      const errors = [];
      page.on('console', (m) => { if (m.type() === 'error' && !/favicon/.test(m.location().url || '')) errors.push(m.text()); });
      page.on('pageerror', (e) => errors.push(e.message));
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      await page.evaluate(() => document.fonts.ready);
      const r = await page.evaluate(() => {
        const vp = document.querySelector('.kbbs-vp').getBoundingClientRect();
        const box = document.querySelector('.kbbs-s .hb-box');
        const q = (sel) => { const e = box && box.querySelector(sel); if (!e) return null; const x = e.getBoundingClientRect(); return [x.left - vp.left, x.top - vp.top, x.width, x.height].map((v) => Math.round(v * 10) / 10); };
        const bx = box ? box.getBoundingClientRect() : null;
        const btn = box && box.querySelector('.hb-btn'); let hit = null;
        if (btn) { const c = btn.getBoundingClientRect(); const e = document.elementFromPoint(c.left + c.width / 2, c.top + c.height / 2); hit = !!(e && btn.contains(e)); }
        return { frame: [vp.width, vp.height].map(Math.round), box: bx ? [bx.left - vp.left, bx.top - vp.top, bx.width, bx.height].map((v) => Math.round(v * 10) / 10) : null,
          eb: q('.hb-eb'), h: q('.hb-h'), t: q('.hb-t'), btn: q('.hb-btn'), stk: q('.hb-stk'), hit, scrollWidth: document.documentElement.scrollWidth };
      });
      console.log(JSON.stringify({ tag: TAG, width, path, ...r, errors }));
      await page.close();
    }
  }
  await b.close();
})();
