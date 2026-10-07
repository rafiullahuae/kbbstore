/*
 * Lane PG2: how many pages does Chrome fetch ahead for one shopper?
 *   node tools/pg2g-prefetch-count.cjs LABEL
 * A laptop rests the pointer 250 ms on each of 15 product links on a category
 * page, as a shopper scanning a grid does; a phone scrolls the same page and
 * taps nothing. The preview's spd-after.log counts the requests that arrive
 * with Sec-Purpose: prefetch, i.e. full page renders nobody has opened yet.
 */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const dir = path.join(__dirname, '..', 'storage/framework/testing/lane-spd-' + process.argv[2]);
const base = 'http://127.0.0.1:' + fs.readFileSync(dir + '/port', 'utf8').trim();
const log = dir + '/spd-after.log';
const count = () => fs.readFileSync(log, 'utf8').split('\n').filter(Boolean).map(JSON.parse).filter((r) => /prefetch/.test(r.purpose)).length;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const prof of ['laptop', 'phone']) {
    const ctx = await b.newContext(prof === 'laptop' ? { viewport: { width: 1280, height: 800 } } : { viewport: { width: 390, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
    const p = await ctx.newPage();
    await p.goto(base + '/collections/spd-dept-3/', { waitUntil: 'load' });
    await p.waitForTimeout(800);
    const before = count();
    if (prof === 'laptop') {
      const links = await p.$$('.kbb-card a.cn');
      for (const a of links.slice(0, 15)) { await a.hover(); await p.waitForTimeout(250); }
    } else {
      for (let i = 0; i < 8; i++) { await p.mouse.wheel(0, 600); await p.waitForTimeout(400); }
    }
    await p.waitForTimeout(1500);
    out[prof] = count() - before;
    await ctx.close();
  }
  console.log(JSON.stringify(out));
  await b.close();
})();
