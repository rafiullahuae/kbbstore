/*
 * Lane PV — the top of the product page: the blurb, the thumbnail strip, the
 * space above the photograph, the summary's gaps and the discount badge.
 *
 *   node tools/pv-shots.cjs <label>      (PV_BASE, PV_OUT to override)
 *
 * Shoots pv-twin-sun-serum (the owner's product, reproduced) and pv-control at
 * 390 and 1280, and writes every number into <label>-measure.json beside the
 * pictures. NOTHING HERE SHIPS: every rectangle below is read by the harness,
 * never by a script the shop serves (CLAUDE.md rule 4).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PV_BASE || 'http://127.0.0.1:8961';
const OUT = process.env.PV_OUT || path.join(__dirname, '..', 'docs', 'lane-pv-shots');
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const LABEL = process.argv[2] || 'shot';

const M = () => {
  const r = (sel) => { const e = document.querySelector(sel); return e ? e.getBoundingClientRect() : null; };
  const px = (n) => (n === null || n === undefined ? null : Math.round(n * 10) / 10);
  const header = document.querySelector('header') || document.querySelector('.hdr');
  // the lowest bottom edge of anything fixed/sticky at the top (header + search bar)
  let topChrome = 0;
  document.querySelectorAll('body *').forEach((el) => {
    const cs = getComputedStyle(el);
    if ((cs.position === 'sticky' || cs.position === 'fixed') && el.getBoundingClientRect().top <= 1 && el.offsetHeight > 20 && el.offsetHeight < 260) {
      topChrome = Math.max(topChrome, el.getBoundingClientRect().bottom + window.scrollY);
    }
  });
  const gmain = r('.gmain');
  const thumbs = r('#gthumbs');
  const brand = r('.bb-brand');
  const title = r('.bb-title');
  const rate = (() => { const a = r('.cap-area .sr-capbar'); const b = r('#bbRate'); return (a && a.height) ? a : b; })();
  const desc = r('.bb-desc');
  const more = r('.bb-more');
  const opt = r('.opt-label');
  const crumb = r('.crumb');
  const lbl = document.querySelector('.gmain .lbl');
  const lcs = lbl ? getComputedStyle(lbl) : null;
  const descEl = document.querySelector('.bb-desc');
  const firstText = descEl ? (() => {
    // where the blurb's first visible character sits, relative to the blurb's box
    const w = document.createTreeWalker(descEl, NodeFilter.SHOW_TEXT);
    let n;
    while ((n = w.nextNode())) {
      if (n.textContent.replace(/[\s ]/g, '') !== '') {
        const range = document.createRange(); range.selectNodeContents(n);
        return px(range.getBoundingClientRect().top - descEl.getBoundingClientRect().top);
      }
    }
    return null;
  })() : null;
  const sy = window.scrollY;
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    headerBottom: px(topChrome),
    crumb: crumb ? { display: getComputedStyle(document.querySelector('.crumb')).display, top: px(crumb.top + sy), bottom: px(crumb.bottom + sy) } : null,
    galleryTop: gmain ? px(gmain.top + sy) : null,
    gapHeaderToPhoto: gmain ? px(gmain.top + sy - topChrome) : null,
    photo: gmain ? [px(gmain.width), px(gmain.height)] : null,
    photoBottom: gmain ? px(gmain.bottom + sy) : null,
    thumbsTop: thumbs ? px(thumbs.top + sy) : null,
    thumbsBottom: thumbs ? px(thumbs.bottom + sy) : null,
    thumbsOverlapPhoto: (thumbs && gmain) ? px(gmain.bottom - thumbs.top) : null,
    gaps: {
      galleryToBrand: (brand && (thumbs || gmain)) ? px(brand.top - (thumbs ? thumbs.bottom : gmain.bottom)) : null,
      brandToTitle: (brand && title) ? px(title.top - brand.bottom) : null,
      titleToRating: (title && rate) ? px(rate.top - title.bottom) : null,
      ratingToDesc: (rate && desc) ? px(desc.top - rate.bottom) : null,
      descToMore: (desc && more) ? px(more.top - desc.bottom) : null,
      moreToOptions: (more && opt) ? px(opt.top - more.bottom) : null,
    },
    descBox: desc ? [px(desc.width), px(desc.height)] : null,
    descFirstTextOffset: firstText,
    descVisibleText: descEl ? descEl.innerText.trim().slice(0, 60) : null,
    titleFont: title ? getComputedStyle(document.querySelector('.bb-title')).fontSize + ' / ' + getComputedStyle(document.querySelector('.bb-title')).fontWeight : null,
    brandFont: brand ? getComputedStyle(document.querySelector('.bb-brand')).fontSize : null,
    badge: lcs ? { text: lbl.textContent.trim(), background: lcs.backgroundColor, color: lcs.color } : null,
    pricePill: (() => { const o = document.querySelector('.bb-price .off'); if (!o) return null; const c = getComputedStyle(o); return { text: o.textContent.trim(), background: c.backgroundColor, color: c.color }; })(),
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE });
  const out = {};
  const pages = (process.env.PV_PAGES || 'twin:/product/pv-twin-sun-serum/,control:/product/pv-control/')
    .split(',').map((p) => p.split(':'));

  for (const [name, url] of pages) {
    for (const width of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: width === 390 ? 2 : 1 });
      const page = await ctx.newPage();
      await page.goto(BASE + url, { waitUntil: 'networkidle' });
      await page.waitForTimeout(300);
      const key = `${name}-${width}`;
      out[key] = await page.evaluate(M);
      await page.screenshot({ path: path.join(OUT, `${LABEL}-${key}.png`), fullPage: false });
      if (width === 390) {
        // the summary: brand down to the options, which the first screen cuts off
        await page.evaluate(() => { const b = document.querySelector('.bb-brand'); if (b) window.scrollTo(0, b.getBoundingClientRect().top + window.scrollY - 150); });
        await page.waitForTimeout(900);
        await page.screenshot({ path: path.join(OUT, `${LABEL}-${key}-summary.png`), fullPage: false });
        await page.evaluate(() => window.scrollTo(0, 0));
      }
      if (name === 'twin') {
        const more = await page.$('label.bb-more');
        if (more) {
          await more.click();
          await page.waitForTimeout(200);
          out[key + '-expanded'] = await page.evaluate(M);
          await page.screenshot({ path: path.join(OUT, `${LABEL}-${key}-expanded.png`), fullPage: false });
        }
      }
      await ctx.close();
    }
  }

  fs.writeFileSync(path.join(OUT, `${LABEL}-measure.json`), JSON.stringify(out, null, 2));
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
