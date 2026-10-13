const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = {};
  for (const [label, vp] of [['1280', { width: 1280, height: 1000, m: false }], ['390', { width: 390, height: 844, m: true }]]) {
    const ctx = await b.newContext({ viewport: { width: vp.width, height: vp.height }, isMobile: vp.m, hasTouch: vp.m, deviceScaleFactor: 2, userAgent: vp.m ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' });
    const pg = await ctx.newPage(); const errs = []; pg.on('pageerror', (e) => errs.push(e.message));
    await pg.route(/js\.stripe\.com/, (r) => r.abort());
    await pg.goto(process.env.BASE + '/shop/', { waitUntil: 'networkidle' });
    await pg.evaluate(async () => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); for (const id of [683,684,685]) await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, quantity: 1 }) }); });
    await pg.goto(process.env.BASE + '/checkout/', { waitUntil: 'domcontentloaded' }); await pg.waitForTimeout(1200);
    const read = () => pg.evaluate(() => { const s = document.getElementById('kbbSummary'), r = s.querySelector('.cosr'), p = document.getElementById('kbbPanels'); const sb = s.getBoundingClientRect(), rb = r.getBoundingClientRect(), pb = p.getBoundingClientRect(); const c = getComputedStyle;
      return { card: [Math.round(sb.left), Math.round(sb.width)], row: [Math.round(rb.left), Math.round(rb.width), Math.round(rb.height)], rowBg: c(r).backgroundColor, cardBg: c(s).backgroundColor, panels: [Math.round(pb.left), Math.round(pb.width), Math.round(pb.top - rb.bottom)], scrollW: document.documentElement.scrollWidth }; });
    out[label] = { closed: await read() };
    if (!vp.m) await pg.screenshot({ path: process.env.OUT + '/desktop-closed-1280.png', clip: { x: 700, y: 60, width: 520, height: 560 } });
    await pg.click('#kbbSumRow'); await pg.waitForTimeout(500);
    out[label].open = await read();
    if (!vp.m) await pg.screenshot({ path: process.env.OUT + '/desktop-open-1280.png', clip: { x: 700, y: 60, width: 520, height: 900 } });
    out[label].errors = errs; await ctx.close();
  }
  console.log(JSON.stringify(out)); await b.close();
})();
