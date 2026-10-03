/*
 * Lane RG: the buy column's reordered mode, forced ON in the DEFAULT order,
 * must draw every block exactly where normal flow draws it. Harness-only:
 * the classes are added here, as ProductDesktopSections would print them.
 *   RG_BASE=http://127.0.0.1:9870 node tools/rg-reorder-check.cjs [slug]
 */
const { chromium } = require('playwright');
const { measure } = require('./rg-measure.cjs');
const BASE = process.env.RG_BASE || 'http://127.0.0.1:9870';
const slug = process.argv[2] || 'pdp-heartleaf-toner';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const res = {};
  for (const w of [1280, 1440]) {
    const p = await b.newPage({ viewport: { width: w, height: 900 } });
    await p.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
    const leaves = () => p.evaluate(() => [...document.querySelectorAll('.pdp .buybox *')].filter((e) => !e.children.length && e.getBoundingClientRect().height > 0).map((e) => { const r = e.getBoundingClientRect(); return Math.round(r.top + scrollY) + ',' + Math.round(r.left) + ',' + Math.round(r.width) + ',' + Math.round(r.height); }).join('|'));
    const flowLeaves = await leaves();
    const flow = (await measure(p)).buy;
    await p.evaluate(() => { document.querySelector('.pdp-page').classList.add('pdsb-on', 'pdsb-f-title'); });
    const forced = (await measure(p)).buy;
    const forcedLeaves = await leaves();
    const diff = Object.keys(flow).filter((k) => JSON.stringify(flow[k]) !== JSON.stringify(forced[k]));
    res[w] = { leavesIdentical: flowLeaves === forcedLeaves, leaves: flowLeaves.split('|').length, identical: diff.length === 0, diff: diff.map((k) => ({ k, flow: flow[k], forced: forced[k] })) };
    await p.close();
  }
  console.log(JSON.stringify(res));
  await b.close();
})();
