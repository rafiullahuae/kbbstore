/* Lane SH: product cards per row in the campaign emails, at 320/375/414/600,
   with the <style> blocks and with them stripped (a client that ignores them).
   node tools/sh-grid.cjs docs/lane-sh-shots/grid */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const DIR = process.argv[2];
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const f of fs.readdirSync(DIR).filter((n) => n.endsWith('.html'))) {
    for (const strip of [false, true]) {
      let html = fs.readFileSync(path.join(DIR, f), 'utf8');
      if (strip) html = html.replace(/<style[\s\S]*?<\/style>/g, '');
      for (const w of [320, 375, 414, 600]) {
        const page = await (await browser.newContext({ viewport: { width: w, height: 900 } })).newPage();
        await page.setContent(html, { waitUntil: 'load' });
        const m = await page.evaluate(() => {
          const cards = [...document.querySelectorAll('.hy')];
          const rows = {}; cards.forEach((c) => { const t = Math.round(c.getBoundingClientRect().top); rows[t] = (rows[t] || 0) + 1; });
          return { cards: cards.length, perRow: Object.values(rows), cardW: cards[0] ? Math.round(cards[0].getBoundingClientRect().width) : null, sw: document.documentElement.scrollWidth };
        });
        out.push({ f, strip, w, ...m });
        if (!strip && (w === 375 || w === 600)) {
          const first = await page.$('.hy'); if (first) { await first.scrollIntoViewIfNeeded(); }
          const box = await page.evaluate(() => { const c = [...document.querySelectorAll('.hy')]; const a = c[0].getBoundingClientRect(), z = c[c.length - 1].getBoundingClientRect(); return { y: Math.max(0, a.top + scrollY - 20), h: z.bottom - a.top + 40 }; });
          await page.setViewportSize({ width: w, height: Math.ceil(box.h) + 40 });
          await page.evaluate((y) => scrollTo(0, y), box.y);
          await page.screenshot({ path: path.join(DIR, `${f.replace('.html', '')}-${w}.png`) });
        }
        await page.close();
      }
    }
  }
  for (const r of out) console.log(r.f.padEnd(30), r.strip ? 'no-style' : 'styled  ', String(r.w).padStart(4), 'cards', r.cards, 'perRow', JSON.stringify(r.perRow), 'w', r.cardW, 'sw', r.sw);
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
