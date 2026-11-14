/*
 * Lane FBT: "Frequently bought together" on the product page, at 390 and 1280,
 * English or Arabic (FBT_AR=1). Writes <tag>-<lang>-<width>.png (the section)
 * and <tag>-<lang>-<width>-page.png (the viewport around it) into FBT_OUT,
 * and prints the measured numbers as JSON.
 *
 *   FBT_BASE=http://127.0.0.1:9347 FBT_OUT=docs/plans/frequently-bought-together/shots FBT_TAG=after \
 *     NODE_PATH=/path/to/node_modules node tools/fbt-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.FBT_BASE || 'http://127.0.0.1:9347';
const OUT = process.env.FBT_OUT || 'docs/plans/frequently-bought-together/shots';
const TAG = process.env.FBT_TAG || 'after';
const AR = process.env.FBT_AR === '1';
// (Lane FBT3) which product page; the toner by default.
const PATH = process.env.FBT_PATH || '/product/pdp-heartleaf-toner/';
const EXE = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: fs.existsSync(EXE) ? EXE : undefined });
  const lang = AR ? 'ar' : 'en';
  const out = [];

  for (const width of [390, 1280]) {
    const page = await browser.newPage({ viewport: { width, height: width < 600 ? 844 : 900 } });
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    const url = BASE + (AR ? '/ar' : '') + PATH;
    await page.goto(url, { waitUntil: 'networkidle' });
    const sec = await page.$('.kbb-fbt');
    const row = { lang, width, url, errors };

    if (sec) {
      await sec.scrollIntoViewIfNeeded();
      await page.waitForTimeout(1200);
      await sec.screenshot({ path: path.join(OUT, `${TAG}-${lang}-${width}.png`) });
      await page.screenshot({ path: path.join(OUT, `${TAG}-${lang}-${width}-page.png`) });
      Object.assign(row, await page.evaluate(() => {
        const s = document.querySelector('.kbb-fbt');
        const h = s.querySelector('h2');
        const r = s.getBoundingClientRect();
        const cards = [...s.querySelectorAll('.bt-card')];
        const names = cards.map((c) => c.querySelector('.nm').textContent.trim());
        // Every title link answers a click where it is drawn.
        const clickable = cards.map((c) => {
          const a = c.querySelector('a.lk');
          const b = a.getBoundingClientRect();
          if (b.width === 0 || b.right < 0 || b.left > innerWidth) return 'offscreen';
          const el = document.elementFromPoint(b.left + b.width / 2, b.top + Math.min(8, b.height / 2));
          return !!(el && a.contains(el));
        });
        return {
          heading: h.textContent.trim(),
          headingPx: getComputedStyle(h).fontSize,
          sectionW: Math.round(r.width), sectionH: Math.round(r.height),
          cardW: cards.length ? Math.round(cards[0].getBoundingClientRect().width) : 0,
          names, clickable,
          scrollWidth: document.documentElement.scrollWidth,
          imgs: [...s.querySelectorAll('img')].map((i) => i.getAttribute('srcset') ? 'srcset' : 'bare'),
          bg: cards.map((c) => (c.querySelector('label.im').getAttribute('style') || '').includes('img-cache') || (c.querySelector('label.im').getAttribute('style') || '').includes('data:')),
        };
      }));
    } else {
      row.section = 'absent';
      await page.screenshot({ path: path.join(OUT, `${TAG}-${lang}-${width}-page.png`) });
    }

    out.push(row);
    await page.close();
  }

  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
