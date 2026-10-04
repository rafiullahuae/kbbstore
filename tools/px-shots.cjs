// Lane PX: the sold-out card, the review summary box and the brand capsule,
// before/after at 390 and 1280, with the numbers that matter.
// node tools/px-shots.cjs <before|after> [port]
// (getBoundingClientRect here is the SHOT HARNESS measuring, not shop code.)
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MODE = process.argv[2] || 'after';
const BASE = `http://127.0.0.1:${process.argv[3] || 10040}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-px-shots');
const PDP = '/product/pdp-heartleaf-toner/';
const GRID = '/shop/?s=Ampoule';

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const out = {};
  const prefixes = MODE === 'after' ? ['', '/ar'] : [''];
  for (const pre of prefixes) {
    for (const w of [390, 1280]) {
      const tag = `${MODE}${pre ? '-ar' : ''}`;
      const page = await browser.newPage({ viewport: { width: w, height: w === 390 ? 844 : 900 } });

      // 1. The grid: the sold-out serum, the variable ampoule beside it.
      await page.goto(BASE + pre + GRID, { waitUntil: 'networkidle' });
      out[`${tag}-grid-${w}`] = await page.evaluate(() => {
        const cards = [...document.querySelectorAll('.kbb-tile')].slice(0, 4);
        return {
          cards: cards.map((c) => {
            const pill = c.querySelector('.kbb-soldout');
            const btn = c.querySelector('.kbb-card-cart');
            const cs = btn ? getComputedStyle(btn) : null;
            return {
              name: c.querySelector('.kbb-card-nm')?.textContent.slice(0, 30),
              height: Math.round(c.getBoundingClientRect().height * 10) / 10,
              pill: pill ? { text: pill.textContent, w: Math.round(pill.getBoundingClientRect().width), h: Math.round(pill.getBoundingClientRect().height), font: getComputedStyle(pill).fontSize } : null,
              button: btn ? { text: btn.textContent, bg: cs.backgroundColor, fg: cs.color, h: Math.round(btn.getBoundingClientRect().height) } : null,
            };
          }),
          scrollWidth: document.documentElement.scrollWidth,
        };
      });
      const grid = await page.$('.kbb-tile');
      const box = await grid.boundingBox();
      const last = await (await page.$$('.kbb-tile'))[3].boundingBox();
      await page.screenshot({ path: path.join(OUT, `${tag}-grid-${w}.png`), fullPage: true,
        clip: { x: 0, y: Math.max(0, box.y - 12), width: w, height: Math.min(last.y + last.height - box.y + 24, 1400) } });

      // 2 + 3. The product page: the brand line and the review summary.
      await page.goto(BASE + pre + PDP, { waitUntil: 'networkidle' });
      out[`${tag}-pdp-${w}`] = await page.evaluate(() => {
        const b = document.querySelector('.bb-brand');
        const bc = getComputedStyle(b);
        const t = document.querySelector('.bb-title, h1');
        const s = document.querySelector('.sr-summary');
        const avg = document.querySelector('.sr-avg');
        const rows = [...document.querySelectorAll('.sr-bar-row')];
        return {
          brand: { h: Math.round(b.getBoundingClientRect().height * 10) / 10, w: Math.round(b.getBoundingClientRect().width), bg: bc.backgroundImage !== 'none' ? bc.backgroundImage.slice(0, 60) : bc.backgroundColor, size: bc.backgroundSize, radius: bc.borderRadius, padding: bc.padding, fg: bc.color, font: bc.fontSize, titleTopMinusBrandTop: Math.round(t.getBoundingClientRect().top - b.getBoundingClientRect().top) },
          summary: s ? { h: Math.round(s.getBoundingClientRect().height * 10) / 10, w: Math.round(s.getBoundingClientRect().width), padding: getComputedStyle(s).padding, avgFont: getComputedStyle(avg).fontSize, rowPitch: rows.length > 1 ? Math.round((rows[1].getBoundingClientRect().top - rows[0].getBoundingClientRect().top) * 10) / 10 : null } : null,
          scrollWidth: document.documentElement.scrollWidth,
        };
      });
      const brand = await page.$('.bb-brand');
      await brand.scrollIntoViewIfNeeded();
      const bb = await brand.boundingBox();
      await page.screenshot({ path: path.join(OUT, `${tag}-brand-${w}.png`), fullPage: true,
        clip: { x: 0, y: Math.max(0, bb.y - 40), width: w, height: w === 390 ? 220 : 260 } });
      const sr = await page.$('.sr');
      if (sr) {
        const head = await (await page.$('.sr-head')).boundingBox();
        const sum = await (await page.$('.sr-summary')).boundingBox();
        const wr = await page.$('.sr-write');
        const end = wr ? await wr.boundingBox() : sum;
        await page.screenshot({ path: path.join(OUT, `${tag}-reviews-${w}.png`), fullPage: true,
          clip: { x: 0, y: Math.max(0, head.y - 16), width: w, height: end.y + end.height - head.y + 32 } });
      }
      await page.close();
    }
  }
  fs.writeFileSync(path.join(OUT, `${MODE}-measures.json`), JSON.stringify(out, null, 1));
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
