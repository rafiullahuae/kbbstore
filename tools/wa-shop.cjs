// Storefront shots + measurements for Lane WA. Usage: node shop.cjs <port> <outdir> [tag]
const { chromium } = require(require('path').join(__dirname, '..', 'node_modules', 'playwright'));
const port = process.argv[2] || 9950, out = process.argv[3], tag = process.argv[4] || '';
(async () => {
  const b = await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const base = 'http://127.0.0.1:' + port;
  const pages = (process.env.PAGES || 'home=/,product=/product/pdp-heartleaf-toner/').split(',').map(s => s.split('='));
  const widths = (process.env.WIDTHS || '390,1280').split(',').map(Number);
  for (const [name, path] of pages) for (const w of widths) {
    const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 860 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2, reducedMotion: process.env.RM ? 'reduce' : 'no-preference' });
    const p = await ctx.newPage();
    if (process.env.ADD) { await p.goto(base + '/product/pdp-heartleaf-toner/', { waitUntil: 'networkidle' }); await p.locator('.addcart:visible').first().click(); await p.waitForTimeout(1500); await p.evaluate(() => { try { localStorage.clear(); } catch (e) {} }); }
    await p.goto(base + path, { waitUntil: 'networkidle' });
    if (process.env.SCROLL) await p.evaluate(y => window.scrollTo(0, y), +process.env.SCROLL);
    await p.waitForTimeout(+(process.env.WAIT || 2600));
    const m = await p.evaluate(() => {
      const r = x => x && (({left,top,right,bottom,width,height}) => ({left:Math.round(left),top:Math.round(top),right:Math.round(innerWidth-right),bottom:Math.round(innerHeight-bottom),w:Math.round(width),h:Math.round(height)}))(x.getBoundingClientRect());
      const wrap = document.getElementById('kbbWa');
      return { footprint: r(wrap), button: r(document.querySelector('.kbw-a')), capsule: r(document.querySelector('.kbw-c')), bubble: r(document.getElementById('kbbWaB')),
        orbitDeg: (() => { const o = document.querySelector('.kbw-o'); if (!o) return null; const m = getComputedStyle(o).transform.match(/matrix\(([^,]+), ([^,]+)/); return m ? Math.round(Math.atan2(+m[2], +m[1]) * 180 / Math.PI) : 0; })(),
        lift: wrap && getComputedStyle(wrap).marginBottom, sticky: !!document.querySelector('#stickybar.show'), docked: !!document.querySelector('.cpg-docked'),
        dir: document.documentElement.dir, href: document.querySelector('.kbw-a') && document.querySelector('.kbw-a').href.slice(0, 80),
        scrollWidth: document.documentElement.scrollWidth, innerWidth };
    });
    const f = `${out}/${tag}${name}-${w}.png`;
    await p.screenshot({ path: f });
    if (process.env.SECOND) { await p.waitForTimeout(+process.env.SECOND); const deg = await p.evaluate(() => { const o = document.querySelector('.kbw-o'); const m = o && getComputedStyle(o).transform.match(/matrix\(([^,]+), ([^,]+)/); return m ? Math.round(Math.atan2(+m[2], +m[1]) * 180 / Math.PI) : null; }); const f2 = f.replace('.png', '-later.png'); await p.screenshot({ path: f2 }); console.log(f2, 'orbitDeg', deg); }
    console.log(f, JSON.stringify(m));
    await ctx.close();
  }
  await b.close();
})();
