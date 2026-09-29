/*
 * LANE PG2 — the one picture that answers "what happens to a long name".
 *
 * The fixture's last row holds `Toner` and a ninety-character title, side by
 * side, so the clip below is the whole of the owner's "if the product titles
 * goes long, still the product grid height must remain equal and adjusted" in
 * one frame. The numbers beside it are read off the same two tiles: the card
 * heights and the two name boxes, which are equal because the clamp reserves
 * two lines whether or not the text fills them.
 */
const { chromium } = require('playwright');
const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8931';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.KBB_SHOTS || '/home/user/lane-pg2/docs/pg2-shots';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });

  for (const w of [390, 1280]) {
    const page = await browser.newPage({ viewport: { width: w, height: 1000 } });
    await page.goto(BASE + '/collections/skincare-sets/', { waitUntil: 'networkidle' });

    const box = await page.evaluate(() => {
      const tiles = [...document.querySelectorAll('.kbb-tile')];
      const short = tiles.find((t) => (t.querySelector('.kbb-card-nm')?.textContent || '') === 'Toner');
      const long = tiles.find((t) => (t.querySelector('.kbb-card-nm')?.textContent || '').length > 80);
      const r = (e) => { const b = e.getBoundingClientRect(); return { x: b.x, y: b.y + window.scrollY, w: b.width, h: b.height }; };
      const a = r(short), b = r(long);
      const nh = (e) => Math.round(e.querySelector('.kbb-card-nm').getBoundingClientRect().height);

      return {
        clip: { x: Math.min(a.x, b.x) - 8, y: Math.min(a.y, b.y) - 8, width: Math.max(a.x + a.w, b.x + b.w) - Math.min(a.x, b.x) + 16, height: Math.max(a.h, b.h) + 16 },
        shortHeight: Math.round(a.h), longHeight: Math.round(b.h),
        shortNameBox: nh(short), longNameBox: nh(long),
      };
    });

    await page.screenshot({ path: `${OUT}/names-short-vs-long-${w}.png`, fullPage: true, clip: box.clip });
    console.log(`${w}: card ${box.shortHeight} vs ${box.longHeight}, name box ${box.shortNameBox} vs ${box.longNameBox}`);
    await page.close();
  }

  await browser.close();
})();
