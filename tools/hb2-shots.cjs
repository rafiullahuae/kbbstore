/*
 * Lane HB2: the homepage banner with a 1px red guide down the header
 * container's content edge -- the line the owner drew -- so a picture shows
 * whether the text box lines up with the logo. The guide is injected by this
 * harness only; the shop draws nothing of the kind.
 *   HB_BASE=... HB_TAG=aligned HB_WIDTHS=390,1280,1920 HB_PATH=/ node tools/hb2-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const BASE = process.env.HB_BASE, TAG = process.env.HB_TAG || 'x', P = process.env.HB_PATH || '/';
const OUT = path.join(__dirname, '..', 'docs', 'lane-hb2-shots');
fs.mkdirSync(OUT, { recursive: true });
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of (process.env.HB_WIDTHS || '390,1280,1920').split(',').map(Number)) {
    const page = await b.newPage({ viewport: { width, height: 900 } });
    await page.goto(BASE + P, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    const bottom = await page.evaluate(() => {
      const wrap = document.querySelector('header .wrap'); const r = wrap.getBoundingClientRect(); const cs = getComputedStyle(wrap);
      const rtl = document.documentElement.dir === 'rtl';
      const x = rtl ? r.right - parseFloat(cs.paddingRight) : r.left + parseFloat(cs.paddingLeft);
      const g = document.createElement('div');
      g.style.cssText = `position:absolute;top:0;left:${x - (rtl ? 1 : 0)}px;width:1px;height:${document.documentElement.scrollHeight}px;background:#e00;z-index:99999;pointer-events:none`;
      document.body.appendChild(g);
      return Math.ceil(document.querySelector('.kbbs-vp').getBoundingClientRect().bottom + scrollY);
    });
    await page.screenshot({ path: path.join(OUT, `${TAG}-${width}.png`), clip: { x: 0, y: 0, width, height: Math.min(bottom + 20, 1300) } });
    await page.close();
  }
  await b.close();
})();
