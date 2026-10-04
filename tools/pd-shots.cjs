/*
 * Lane PD — the two product pages from the owner's report, before and after,
 * at 390 and 1280, measured in Chromium. Before = the lane's base commit served
 * from a detached worktree (tools/pd-preview.sh run there on port 10120);
 * after = this branch (port 10110). Both seeded by tools/pd-seed.php.
 *
 *   node tools/pd-shots.cjs [beforeBase] [afterBase]
 *
 * Writes docs/lane-pd-shots/*.png and MEASUREMENTS.json. Every number is read
 * here, in the browser, by the harness -- the shop itself measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const OUT = path.join(__dirname, '..', 'docs', 'lane-pd-shots');
const BEFORE = process.argv[2] || 'http://127.0.0.1:10120';
const AFTER = process.argv[3] || 'http://127.0.0.1:10110';
const ANUA = '/product/anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml/';
const MEDI = '/product/medicube-collagen-booster-set-pink-edition/';

fs.mkdirSync(OUT, { recursive: true });

async function visibleContent(page, width) {
  // The panel the page shows at this width, opened past its "Read more" clamp.
  const more = page.locator('.dtabpanel.on .readmore:visible');
  if (await more.count()) { await more.first().click().catch(() => {}); }
  await page.waitForTimeout(300);
  return page.evaluateHandle(() => [...document.querySelectorAll('.dcontent')].find((el) => el.offsetParent !== null && el.getBoundingClientRect().height > 0));
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};

  for (const [label, base] of [['before', BEFORE], ['after', AFTER]]) {
    for (const width of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();

      /* 1. The ingredient columns. */
      await page.goto(base + ANUA, { waitUntil: 'networkidle' });
      let content = await visibleContent(page, width);
      const cols = await page.evaluate((el) => {
        const block = el.querySelector('.kbb-eblock');
        const outer = block.querySelector('.kbb-eblock__row--3');
        const r = (n) => Math.round(n * 10) / 10;
        const heads = [...outer.querySelectorAll('.kbb-eblock__col .kbb-eblock__heading, .kbb-eblock__col .kbb-eblock__title')].map((h) => {
          const cs = getComputedStyle(h);
          const rect = h.getBoundingClientRect();
          const range = document.createRange(); range.selectNodeContents(h);
          const lines = new Set([...range.getClientRects()].map((q) => Math.round(q.top))).size;
          return { text: h.textContent, fontSize: cs.fontSize, lineHeight: cs.lineHeight, height: r(rect.height), width: r(rect.width), lines };
        });
        const outerCols = [...outer.children].map((c) => r(c.getBoundingClientRect().width));
        const tops = [...outer.children].map((c) => Math.round(c.getBoundingClientRect().top));
        const pics = [...outer.querySelectorAll('img')].map((i) => r(i.getBoundingClientRect().width));
        return {
          blockWidth: r(block.getBoundingClientRect().width),
          columnsPerRow: new Set(tops).size === 1 ? outerCols.length : `${outerCols.length} stacked into ${new Set(tops).size} rows`,
          gridTemplateColumns: getComputedStyle(outer).gridTemplateColumns,
          columnWidths: outerCols, pictureWidths: pics, headings: heads,
          blockHeight: r(block.getBoundingClientRect().height),
          scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
        };
      }, content);
      const block = await content.evaluateHandle((el) => el.querySelector('.kbb-eblock'));
      await block.asElement().screenshot({ path: path.join(OUT, `${label}-columns-${width}.png`) });
      report[`${label}-columns-${width}`] = cols;

      /* 2. The clips. */
      await page.goto(base + MEDI, { waitUntil: 'networkidle' });
      content = await visibleContent(page, width);
      const vids = await page.evaluate(async (el) => {
        const r = (n) => Math.round(n * 10) / 10;
        const grid = el.querySelector('.kbb-dvid');
        const text = el.innerText.match(/https?:\/\/\S+/g) || [];
        if (!grid) {
          return { players: 0, addressesPrintedAsText: text, scrollWidth: document.documentElement.scrollWidth };
        }
        const v = [...grid.querySelectorAll('video')];
        await Promise.all(v.map((x) => (x.readyState >= 1 ? 0 : new Promise((ok) => { x.addEventListener('loadedmetadata', ok, { once: true }); setTimeout(ok, 4000); }))));
        return {
          players: v.length,
          boxes: v.map((x) => { const b = x.getBoundingClientRect(); return { width: r(b.width), height: r(b.height) }; }),
          sameRow: new Set(v.map((x) => Math.round(x.getBoundingClientRect().top))).size === 1,
          attributes: v.map((x) => ({ controls: x.controls, autoplay: x.autoplay, preload: x.preload, playsInline: x.playsInline, src: x.getAttribute('src') })),
          metadata: v.map((x) => ({ readyState: x.readyState, videoWidth: x.videoWidth, videoHeight: x.videoHeight, duration: x.duration })),
          addressesPrintedAsText: text,
          scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
        };
      }, content);
      report[`${label}-videos-${width}`] = vids;

      const target = await content.evaluateHandle((el) => el.querySelector('.kbb-dvid') || [...el.querySelectorAll('p')].find((p) => p.textContent.includes('.webm')) || el.lastElementChild);
      // The clips (or the letters) with the paragraph above them, below the sticky header.
      await target.asElement().evaluate((el) => { const b = el.getBoundingClientRect(); window.scrollTo({ top: b.top + window.scrollY - (860 - Math.min(b.height, 560)), behavior: 'instant' }); });
      await page.waitForTimeout(250);
      const box = await target.asElement().boundingBox();
      const clip = { x: 0, y: 150, width, height: Math.min(box.y + box.height + 30, 900) - 150 };
      await page.screenshot({ path: path.join(OUT, `${label}-videos-${width}.png`), clip });

      if (label === 'after' && vids.players) {
        // Show it moving: play the first clip for a second and shoot again.
        await page.evaluate(async (el) => { const v = el.querySelector('.kbb-dvid video'); v.muted = true; await v.play(); await new Promise((ok) => setTimeout(ok, 1200)); v.pause(); }, content);
        report[`after-videos-${width}`].playedTo = await page.evaluate((el) => el.querySelector('.kbb-dvid video').currentTime, content);
        await page.screenshot({ path: path.join(OUT, `after-videos-${width}-playing.png`), clip });
      }

      await ctx.close();
    }
  }

  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS.json'), JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
