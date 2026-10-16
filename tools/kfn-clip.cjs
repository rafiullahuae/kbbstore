/*
 * The footer's big name: is any of its ink cut off at the bottom?
 * background-clip:text paints only inside the name's box, so ink below the
 * box is invisible. Ink bottom = the baseline (a zero-size marker set on it)
 * plus the glyphs' real descent (canvas measureText, same font and size).
 *   node tools/kfn-clip.cjs <port> [outdir]
 */
const { chromium } = require('playwright');
(async () => {
  const port = process.argv[2], out = process.argv[3];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const lang of ['ar', 'en']) for (const w of [320, 390, 600, 900, 1280, 1600]) {
    const c = await b.newContext({ viewport: { width: w, height: 900 } });
    const p = await c.newPage();
    const errs = []; p.on('pageerror', e => errs.push(String(e))); p.on('console', m => m.type() === 'error' && errs.push(m.text()));
    await p.goto(`http://127.0.0.1:${port}${lang === 'ar' ? '/ar' : ''}/`, { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    const n = p.locator('.kft-name');
    await n.scrollIntoViewIfNeeded();
    await p.waitForTimeout(150);
    if (out) await n.screenshot({ path: `${out}/${lang}-${w}-name.png`, animations: 'disabled' });
    const m = await n.evaluate(e => {
      const cs = getComputedStyle(e), r = e.getBoundingClientRect();
      const mk = document.createElement('span'); mk.style.cssText = 'display:inline-block;width:0;height:0;vertical-align:baseline';
      e.appendChild(mk); const base = mk.getBoundingClientRect().bottom; mk.remove();
      const cv = document.createElement('canvas').getContext('2d');
      cv.font = `${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
      const t = cv.measureText(e.textContent.trim());
      const inkBottom = base + t.actualBoundingBoxDescent, inkTop = base - t.actualBoundingBoxAscent;
      return { text: e.textContent.trim(), fs: Math.round(parseFloat(cs.fontSize)), lh: cs.lineHeight, pb: cs.paddingBottom, boxH: Math.round(r.height),
        cutBottomPx: Math.max(0, Math.round(inkBottom - r.bottom)), cutTopPx: Math.max(0, Math.round(r.top - inkTop)), spareBelowPx: Math.round(r.bottom - inkBottom),
        fitsWidth: e.scrollWidth <= e.clientWidth, page: document.documentElement.scrollWidth };
    });
    console.log(lang, w, JSON.stringify({ ...m, errs }));
    await c.close();
  }
  await b.close();
})();
