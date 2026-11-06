// 2.60.649 integration check: the occasion banner AND the gift strip on together.
const { chromium } = require('playwright');
const BASE = process.argv[2], OUT = process.argv[3];
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const r = {};
  for (const [name, path, w, h] of [['home-1280', '/', 1280, 900], ['home-390', '/', 390, 844], ['home-844x390', '/', 844, 390], ['ar-390', '/ar/', 390, 844]]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, userAgent: UA });
    const p = await ctx.newPage(); const errs = [];
    p.on('console', m => { if (m.type() === 'error') errs.push(m.text()); });
    await p.goto(BASE + path, { waitUntil: 'networkidle' }); await p.waitForTimeout(1200);
    await p.evaluate(() => document.querySelectorAll('[class*=wa-],[class*=whats],[id*=whats]').forEach(e => e.style.visibility = 'hidden'));
    r[name] = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, banner: !!document.querySelector('[class*=ohb],[class*=occ]'), strip: !!document.querySelector('.gs, [class*=gift-strip], [class*=gstr]') }));
    r[name].errors = errs;
    await p.screenshot({ path: `${OUT}/${name}.png`, fullPage: false, clip: { x: 0, y: 0, width: w, height: Math.min(h * 1.6, 1400) } }).catch(() => p.screenshot({ path: `${OUT}/${name}.png` }));
    await ctx.close();
  }
  console.log(JSON.stringify(r, null, 1)); await b.close();
})();
