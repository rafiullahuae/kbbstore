/*
 * Lane ARF -- is the Arabic wordmark's pink word clipped? (the owner: "also in
 * the header logo, the end is cut little bit").
 *
 *   node tools/arf-logo.cjs <port> <outJson> [shotsDir] [lang=ar|en]
 *
 * For every width 320..1024 step 20, plus 1280: the gradient word (.lgx-w em)
 * is painted with background-clip:text, so ink that falls outside the em's
 * own box is not painted at all. We photograph the word's neighbourhood twice
 * -- as the shop draws it, and with the em drawn in a SOLID colour (no clip)
 * -- and count pink ink outside the em's box in each. A clipped word has ink
 * outside in the solid copy and none in the real one. Also: the logo box,
 * scrollWidth, and the box's size across 40 frames (stable = one value).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const PORT = process.argv[2] || '9471';
const OUT = process.argv[3] || 'storage/arf-logs/logo.json';
const SHOTS = process.argv[4] || '';
const LANG = process.argv[5] || 'ar';
const URL = `http://127.0.0.1:${PORT}${LANG === 'ar' ? '/ar' : ''}/`;
const WIDTHS = [];
for (let w = 320; w <= 1024; w += 20) WIDTHS.push(w);
WIDTHS.push(390, 1280);
const SOLID = '.lgx .lgx-w em{background:none!important;-webkit-background-clip:border-box!important;background-clip:border-box!important;color:#C6395F!important}';

async function inkOutside(page, png, box, clip) {
  return page.evaluate(async ({ b64, box, clip }) => {
    const img = new Image();
    img.src = 'data:image/png;base64,' + b64;
    await img.decode();
    const c = document.createElement('canvas');
    c.width = img.width; c.height = img.height;
    const x = c.getContext('2d');
    x.drawImage(img, 0, 0);
    const d = x.getImageData(0, 0, c.width, c.height).data;
    const s = img.width / clip.width;
    let out = 0;
    for (let py = 0; py < c.height; py++) {
      for (let px = 0; px < c.width; px++) {
        const i = (py * c.width + px) * 4;
        const [r, g, bl] = [d[i], d[i + 1], d[i + 2]];
        const pink = r - g > 40 && r - bl > 10; // the accent's ink, not the grey name or white
        if (!pink) continue;
        const X = clip.x + px / s, Y = clip.y + py / s;
        if (X < box.x - 0.5 || X > box.x + box.width + 0.5 || Y < box.y - 0.5 || Y > box.y + box.height + 0.5) out++;
      }
    }
    return out;
  }, { b64: png.toString('base64'), box, clip });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];
  for (const w of WIDTHS) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 700 }, deviceScaleFactor: 3, reducedMotion: 'reduce', isMobile: w < 900, hasTouch: w < 900,
      userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
    await ctx.route('**/*', (r) => { const u = r.request().url(); return u.startsWith(`http://127.0.0.1:${PORT}`) || u.startsWith('data:') ? r.continue() : r.abort(); });
    const page = await ctx.newPage();
    await page.goto(URL, { waitUntil: 'load' });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(300);
    const m = await page.evaluate(async () => {
      const lg = document.querySelector('header .logo.lgx');
      const em = lg && lg.querySelector('.lgx-w em');
      if (!em) return null;
      const r = em.getBoundingClientRect();
      const L = lg.getBoundingClientRect();
      const sizes = new Set();
      for (let f = 0; f < 40; f++) { await new Promise((ok) => requestAnimationFrame(ok)); const q = lg.getBoundingClientRect(); sizes.add(`${q.width.toFixed(2)}x${q.height.toFixed(2)}`); }
      return { em: { x: r.x, y: r.y, width: r.width, height: r.height }, logo: { x: L.x, y: L.y, width: L.width, height: L.height },
        emScroll: [em.scrollWidth, em.clientWidth], frames: sizes.size, cls: lg.className,
        sw: document.documentElement.scrollWidth, word: em.textContent, font: getComputedStyle(em).fontFamily, fs: getComputedStyle(em).fontSize };
    });
    if (!m) { rows.push({ w, missing: true }); await ctx.close(); continue; }
    // The tagline and the lotus are pink too: hidden (not removed) for the count.
    await page.addStyleTag({ content: '.lgx .lgx-g,.logo.lgx>.lgx-i,.lgx .lgx-lt{visibility:hidden!important}' });
    const pad = Math.max(10, m.em.height);
    const clip = { x: Math.max(0, m.em.x - pad), y: Math.max(0, m.em.y - pad / 2), width: m.em.width + 2 * pad, height: m.em.height + pad };
    const real = await page.screenshot({ clip });
    await page.addStyleTag({ content: SOLID });
    await page.waitForTimeout(50);
    const solid = await page.screenshot({ clip });
    const inkReal = await inkOutside(page, real, m.em, clip);
    const inkSolid = await inkOutside(page, solid, m.em, clip);
    if (SHOTS && [390, 600, 1280].includes(w)) {
      fs.mkdirSync(SHOTS, { recursive: true });
      fs.writeFileSync(path.join(SHOTS, `logo-${LANG}-${w}.png`), real);
      fs.writeFileSync(path.join(SHOTS, `logo-${LANG}-${w}-solid.png`), solid);
    }
    rows.push({ w, ...m, inkOutsideReal: inkReal, inkOutsideSolid: inkSolid, clipped: inkSolid - inkReal > 20 });
    console.log(`${String(w).padStart(4)} logo ${m.logo.width.toFixed(1)}x${m.logo.height.toFixed(1)} em ${m.em.width.toFixed(1)}x${m.em.height.toFixed(1)} ink-outside real/solid ${inkReal}/${inkSolid} frames=${m.frames} sw=${m.sw}`);
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(OUT, JSON.stringify(rows, null, 1));
})();
