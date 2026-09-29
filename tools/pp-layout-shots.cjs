/*
 * Lane PP — the three proposed layouts, photographed. Each on an ordinary
 * product and on a set, at 390 and 1280, with the numbers that decide between
 * them: scrollWidth, the y of Add to cart, the whole page height, and how much
 * of the gallery frame is actually photograph.
 *
 * Nothing here ships. The page measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.PP_BASE || 'http://127.0.0.1:8977';
const OUT = process.env.PP_OUT || '/home/user/lane-pp/docs/lane-pp-shots';

const M = () => {
  const gal = document.querySelector('.gmain');
  const img = document.querySelector('.gmain-img');
  const f = gal ? gal.getBoundingClientRect() : null;
  let fill = null, painted = null;
  if (img && f) {
    const r = img.getBoundingClientRect();
    const s = Math.min(r.width / img.naturalWidth, r.height / img.naturalHeight);
    painted = [Math.round(img.naturalWidth * s), Math.round(img.naturalHeight * s)];
    fill = Math.round((painted[0] * painted[1]) / (f.width * f.height) * 100);
  }
  const btn = document.querySelector('.addcart');
  const r = btn ? btn.getBoundingClientRect() : null;
  const desc = document.querySelector('.bb-desc');
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    pageHeight: document.documentElement.scrollHeight,
    pdpHeight: Math.round(document.querySelector('.pdp').getBoundingClientRect().height),
    buyboxHeight: Math.round(document.querySelector('.buybox').getBoundingClientRect().height),
    addToCartY: r ? Math.round(r.top + window.scrollY) : null,
    galleryFrame: f ? [Math.round(f.width), Math.round(f.height)] : null,
    galleryPainted: painted,
    galleryFillPct: fill,
    descTop: desc ? Math.round(desc.getBoundingClientRect().top + window.scrollY) : null,
    titleFontSize: getComputedStyle(document.querySelector('.bb-title')).fontSize,
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const all = [];
  const pages = [['plain', 'lanepp-plain-moisturiser'], ['set3', 'lanepp-glow-starter-set']];
  for (const lay of ['focus', 'editorial', 'compact']) {
    for (const [label, slug] of pages) {
      for (const w of [390, 1280]) {
        await p.setViewportSize({ width: w, height: 1400 });
        await p.goto(`${BASE}/product/${slug}/?layout=${lay}`, { waitUntil: 'networkidle' });
        await p.waitForTimeout(300);
        const m = await p.evaluate(M);
        await p.screenshot({ path: `${OUT}/layout-${lay}-${label}-${w}.png`, fullPage: true });
        console.log(JSON.stringify({ shot: `layout-${lay}-${label}-${w}`, ...m }));
        all.push({ layout: lay, page: label, width: w, ...m });
      }
    }
  }
  await b.close();
  fs.writeFileSync(`${OUT}/layout-measurements.json`, JSON.stringify(all, null, 2));
})();
