const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const res = {};
  for (const [label, vp] of [['390', { width: 390, height: 1400, m: true }], ['1280', { width: 1280, height: 1400, m: false }]]) {
    const ctx = await b.newContext({ viewport: { width: vp.width, height: vp.height }, isMobile: vp.m, hasTouch: vp.m, userAgent: vp.m ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' : 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' });
    const pg = await ctx.newPage(); const errs = []; pg.on('pageerror', (e) => errs.push(e.message));
    await pg.route(/js\.stripe\.com/, (r) => r.abort());
    await pg.goto(process.env.BASE + '/shop/', { waitUntil: 'networkidle' });
    await pg.evaluate(async () => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); for (const id of [683,684,685]) await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, quantity: 1 }) }); });
    await pg.goto(process.env.BASE + '/checkout/', { waitUntil: 'domcontentloaded' }); await pg.waitForTimeout(1000);
    // Show the Express row the way Stripe does on a phone (preview has no wallet), with a 40px stand-in button.
    await pg.evaluate(() => { document.querySelectorAll('.co-xc-h,.co-xc-or').forEach((n) => { n.hidden = false; }); const r = document.querySelector('[data-kbb-express]'); if (r) { r.style.cssText = 'display:block'; r.removeAttribute('data-kbb-express-pending'); const m = r.querySelector('[data-kbb-express-mount]'); m.style.cssText = 'height:40px;background:#000;border-radius:6px'; } });
    await pg.waitForTimeout(200);
    const boxes = await pg.evaluate(() => { const q = (s) => document.querySelector(s); const r = (e) => { const b = e.getBoundingClientRect(); return { top: b.top, bottom: b.bottom, left: b.left, right: b.right }; }; const secs = document.querySelectorAll('#customer_details > .sec');
      return { cls: q('.kbb-checkout').className, gridGap: getComputedStyle(q('.co-grid')).rowGap, sum: r(q('#kbbSummary')), head: r(q('.co-xc-h')), or: r(q('.co-xc-or')), form: r(q('#customer_details')), acct: r(q('#create_account_field .kbb-acct-opt span')), dh2: r(secs[1].querySelector('h2')), slot: r(q('#kbbDeliverySlot')), ph2: r(secs[2].querySelector('h2')), secPads: [...secs].map((s) => getComputedStyle(s).paddingBottom), scrollW: document.documentElement.scrollWidth }; });
    fs.writeFileSync(process.env.S + `/real-${label}.json`, JSON.stringify(boxes));
    fs.writeFileSync(process.env.S + `/real-${label}.png`, await pg.screenshot({ clip: { x: 0, y: 0, width: vp.width, height: vp.height } }));
    res[label] = { gridGap: boxes.gridGap, secPads: boxes.secPads, scrollW: boxes.scrollW, eqgap: boxes.cls.includes('cop-eqgap'), errors: errs };
    await ctx.close();
  }
  console.log(JSON.stringify(res)); await b.close();
})();
