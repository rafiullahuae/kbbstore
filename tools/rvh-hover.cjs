/*
 * Product page reviews: does a review card stay on screen while the pointer is
 * on it? Hovers each card, then asks the browser what is under the pointer
 * and photographs the card, on /ar and in English, at 1280 and 390.
 *   node tools/rvh-hover.cjs <port> <product-slug> [outdir]
 */
const { chromium } = require('playwright');
(async () => {
  const [port, slug, out] = process.argv.slice(2);
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const lang of ['ar', 'en']) for (const w of [1280, 390]) {
    const c = await b.newContext({ viewport: { width: w, height: 900 }, hasTouch: false });
    const p = await c.newPage();
    const errs = []; p.on('pageerror', e => errs.push(String(e)));
    await p.goto(`http://127.0.0.1:${port}${lang === 'ar' ? '/ar' : ''}/product/${slug}/`, { waitUntil: 'load' });
    const cards = p.locator('.sr-card');
    const n = await cards.count();
    const res = [];
    for (let i = 0; i < Math.min(n, 4); i++) {
      const card = cards.nth(i);
      await card.scrollIntoViewIfNeeded();
      const bb = await card.boundingBox();
      await p.mouse.move(bb.x + bb.width / 2, bb.y + bb.height / 2);
      const frames = [];
      for (let f = 0; f < 6; f++) {
        await p.waitForTimeout(60);
        frames.push(await card.evaluate((el, pt) => {
          const r = el.getBoundingClientRect(), hit = document.elementFromPoint(pt.x, pt.y);
          const cs = getComputedStyle(el);
          return { onCard: !!hit && el.contains(hit), top: Math.round(r.top), h: Math.round(r.height), op: cs.opacity, vis: cs.visibility, tr: cs.transform };
        }, { x: bb.x + bb.width / 2, y: bb.y + bb.height / 2 }));
      }
      if (out && i === 0) await p.screenshot({ path: `${out}/${lang}-${w}-hover.png`, clip: { x: 0, y: Math.max(0, bb.y - 60), width: w, height: Math.min(500, bb.height + 120) } });
      res.push({ i, stable: frames.every(f => f.onCard), frames: frames.map(f => (f.onCard ? 'on' : 'OFF') + '@' + f.top).join(' ') });
    }
    console.log(lang, w, 'cards', n, JSON.stringify(res), errs.length ? JSON.stringify(errs) : '');
    await c.close();
  }
  await b.close();
})();
