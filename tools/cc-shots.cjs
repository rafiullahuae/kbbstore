/* Lane CC: the opening homepage at 390 and 1280, before (:8840) and after (:8860), pictures on,
 * animations paused after load; viewport PNGs into docs/lane-cc-shots and a pixel diff of each pair.
 *   node tools/cc-shots.cjs */
const path = require('path'); const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const OUT = path.join(__dirname, '..', 'docs/lane-cc-shots');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const tool = await (await b.newContext()).newPage();
  for (const w of [390, 1280]) {
    const shots = {};
    for (const [lab, port] of [['before', 8840], ['after', 8860]]) {
      const ctx = await b.newContext({ viewport: { width: w, height: w < 800 ? 844 : 800 }, deviceScaleFactor: 1, isMobile: w < 800, hasTouch: w < 800 });
      await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, (r) => r.fulfill({ status: 200, body: '' }));
      const p = await ctx.newPage(); await p.goto('http://127.0.0.1:' + port + '/', { waitUntil: 'load' });
      await p.addStyleTag({ content: '*,*::before,*::after{animation-play-state:paused!important;animation-delay:0s!important;animation-duration:0s!important;transition:none!important}' });
      await p.waitForTimeout(1200);
      const m = await p.evaluate(() => ({ inline: !!document.getElementById('kbb-css-inline'), sheets: [...document.querySelectorAll('link[rel=stylesheet]')].map((l) => l.href.replace(/^.*\//, '')), scrollWidth: document.documentElement.scrollWidth,
        cls: performance.getEntriesByType('layout-shift').reduce((s, e) => s + (e.hadRecentInput ? 0 : e.value), 0) }));
      const f = path.join(OUT, `home-${w}-${lab}.png`); await p.screenshot({ path: f }); shots[lab] = fs.readFileSync(f).toString('base64');
      console.log(w, lab, JSON.stringify(m)); await ctx.close();
    }
    const d = await tool.evaluate(async ([a, c]) => { const L = (x) => new Promise((r) => { const i = new Image(); i.onload = () => r(i); i.src = 'data:image/png;base64,' + x; });
      const [x, y] = await Promise.all([L(a), L(c)]); const px = (i) => { const cv = document.createElement('canvas'); cv.width = i.width; cv.height = i.height; const g = cv.getContext('2d'); g.drawImage(i, 0, 0); return g.getImageData(0, 0, i.width, i.height).data; };
      const P = px(x), Q = px(y); let n = 0; for (let k = 0; k < P.length; k += 4) if (P[k] !== Q[k] || P[k + 1] !== Q[k + 1] || P[k + 2] !== Q[k + 2]) n++; return n; }, [shots.before, shots.after]);
    console.log(w, 'differing pixels before/after:', d);
  }
  await b.close();
})();
