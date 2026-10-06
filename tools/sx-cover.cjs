/*
 * Lane SX: with each mega item hovered, how much of every OTHER menu link can
 * still be hit? A 7x5 grid of points over each other link, elementFromPoint.
 * Also: does the hovered item's panel still open, and is a straight move down
 * from its link into its panel kept (the panel's link under the pointer)?
 *   node sx-cover.cjs label,label [width]
 */
const path = require('path'); const fs = require('fs');
const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const LABELS = (process.argv[2] || 'sxgood,sxhead,sxfix').split(',');
const W = Number(process.argv[3] || 1280);
const dir = (l) => path.join(APP, 'storage/framework/testing/lane-spd-' + l);
const base = (l) => 'http://127.0.0.1:' + fs.readFileSync(dir(l) + '/port', 'utf8').trim();

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const l of LABELS) {
    const page = await browser.newPage({ viewport: { width: W, height: 800 } });
    await page.goto(base(l) + (process.env.SX_PAGE || '/collections/spd-dept-1/'), { waitUntil: 'load' });
    await page.waitForTimeout(400);
    const items = await page.$$eval('.mbar .wrap > .navitem', (ns) => ns.map((n, i) => ({ i, label: n.querySelector('.navlink').textContent.trim().split(/\s/)[0], drop: !!n.querySelector('.drop') })));
    let hit = 0; let total = 0; let open = 0; let down = 0; let megas = 0;
    for (const it of items.filter((x) => x.drop)) {
      megas++;
      const nl = page.locator('.mbar .wrap > .navitem > .navlink').nth(it.i);
      const b = await nl.boundingBox();
      await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2);
      await page.waitForTimeout(250);
      const r = await page.evaluate((idx) => {
        const ns = [...document.querySelectorAll('.mbar .wrap > .navitem')];
        const me = ns[idx];
        let h = 0; let t = 0;
        ns.forEach((n, k) => {
          if (k === idx) return;
          const a = n.querySelector('.navlink'); const rc = a.getBoundingClientRect();
          for (let gx = 1; gx <= 7; gx++) for (let gy = 1; gy <= 5; gy++) {
            const e = document.elementFromPoint(rc.left + rc.width * gx / 8, rc.top + rc.height * gy / 6);
            t++; if (e && a.contains(e)) h++;
          }
        });
        const d = me.querySelector('.drop'); const cs = getComputedStyle(d);
        return { h, t, open: cs.visibility === 'visible' };
      }, it.i);
      hit += r.h; total += r.t; if (r.open) open++;
      // straight down from the link into the panel, then check what is under the pointer
      await page.mouse.move(b.x + b.width / 2, b.y + b.height + 30, { steps: 6 });
      await page.waitForTimeout(250);
      const inPanel = await page.evaluate(([x, y, idx]) => { const e = document.elementFromPoint(x, y); const d = document.querySelectorAll('.mbar .wrap > .navitem')[idx].querySelector('.drop'); return !!(e && d.contains(e)) && getComputedStyle(d).visibility === 'visible'; }, [b.x + b.width / 2, b.y + b.height + 30, it.i]);
      if (inPanel) down++;
      await page.mouse.move(5, 790); await page.waitForTimeout(250);
    }
    console.log(JSON.stringify({ ref: l, page: process.env.SX_PAGE || '/collections/spd-dept-1/', width: W, megaItems: megas, otherLinkPointsHittable: hit + '/' + total, pct: Math.round(1000 * hit / total) / 10, panelsOpenOnHover: open + '/' + megas, straightDownStaysInPanel: down + '/' + megas }));
    await page.close();
  }
  await browser.close();
})();
