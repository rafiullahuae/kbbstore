/*
 * Lane RC -- measure and photograph the top of the homepage (header + banner)
 * at 390 and 1280 in Chromium.
 *
 *   RC_BASE=http://127.0.0.1:9941 RC_TAG=before node tools/rc-measure.cjs
 *
 * Writes docs/rc-shots/<tag>-home-<w>.png and prints one JSON line per width:
 * the header's bottom edge, the banner section's top, the banner picture's
 * top, the gap between them, every box from <main> down to the picture with
 * its padding/margin, and document.documentElement.scrollWidth.
 *
 * EVERY RECTANGLE IS READ BY THIS HARNESS, never by a script the shop serves
 * (CLAUDE.md rule 4).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RC_BASE || 'http://127.0.0.1:9941';
const TAG = process.env.RC_TAG || 'shot';
const URL_PATH = process.env.RC_PATH || '/';
const OUT = path.join(__dirname, '..', 'docs', 'rc-shots');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const WIDTHS = (process.env.RC_WIDTHS || '390,1280').split(',').map(Number);
const CLIP = Number(process.env.RC_CLIP || 0);

fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const w of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 768 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await page.goto(BASE + URL_PATH, { waitUntil: 'networkidle' });
    await page.waitForTimeout(400);
    const m = await page.evaluate(() => {
      const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { x: +b.x.toFixed(2), y: +b.y.toFixed(2), w: +b.width.toFixed(2), h: +b.height.toFixed(2), bottom: +b.bottom.toFixed(2) }; };
      const main = document.querySelector('main#content');
      const header = main ? main.previousElementSibling : null;
      let hdr = header;
      // The site header is the last visible element before <main>.
      while (hdr && (hdr.getBoundingClientRect().height === 0)) hdr = hdr.previousElementSibling;
      const banner = document.querySelector('.kbbs, .kbbi, .kbbn');
      const sec = banner ? banner.closest('section') : null;
      const vp = document.querySelector('.kbbs-vp, .kbbi-a, .kbbn-vp');
      const img = vp ? vp.querySelector('img') : null;
      const chain = [];
      for (let el = img; el && el !== document.body; el = el.parentElement) {
        const cs = getComputedStyle(el);
        chain.push({ el: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).join('.') : ''), rect: r(el), pt: cs.paddingTop, mt: cs.marginTop, pb: cs.paddingBottom, mb: cs.marginBottom });
      }
      const ir = img ? img.getBoundingClientRect() : null;
      return {
        url: location.href,
        headerTag: hdr ? hdr.tagName.toLowerCase() + '.' + (hdr.className || '') : null,
        headerBottom: hdr ? +hdr.getBoundingClientRect().bottom.toFixed(2) : null,
        sectionTop: sec ? +sec.getBoundingClientRect().top.toFixed(2) : null,
        frame: r(vp),
        img: r(img),
        imgNatural: img ? [img.naturalWidth, img.naturalHeight] : null,
        imgObjectFit: img ? getComputedStyle(img).objectFit : null,
        imgCurrentSrc: img ? img.currentSrc.replace(location.origin, '') : null,
        gap: hdr && vp ? +(vp.getBoundingClientRect().top - hdr.getBoundingClientRect().bottom).toFixed(2) : null,
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        chain: chain.slice(0, 9),
      };
    });
    const file = path.join(OUT, `${TAG}-home-${w}.png`);
    const clipH = CLIP || Math.min(900, Math.ceil((m.frame ? m.frame.bottom : 600) + 60));
    await page.screenshot({ path: file, clip: { x: 0, y: 0, width: w, height: clipH } });
    console.log(JSON.stringify({ tag: TAG, width: w, file: path.relative(path.join(__dirname, '..'), file), ...m }));
    await ctx.close();
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
