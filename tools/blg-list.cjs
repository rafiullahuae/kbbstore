/*
 * Blog post lists: do the bullets sit inside the text column? Compares each
 * list marker's left edge (in Arabic, right edge) with the paragraph text edge.
 *   node tools/blg-list.cjs <port> <slug> <outdir> <tag>
 */
const { chromium } = require('playwright');
(async () => {
  const [port, slug, out, tag] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const lang of ['en', 'ar']) for (const w of [390, 1280]) {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    const r = await p.goto(`http://127.0.0.1:${port}${lang === 'ar' ? '/ar' : ''}/blog/${slug}/`, { waitUntil: 'load' });
    const m = await p.evaluate((rtl) => {
      const body = document.querySelector('.abody'); if (!body) return null;
      const col = body.getBoundingClientRect();
      const li = body.querySelector('li'); const lr = li.getBoundingClientRect();
      const markerW = parseFloat(getComputedStyle(li).fontSize) * 1.0; // a bullet and its gap sit about 1em before the text
      const markerEdge = rtl ? lr.right + markerW : lr.left - markerW;
      return { bulletOutsideColumnPx: Math.round(rtl ? Math.max(0, markerEdge - col.right) : Math.max(0, col.left - markerEdge)), listIndent: getComputedStyle(li.parentElement).paddingInlineStart, page: document.documentElement.scrollWidth };
    }, lang === 'ar');
    const ul = p.locator('.abody ul').first(); await ul.scrollIntoViewIfNeeded();
    const bb = await ul.boundingBox(); const sy = await p.evaluate(() => scrollY);
    await p.screenshot({ path: `${out}/${tag}-${lang}-${w}.png`, clip: { x: 0, y: Math.max(0, bb.y + sy - 110), width: w, height: bb.height + 230 }, fullPage: true });
    console.log(tag, lang, w, r.status(), JSON.stringify(m));
    await p.close();
  }
  await b.close();
})();
