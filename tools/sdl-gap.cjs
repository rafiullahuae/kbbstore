/*
 * The short description under the price: the empty space above and below its
 * text (text box vs. the block before/after it), at 390 and 1280.
 *   node tools/sdl-gap.cjs <port> <slug> <outdir> <tag>
 */
const { chromium } = require('playwright');
(async () => {
  const [port, slug, out, tag] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const lang of ['en', 'ar']) for (const w of [390, 1280]) {
    const p = await b.newPage({ viewport: { width: w, height: 1000 } });
    await p.goto(`http://127.0.0.1:${port}${lang === 'ar' ? '/ar' : ''}/product/${slug}/`, { waitUntil: 'load' });
    const m = await p.evaluate(() => {
      const d = document.querySelector('.pm-short .bb-desc'); if (!d) return null;
      const r = document.createRange(); r.selectNodeContents(d);
      const rects = Array.from(r.getClientRects()).filter(x => x.height > 0 && x.width > 0);
      const textTop = Math.min(...rects.map(x => x.top)), textBottom = Math.max(...rects.map(x => x.bottom));
      const box = d.closest('.pm-short').getBoundingClientRect();
      const sec = d.querySelector('section'), cs = sec && getComputedStyle(sec);
      const lines = rects.filter(x => x.top >= box.top - 1 && x.bottom <= box.bottom + 1).length;
      return { sectionPadding: cs ? cs.paddingTop + ' / ' + cs.paddingBottom : 'none', firstLineFromBoxTop: Math.round(textTop - box.top), linesVisibleBeforeReadMore: lines, page: document.documentElement.scrollWidth };
    });
    await p.locator('.pm-short').first().scrollIntoViewIfNeeded();
    const bb = await p.locator('.pm-short').first().boundingBox();
    await p.screenshot({ path: `${out}/${tag}-${lang}-${w}.png`, clip: { x: 0, y: Math.max(0, bb.y - 120 + await p.evaluate(() => scrollY)), width: w, height: bb.height + 260 }, fullPage: true });
    console.log(tag, lang, w, JSON.stringify(m));
    await p.close();
  }
  await b.close();
})();
