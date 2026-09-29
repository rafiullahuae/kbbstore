/*
 * Lane PP — what is actually wrong with the product page, in numbers.
 *
 * The owner's complaint ("i don't like that much the product page") is real and
 * vague. This reads the page rather than arguing with it: every block in the
 * buy column with its top, its height and the gap above it, the gallery frame
 * against the photograph inside it, and the whole page's section heights.
 *
 * Nothing here ships. The page itself measures nothing.
 */
const { chromium } = require('playwright');
const BASE = process.env.PP_BASE || 'http://127.0.0.1:8977';

const PROBE = () => {
  const r = (el) => { const b = el.getBoundingClientRect(); return { t: Math.round(b.top + scrollY), h: Math.round(b.height), w: Math.round(b.width), x: Math.round(b.left) }; };
  const buy = document.querySelector('.buybox');
  let prev = null;
  const blocks = [...buy.children].flatMap((c) => (c.tagName === 'FORM' ? [...c.children] : [c]))
    .filter((c) => c.getBoundingClientRect().height > 0)
    .map((c) => {
      const b = r(c);
      const gap = prev === null ? null : b.t - prev;
      prev = b.t + b.h;
      const cs = getComputedStyle(c);
      return { el: c.tagName.toLowerCase() + '.' + String(c.className).trim().split(/\s+/).filter(Boolean).join('.'), top: b.t, h: b.h, gapAbove: gap, fontSize: cs.fontSize, textAlign: cs.textAlign, x: b.x };
    });
  const gal = document.querySelector('.gallery');
  const img = document.querySelector('.gallery img');
  const frame = gal ? r(gal) : null;
  const ir = img ? img.getBoundingClientRect() : null;
  // How much of the frame the photograph actually covers, given object-fit.
  let painted = null;
  if (img && ir) {
    const nw = img.naturalWidth, nh = img.naturalHeight;
    const scale = Math.min(ir.width / nw, ir.height / nh);
    painted = { w: Math.round(nw * scale), h: Math.round(nh * scale) };
  }
  const sec = [...document.querySelectorAll('.wrap > *, .sec, section')].filter((s) => s.getBoundingClientRect().height > 8)
    .map((s) => ({ el: s.tagName.toLowerCase() + '.' + String(s.className).trim().split(/\s+/).filter(Boolean).join('.'), h: Math.round(s.getBoundingClientRect().height) }));
  return {
    viewport: innerWidth,
    pageHeight: document.documentElement.scrollHeight,
    buyboxHeight: r(buy).h,
    blocks,
    gallery: frame,
    galleryImgNatural: img ? [img.naturalWidth, img.naturalHeight] : null,
    galleryPainted: painted,
    galleryFillPct: frame && painted ? Math.round((painted.w * painted.h) / (frame.w * frame.h) * 100) : null,
    sections: sec,
  };
};

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 1400 } });
  const p = await ctx.newPage();
  for (const [label, slug] of [['plain', 'lanepp-plain-moisturiser'], ['set3', 'lanepp-glow-starter-set']]) {
    for (const w of [390, 1280]) {
      await p.setViewportSize({ width: w, height: 1400 });
      await p.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
      await p.waitForTimeout(250);
      console.log('### ' + label + ' @ ' + w);
      console.log(JSON.stringify(await p.evaluate(PROBE), null, 1));
    }
  }
  await b.close();
})();
