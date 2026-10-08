/* Lane LZ: per product grid on a page, its top and how many cards share the
   first row, at 390 and 1280. Harness-only.   node tools/lz-rows.cjs PORT PATH */
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const [port, p, widths = '390,1280,1680'] = process.argv.slice(2);
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of widths.split(',').map(Number)) {
    const m = w < 600;
    const ctx = await b.newContext({ viewport: { width: w, height: m ? 844 : 860 }, deviceScaleFactor: m ? 3 : 1, isMobile: m, hasTouch: m, serviceWorkers: 'block' });
    const page = await ctx.newPage();
    await page.goto('http://127.0.0.1:' + port + p, { waitUntil: 'load' });
    const r = await page.evaluate(() => [...document.querySelectorAll('.kbb-pgrid, .gs-grid, .hs-grid, .bndl-track')].filter((g) => !g.parentElement.closest('.kbb-pgrid')).map((g) => {
      const cards = [...g.querySelectorAll('.kbb-card-img, .kbb-card-ph')].map((i) => i.getBoundingClientRect()).filter((x) => x.width > 0);
      if (!cards.length) return null;
      const top = Math.round(cards[0].top + scrollY);
      const inRow = cards.filter((c) => Math.abs(c.top - cards[0].top) < 2 && c.left < innerWidth && c.right > 0).length;
      const sec = g.closest('section');
      return { sec: sec ? sec.className.slice(0, 50) : g.className.slice(0, 40), top, inRow, total: cards.length, rowH: cards.find((c) => c.top > cards[0].top + 2) ? Math.round(cards.find((c) => c.top > cards[0].top + 2).top - cards[0].top) : null };
    }).filter(Boolean));
    console.log('==', w, p); for (const x of r) console.log('  ', JSON.stringify(x));
    await ctx.close();
  }
  await b.close();
})();
