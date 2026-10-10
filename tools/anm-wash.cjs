/* Lane AN: the page wash, before and after, at the same moments of its 300 s
   cycle (everything on the page hidden but the wash). node tools/anm-wash.cjs BEFORE AFTER */
const { chromium } = require('playwright');
const [BEFORE, AFTER] = process.argv.slice(2);
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const cmp = await browser.newPage();
  for (const [w, h] of [[390, 844], [1280, 800]]) for (const t of [8000, 30000, 60000, 99000, 131000, 170000, 200000, 250000, 290000]) {
    const shots = [];
    for (const base of [BEFORE, AFTER]) {
      const p = await browser.newPage({ viewport: { width: w, height: h } });
      await p.goto(base + '/product/co-glow-serum/', { waitUntil: 'networkidle' });
      await p.addStyleTag({ content: 'body > *{visibility:hidden!important}' });
      await p.evaluate(t => document.getAnimations().forEach(a => { a.pause(); a.currentTime = t; }), t);
      await p.waitForTimeout(150);
      shots.push((await p.screenshot()).toString('base64')); await p.close();
    }
    const r = await cmp.evaluate(async ([a, b]) => {
      const px = async s => { const i = new Image(); await new Promise(r => { i.onload = r; i.src = 'data:image/png;base64,' + s; }); const c = new OffscreenCanvas(i.width, i.height), x = c.getContext('2d'); x.drawImage(i, 0, 0); return x.getImageData(0, 0, i.width, i.height).data; };
      const da = await px(a), db = await px(b); let diff = 0, max = 0;
      for (let k = 0; k < da.length; k += 4) { const m = Math.max(Math.abs(da[k] - db[k]), Math.abs(da[k + 1] - db[k + 1]), Math.abs(da[k + 2] - db[k + 2])); if (m) diff++; if (m > max) max = m; }
      return { pixels: da.length / 4, diff, max };
    }, shots);
    console.log(JSON.stringify({ w, t, ...r }));
  }
  await browser.close();
})();
