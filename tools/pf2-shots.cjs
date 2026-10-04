// Lane PF2: full-page screenshots for the pixel comparison.
//
//   node tools/pf2-shots.cjs <base> <outdir> <tag>
//
// Every page at 390 and 1280, DPR 1, reduced motion and CSS animations frozen
// (the banner's autoplay, the ticker and the footer shine would otherwise put
// a different frame in every shot). Scrolled to the bottom and back first, so
// every lazy picture has been fetched and painted before the shot.
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const [base, out, tag] = process.argv.slice(2);
  fs.mkdirSync(out, { recursive: true });
  const pages = { home: '/', shop: '/shop/', category: '/collections/sunscreens/', product: '/product/relief-sun-rice-probiotics-spf50/' };
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [name, path] of Object.entries(pages)) {
    for (const w of [390, 1280]) {
      const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
      const p = await ctx.newPage();
      await p.goto(base + path, { waitUntil: 'load' });
      await p.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
      await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 300) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 80)); } window.scrollTo(0, 0); });
      await p.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
      // Only pictures that are actually drawn: a lazy <img> inside a card a
      // rail hides past its count never loads, and waiting on it hangs.
      await p.evaluate(() => Promise.race([new Promise(r => setTimeout(r, 8000)), Promise.all([...document.images]
        .filter(i => i.getClientRects().length && getComputedStyle(i).visibility !== 'hidden')
        .map(i => i.complete ? 0 : new Promise(r => { i.onload = i.onerror = r; })))]));
      await p.waitForTimeout(800);
      const m = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, h: document.documentElement.scrollHeight,
        cards: [...document.querySelectorAll('img.kbb-card-img')].length,
        first: (() => { const i = document.querySelector('img.kbb-card-img'); if (!i) return null; const r = i.getBoundingClientRect(); return [Math.round(r.width * 100) / 100, Math.round(r.height * 100) / 100, i.naturalWidth, i.naturalHeight, i.getAttribute('width'), i.getAttribute('height')]; })() }));
      await p.screenshot({ path: `${out}/${tag}-${name}-${w}.png`, fullPage: true, animations: 'disabled', caret: 'hide' });
      console.log(`${tag} ${name} ${w}: scrollWidth=${m.sw} height=${m.h} cards=${m.cards} firstCard=${JSON.stringify(m.first)}`);
      await ctx.close();
    }
  }
  await b.close();
})();
