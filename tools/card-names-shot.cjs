/*
 * LANE CARD — the one picture that answers "what happens to a long name".
 *
 * The fixture's LAST row holds `Toner` and an eighty-five-character title side
 * by side, at 320, at 390 and at 1280 alike (indices 11 and 12 of 12 land
 * together whether the row is five wide or two), and one of the pair carries 96
 * reviews while the other has none. So one frame shows both of the owner's
 * sentences at once: a long name that does not make its row taller, and a
 * rating row appearing and disappearing without moving the button.
 *
 * The 129-character name is measured here too. It is in the row above, so it is
 * not in the crop; its numbers are printed beside it, because "what actually
 * happens at three and four lines" is a question the crop cannot answer.
 *
 * Modelled on tools/pg2-names-shot.cjs, including its note about scrolling a
 * lazy image into view before photographing it.
 */
const { chromium } = require('playwright');
const path = require('path');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8990';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OUT = process.env.KBB_SHOTS || path.join(__dirname, '..', 'docs', 'card-shots');

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });

  for (const w of [320, 390, 1280]) {
    const page = await browser.newPage({ viewport: { width: w, height: 1100 } });
    await page.goto(BASE + '/collections/skincare-sets/', { waitUntil: 'networkidle' });

    /* ── SCROLL THE PAIR INTO VIEW FIRST, AND WAIT ─────────────────────────
       Every tile but the first is `loading="lazy"`, and at 390px this pair is
       the SIXTH row. A full-page screenshot does not make a browser fetch an
       image it has decided it does not need yet: the crop came back with empty
       frames and the pale background showing through, which reads as "the
       photograph is broken" and is only the harness. */
    await page.evaluate(() => {
      const t = [...document.querySelectorAll('.kbb-tile')].find(
        (e) => (e.querySelector('.kbb-card-nm')?.textContent || '').trim() === 'Toner'
      );
      t?.scrollIntoView({ block: 'center' });
    });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    const box = await page.evaluate(() => {
      const px = (n) => Math.round(n * 100) / 100;
      const tiles = [...document.querySelectorAll('.kbb-tile')];
      const named = (pred) => tiles.find((t) => pred((t.querySelector('.kbb-card-nm')?.textContent || '').trim()));
      const short = named((n) => n === 'Toner');
      const long = named((n) => n.length > 80 && n.length < 100);
      const longest = named((n) => n.length > 120);

      const r = (e) => {
        const b = e.getBoundingClientRect();

        return { x: b.x, y: b.y + window.scrollY, w: px(b.width), h: px(b.height) };
      };
      const part = (e, sel) => {
        const c = e.querySelector(sel);

        return c ? px(c.getBoundingClientRect().height) : null;
      };
      const a = r(short);
      const b = r(long);

      return {
        clip: {
          x: Math.max(0, Math.min(a.x, b.x) - 8),
          y: Math.max(0, Math.min(a.y, b.y) - 8),
          width: Math.max(a.x + a.w, b.x + b.w) - Math.min(a.x, b.x) + 16,
          height: Math.max(a.h, b.h) + 16,
        },
        short: { card: a.h, name: part(short, '.kbb-card-nm'), rate: part(short, '.kbb-card-rate'), price: part(short, '.cp') },
        long: { card: b.h, name: part(long, '.kbb-card-nm'), rate: part(long, '.kbb-card-rate'), price: part(long, '.cp') },
        longest: longest
          ? { card: px(longest.getBoundingClientRect().height), name: part(longest, '.kbb-card-nm'), chars: (longest.querySelector('.kbb-card-nm').textContent || '').trim().length }
          : null,
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      };
    });

    await page.screenshot({ path: `${OUT}/card-names-short-vs-long-${w}.png`, fullPage: true, clip: box.clip });

    console.log(
      `${w}: Toner card ${box.short.card} name ${box.short.name} rate ${box.short.rate ?? '-'} price ${box.short.price}` +
        `  |  85-char card ${box.long.card} name ${box.long.name} rate ${box.long.rate ?? '-'} price ${box.long.price}` +
        (box.longest
          ? `  |  ${box.longest.chars}-char card ${box.longest.card} name ${box.longest.name}`
          : '') +
        `  |  scrollWidth ${box.scrollWidth}/${box.clientWidth}`
    );

    await page.close();
  }

  await browser.close();
})();
