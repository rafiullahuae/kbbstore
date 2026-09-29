/*
 * Lane PP — the phone shots at a REAL phone viewport (390x844), not the tall
 * one the full-page captures use.
 *
 * This matters for proposal C: its gallery cap is written in vh and its buy row
 * is `position:sticky;bottom:0`. Both are inert in a 1400px-tall window, so a
 * full-page shot photographs a page the phone never renders. These are viewport
 * shots, scrolled, which is the only way the sticky row is in the picture at
 * all.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.PP_BASE || 'http://127.0.0.1:8977';
const OUT = process.env.PP_OUT || '/home/user/lane-pp/docs/lane-pp-shots';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  const out = [];
  for (const [name, qs] of [['shipped', ''], ['focus', '?layout=focus'], ['editorial', '?layout=editorial'], ['compact', '?layout=compact']]) {
    for (const [label, slug] of [['plain', 'lanepp-plain-moisturiser'], ['set3', 'lanepp-glow-starter-set']]) {
      await p.goto(`${BASE}/product/${slug}/${qs}`, { waitUntil: 'networkidle' });
      await p.waitForTimeout(250);
      // Scrolled to where a shopper actually decides: the top of the options.
      await p.evaluate(() => {
        const t = document.querySelector('.opt-label, .ksl');
        if (t) window.scrollTo(0, Math.max(0, t.getBoundingClientRect().top + scrollY - 90));
      });
      await p.waitForTimeout(250);
      const m = await p.evaluate(() => {
        const btn = document.querySelector('.addcart');
        const r = btn ? btn.getBoundingClientRect() : null;
        const gm = document.querySelector('.gmain');
        return {
          scrollWidth: document.documentElement.scrollWidth,
          scrollY: Math.round(scrollY),
          galleryFrame: gm ? [Math.round(gm.getBoundingClientRect().width), Math.round(gm.getBoundingClientRect().height)] : null,
          // IN the 844px window, which is the only question that matters here.
          addToCartVisible: !!(r && r.top < innerHeight && r.bottom > 0),
          addToCartViewportY: r ? Math.round(r.top) : null,
        };
      });
      await p.screenshot({ path: `${OUT}/phone844-${name}-${label}.png` });
      console.log(JSON.stringify({ shot: `phone844-${name}-${label}`, ...m }));
      out.push({ layout: name, page: label, ...m });
    }
  }
  await b.close();
  fs.writeFileSync(`${OUT}/phone844-measurements.json`, JSON.stringify(out, null, 2));
})();
