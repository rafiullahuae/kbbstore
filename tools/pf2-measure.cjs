// Lane PF2: what the homepage banner and the first card row actually load.
//
//   node tools/pf2-measure.cjs <base> [path]
//
// For 1280/1440/1920 at DPR 1 and 2, and 390 at DPR 1/2/3: the banner frame's
// rendered width, the candidate the browser chose (currentSrc) and the bytes
// every banner-picture response carried on the wire. Then, at 390 and 1280,
// each product-card image's section, its top edge, its loading attribute and
// whether it is inside the first viewport. Reads layout ONCE after load from a
// test harness -- nothing here ships.
const { chromium } = require('playwright');
(async () => {
  const base = process.argv[2] || 'http://127.0.0.1:9886';
  const path = process.argv[3] || '/';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const runs = [[1280, 1], [1280, 2], [1440, 1], [1440, 2], [1920, 1], [1920, 2], [390, 1], [390, 2], [390, 3]];
  for (const [w, dpr] of runs) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: dpr });
    const p = await ctx.newPage();
    const got = [];
    p.on('response', async (r) => {
      const u = r.url();
      if (!/banner-\d/.test(u)) return;
      let n = 0;
      try { n = (await r.body()).length; } catch (e) { n = -1; }
      got.push([u.replace(base, ''), n]);
    });
    await p.goto(base + path, { waitUntil: 'networkidle' });
    await p.waitForTimeout(400);
    const m = await p.evaluate(() => {
      const vp = document.querySelector('.kbbs-vp');
      const img = document.querySelector('.kbbs-vp img');
      return {
        frame: vp ? Math.round(vp.getBoundingClientRect().width * 100) / 100 : null,
        img: img ? Math.round(img.getBoundingClientRect().width * 100) / 100 : null,
        cur: img ? img.currentSrc.replace(location.origin, '') : null,
        sizes: img ? img.getAttribute('sizes') : null,
      };
    });
    const total = got.reduce((s, [, n]) => s + n, 0);
    console.log(`banner ${w}@${dpr}x frame=${m.frame} img=${m.img} chose=${m.cur} bytes=${total} (${got.map(g => g[0].replace(/.*\/(img-cache\/\d+\/)?uploads\/pf\//, (a, c) => c || '') + ':' + g[1]).join(' ')})`);
    await ctx.close();
  }
  for (const w of [390, 1280]) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: 1 });
    const p = await ctx.newPage();
    await p.goto(base + path, { waitUntil: 'networkidle' });
    const rows = await p.evaluate(() => [...document.querySelectorAll('img.kbb-card-img')].slice(0, 8).map((i) => {
      const r = i.getBoundingClientRect();
      const sec = i.closest('section');
      return [sec ? (sec.className || '').split(' ')[0] : '-', Math.round(r.left), Math.round(r.top), Math.round(r.width) + 'x' + Math.round(r.height),
        i.getAttribute('loading'), i.getAttribute('width') + 'x' + i.getAttribute('height'), r.top < innerHeight && r.left < innerWidth && r.right > 0 ? 'IN-VIEW' : ''];
    }));
    console.log(`cards ${w}: ` + JSON.stringify(rows));
    await ctx.close();
  }
  await b.close();
})();
