/*
 * Lane HB2: where the text box sits, measured in Chromium (harness only -- the
 * shop measures nothing). For each width: the header container's content edge
 * (the .wrap's left + its padding), the logo's left, and the box's LAYOUT
 * edges (offsetParent rect + offsetLeft/Top, so D's tilt does not count), plus
 * the frame, both sides, and whether a real click on the button navigates.
 *
 *   HB_BASE=http://127.0.0.1:10560 HB_TAG=after HB_PATH=/ node tools/hb2-align.cjs
 */
const { chromium } = require('playwright');
const BASE = process.env.HB_BASE, TAG = process.env.HB_TAG || 'x', PATH = process.env.HB_PATH || '/';
const WIDTHS = (process.env.HB_WIDTHS || '390,768,1280,1600,1920').split(',').map(Number);
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of WIDTHS) {
    const page = await b.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + PATH, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    const m = await page.evaluate(() => {
      const r = (x) => x.getBoundingClientRect();
      const wrap = document.querySelector('header .wrap');
      const cs = getComputedStyle(wrap);
      const rtl = document.documentElement.dir === 'rtl';
      const content = rtl ? r(wrap).right - parseFloat(cs.paddingRight) : r(wrap).left + parseFloat(cs.paddingLeft);
      const logo = document.querySelector('header .logo');
      const box = document.querySelector('.kbbs-s .hb-box');
      const vp = r(document.querySelector('.kbbs-vp'));
      if (!box || getComputedStyle(box.closest('.hb-pos') || box).display === 'none') return { none: true };
      const op = r(box.offsetParent);
      const left = op.left + box.offsetLeft, top = op.top + box.offsetTop;
      const right = left + box.offsetWidth, bottom = top + box.offsetHeight;
      const btn = box.querySelector('.hb-btn');
      let hit = null;
      if (btn) { const q = r(btn); const e = document.elementFromPoint(q.left + q.width / 2, q.top + q.height / 2); hit = !!(e && btn.contains(e)); }
      return {
        rtl, header: Math.round(content * 10) / 10, logo: Math.round((rtl ? r(logo).right : r(logo).left) * 10) / 10,
        boxEdge: Math.round((rtl ? right : left) * 10) / 10,
        box: { left: Math.round(left - vp.left), top: Math.round(top - vp.top), right: Math.round(vp.right - right), bottom: Math.round(vp.bottom - bottom), w: box.offsetWidth, h: box.offsetHeight },
        frame: Math.round(vp.width) + 'x' + Math.round(vp.height), inside: top >= vp.top && bottom <= vp.bottom && left >= vp.left && right <= vp.right,
        buttonHit: hit, scrollWidth: document.documentElement.scrollWidth,
      };
    });
    console.log(JSON.stringify({ tag: TAG, width, ...m, errors }));
    await page.close();
  }
  await b.close();
})();
