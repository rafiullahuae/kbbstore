/* Lane PG2: is a hover prefetch USED by the click that follows?
 *   node tools/pg2g-prefetch-used.cjs LABEL
 * Hover a product link 400 ms, click it, then read the preview's spd-after.log:
 * one request with Sec-Purpose: prefetch and none without = the click was
 * served from the prefetch. Also: does the click land on the first try. */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const dir = path.join(__dirname, '..', 'storage/framework/testing/lane-spd-' + process.argv[2]);
const base = 'http://127.0.0.1:' + fs.readFileSync(dir + '/port', 'utf8').trim();
const rows = () => fs.readFileSync(dir + '/spd-after.log', 'utf8').split('\n').filter(Boolean).map(JSON.parse);
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [from, sel] of [['/collections/spd-dept-4/', '.kbb-card a.cn'], ['/brands/anua/', '.kbb-card a.cn'], ['/product/spd-product-901/', '.kbb-card a.cn']]) {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
    const p = await ctx.newPage();
    await p.goto(base + from, { waitUntil: 'load' });
    await p.waitForTimeout(600);
    const a = (await p.$$(sel))[1];
    await a.scrollIntoViewIfNeeded();
    const href = new URL(await a.getAttribute('href'), base).pathname;
    const n0 = rows().length;
    await a.hover(); await p.waitForTimeout(400);
    await Promise.all([p.waitForURL((u) => u.pathname === href, { timeout: 15000 }), a.click()]);
    await p.waitForLoadState('load'); await p.waitForTimeout(400);
    const hits = rows().slice(n0).filter((r) => r.uri === href);
    console.log(from, '->', href, 'prefetch', hits.filter((r) => /prefetch/.test(r.purpose)).length, 'plain', hits.filter((r) => !/prefetch/.test(r.purpose)).length, 'landed', new URL(p.url()).pathname === href);
    await ctx.close();
  }
  await b.close();
})();
