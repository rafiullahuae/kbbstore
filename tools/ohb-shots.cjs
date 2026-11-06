// Lane OHB: screenshots and measurements of the homepage occasion banner.
//   node tools/ohb-shots.cjs <base> <label> [path] [widths]
// e.g. node tools/ohb-shots.cjs http://127.0.0.1:11900 both-G-1111
// Writes docs/lane-ohb-shots/<label>-<w>.jpg and prints one JSON line per width.
// The measuring here (getBoundingClientRect, elementFromPoint) is the
// INSTRUMENT, not the shop: nothing the shop ships measures layout.
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const BASE = process.argv[2];
const LABEL = process.argv[3];
const PAGE = process.argv[4] || '/';
const SIZES = (process.argv[5] || '390x844,844x390,1280x800').split(',').map((s) => s.split('x').map(Number));
const OUT = path.join(__dirname, '..', 'docs', 'lane-ohb-shots');
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const [w, h] of SIZES) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, isMobile: w < 700, hasTouch: w < 900 && h < w ? true : w < 700 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    await page.goto(BASE + PAGE, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    const file = path.join(OUT, `${LABEL}-${w}x${h}.jpg`);
    await page.screenshot({ path: file, type: 'jpeg', quality: 78, clip: { x: 0, y: 0, width: w, height: Math.min(h, 1000) } });
    const m = await page.evaluate(() => {
      const o = document.querySelector('.ohb');
      const vp = document.querySelector('.kbbs-vp');
      const r = o ? o.getBoundingClientRect() : null;
      const btn = o ? o.querySelector('.ohb-btn') : null;
      let hit = null;
      if (btn) {
        btn.scrollIntoView({ block: 'center' });
        const b = btn.getBoundingClientRect();
        const el = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
        hit = !!el && (el === btn || btn.contains(el));
      }
      const imgs = o ? [...o.querySelectorAll('.ohb-p img')].map((i) => ({ alt: i.alt, h: Math.round(i.getBoundingClientRect().height), cur: (i.currentSrc || '').split('/img-cache/cut/')[1] })) : [];
      const t = o ? o.querySelector('.ohb-title') : null;
      return {
        banner: r ? { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top) } : null,
        slider: vp ? { w: Math.round(vp.getBoundingClientRect().width), h: Math.round(vp.getBoundingClientRect().height) } : null,
        slides: document.querySelectorAll('.kbbs-s').length,
        scrollWidth: document.documentElement.scrollWidth,
        titlePx: t ? getComputedStyle(t).fontSize : null,
        buttonHit: hit,
        products: imgs,
        phoneLayout: o ? getComputedStyle(o.querySelector('.ohb-copy')).top === '20px' : null,
      };
    });
    let nav = null;
    const btn = await page.$('.ohb .ohb-btn');
    if (btn && process.env.OHB_CLICK === '1') {
      await Promise.all([page.waitForNavigation({ timeout: 8000 }).catch(() => null), btn.click()]);
      nav = page.url().replace(BASE, '');
    }
    console.log(JSON.stringify({ label: LABEL, w, h, ...m, nav, errors }));
    await ctx.close();
  }
  await browser.close();
})();
