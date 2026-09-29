/*
 * LANE PG2 — one tile, measured part by part, with the quick-view pill shown.
 *
 * The contact sheets answer "which of these do I want". This answers "is the
 * card actually built the way it looks", which is a different question and the
 * one that caught two defects:
 *
 *   the quick-view pill measured 231x231 — the whole photograph — because an
 *   absolutely positioned grid child resolves an `auto` offset to the edge of
 *   its grid area, so `top:auto` became the photograph's top edge and the box
 *   stretched down to `bottom:10px`
 *
 *   the button and the heart have to add up: button width + gap + heart width
 *   must equal the text column's content box, or one of them is overlapping
 *   something. 143 + 12 + 44 = 199 = 231 - 2x16 at 1280.
 *
 * It hovers the first tile, because the pill is invisible until then.
 *
 * A HARNESS, not the shop: rule 4 forbids the shop's own JavaScript from
 * measuring layout, and measuring it from outside is how the calc() is checked.
 */
const { chromium } = require('playwright');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8931';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.KBB_SHOTS || '/home/user/lane-pg2/docs/pg2-shots';
const PATH_ = process.env.KBB_PATH || '/collections/skincare-sets/';

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });

  for (const w of [390, 1280]) {
    const page = await browser.newPage({ viewport: { width: w, height: 1000 } });
    await page.goto(BASE + PATH_, { waitUntil: 'networkidle' });
    await (await page.$('.kbb-tile')).hover();
    await page.waitForTimeout(600);

    const geo = await page.evaluate(() => {
      const t = document.querySelector('.kbb-tile');
      const r = (sel) => {
        const e = t.querySelector(sel);
        if (!e) return null;
        const b = e.getBoundingClientRect();
        return [Math.round(b.x), Math.round(b.y), Math.round(b.width), Math.round(b.height)];
      };
      const card = t.getBoundingClientRect();

      return {
        card: [Math.round(card.x), Math.round(card.y), Math.round(card.width), Math.round(card.height)],
        photo: r('.kbb-card-shot'),
        image: r('.kbb-card-img'),
        salePill: r('.kbb-badge-sale'),
        quickView: r('.qv-btn'),
        body: r('.cb'),
        price: r('.cp'),
        button: r('.kbb-card-cart'),
        heart: r('.heart'),
      };
    });

    const fits = geo.button && geo.heart && geo.body
      ? geo.button[2] + geo.heart[2] + 12 === geo.body[2] - 32 || geo.heart[1] < geo.button[1]
      : null;

    console.log(w + ': ' + JSON.stringify(geo) + '  buttonAndHeartFitTheColumn=' + fits);

    if (w === 1280) {
      await page.screenshot({
        path: `${OUT}/hover-quickview-1280.png`,
        fullPage: true,
        clip: { x: geo.card[0] - 6, y: geo.card[1] - 6, width: geo.card[2] * 2 + 30, height: geo.card[3] + 12 },
      });
    }

    await page.close();
  }

  await browser.close();
})();
