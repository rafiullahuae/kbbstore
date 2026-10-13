const { chromium } = require('playwright');
const OUT = process.env.OUT;
const APPLE = 'M7.3 4.6c.5-.6.8-1.4.7-2.2-.7 0-1.6.5-2.1 1.1-.5.5-.9 1.4-.7 2.2.8.1 1.6-.4 2.1-1.1Zm.7 1.2c-1.2-.1-2.2.7-2.7.7-.6 0-1.4-.6-2.3-.6-1.2 0-2.3.7-2.9 1.8-1.2 2.2-.3 5.4.9 7.1.6.9 1.3 1.8 2.2 1.8.9 0 1.2-.6 2.3-.6s1.4.6 2.3.6c1 0 1.6-.9 2.2-1.7.7-1 1-1.9 1-2-.1 0-1.9-.7-1.9-2.8 0-1.7 1.4-2.5 1.5-2.6-.8-1.2-2-1.4-2.6-1.5Z';
const PAY = 'M17.9 3.2h-4.4V13h1.9V9.6h2.5c2.1 0 3.6-1.3 3.6-3.2s-1.5-3.2-3.6-3.2Zm-2.5 1.6h2.1c1.2 0 2 .6 2 1.6s-.8 1.6-2 1.6h-2.1ZM27.6 13h1.8V8c0-1.6-1.3-2.7-3.3-2.7-1.9 0-3.2 1-3.4 2.5h1.7c.2-.7.8-1.1 1.6-1.1 1 0 1.6.5 1.6 1.3v.6l-2.4.1c-2 .1-3.1 1-3.1 2.4 0 1.5 1.2 2.4 2.8 2.4 1.1 0 2.1-.5 2.6-1.4h.1Zm-3.1-2c0-.6.5-1 1.5-1.1l2-.1v.6c0 1-.9 1.7-2 1.7-.9 0-1.5-.4-1.5-1.1ZM31.3 15.7c1.8 0 2.7-.7 3.4-2.8l3.3-9.2h-1.9l-2.2 7.1h-.1l-2.2-7.1h-2l3.2 8.8-.2.5c-.3.9-.8 1.2-1.6 1.2h-.6v1.4c.1 0 .7.1.9.1Z';
const css = (bg) => `@media(max-width:900px){
 .kbb-checkout.cop-sumg .summary.cosr-open{background:${bg};border-end-start-radius:18px;border-end-end-radius:18px;border-bottom:1px solid #D6EEDF}
 .kbb-checkout.cop-sumg .summary.cosr-open .cotot,.kbb-checkout.cop-sumg .summary.cosr-open .trust{border-color:#E3F1E8}
 .kbb-checkout.cop-totonly .kbb-mobile-order.co-open .co-osbox{margin-top:16px}
 .kbb-checkout .co-osh{background:#DCEFE3;padding:12px 14px;border-radius:10px;margin:0 0 10px}
}`;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' });
  const pg = await ctx.newPage();
  await pg.goto(process.env.BASE + '/shop/', { waitUntil: 'networkidle' });
  await pg.evaluate(async () => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); for (const id of [683,684,685]) await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: id, quantity: 1 }) }); });
  const res = {};
  for (const [opt, bg] of (process.env.ONLY === 'A' ? [['A', '#FBFDFC']] : process.env.ONLY === 'B' ? [['B', '#F4FAF6']] : [['A', '#FBFDFC'], ['B', '#F4FAF6'], ['now', null]])) {
    await pg.goto(process.env.BASE + '/checkout/', { waitUntil: 'networkidle' });
    if (bg) await pg.addStyleTag({ content: css(bg) });
    await pg.addStyleTag({ content: 'header{position:static!important}' });
    await pg.evaluate(() => { const li = document.querySelector('[data-kbb-ap-li]'); if (li) li.hidden = false; });
    await pg.click('#kbbSumRow'); await pg.waitForTimeout(400);
    await pg.fill('#kbb_coupon_code', 'glow'); await pg.click('#kbb_apply_coupon'); await pg.waitForTimeout(1500);
    const s = await pg.$('#kbbSummary'); const bb = await s.boundingBox();
    await pg.screenshot({ path: `${OUT}/top-summary-${opt}.png`, clip: { x: 0, y: 0, width: 390, height: Math.min(bb.y + bb.height + 140, 1400) }, fullPage: true });
    if (opt === 'A') {
      // the box at the foot, opened
      const foot = pg.locator('.kbb-mobile-order'); await foot.scrollIntoViewIfNeeded();
      await pg.locator('.kbb-mobile-order .co-ta:visible').first().click(); await pg.waitForTimeout(400);
      const [pb, fb] = await pg.evaluate(() => [document.querySelector('#payment'), document.querySelector('.kbb-mobile-order')].map((e) => { const r = e.getBoundingClientRect(); return { y: r.top + scrollY, height: r.height }; }));
      await pg.screenshot({ path: `${OUT}/bottom-summary-new.png`, clip: { x: 0, y: pb.y + pb.height - 260, width: 390, height: Math.min(260 + (fb.y + fb.height - pb.y - pb.height) + 20, 1500) }, fullPage: true });
    }
    if (opt === 'now') {
      const foot = pg.locator('.kbb-mobile-order'); await foot.scrollIntoViewIfNeeded();
      await pg.locator('.kbb-mobile-order .co-ta:visible').first().click(); await pg.waitForTimeout(400);
      const [pb, fb] = await pg.evaluate(() => [document.querySelector('#payment'), document.querySelector('.kbb-mobile-order')].map((e) => { const r = e.getBoundingClientRect(); return { y: r.top + scrollY, height: r.height }; }));
      await pg.screenshot({ path: `${OUT}/bottom-summary-now.png`, clip: { x: 0, y: pb.y + pb.height - 260, width: 390, height: Math.min(260 + (fb.y + fb.height - pb.y - pb.height) + 20, 1500) }, fullPage: true });
    }
  }
  // The Apple Pay mark: measure, raise the apple onto the baseline, compare.
  await pg.goto(process.env.BASE + '/checkout/', { waitUntil: 'networkidle' });
  await pg.addStyleTag({ content: css('#FBFDFC') });
  res.mark = await pg.evaluate(({ APPLE, PAY }) => {
    const li = document.querySelector('[data-kbb-ap-li]'); li.hidden = false;
    const svg = li.querySelector('.pay-logo-applepay svg');
    svg.innerHTML = `<path d="${APPLE}"/><path d="${PAY}"/>`;
    const [a, p] = svg.querySelectorAll('path'); const ab = a.getBBox(), pbx = p.getBBox();
    return { apple: [ab.y, ab.y + ab.height], pay: [pbx.y, pbx.y + pbx.height] };
  }, { APPLE, PAY });
  // The P's baseline is y=13 in this artwork; the apple's bottom goes there.
  // Scale the apple to 1.2x the P's cap height (as Apple's own mark), base on the baseline.
  const ah = res.mark.apple[1] - res.mark.apple[0], cap = 13 - res.mark.pay[0];
  res.k = +((cap * 1.2) / ah).toFixed(3);
  res.dy = +(13.05 - res.mark.apple[1] * res.k).toFixed(2);
  const li = pg.locator('[data-kbb-ap-li]'); await li.scrollIntoViewIfNeeded();
  await li.screenshot({ path: `${OUT}/apple-mark-now.png` });
  await pg.evaluate(({ dy, k }) => { const a = document.querySelector('[data-kbb-ap-li] .pay-logo-applepay svg path'); a.setAttribute('transform', `translate(${(1 - k) * 4.5} ${dy}) scale(${k})`); }, { dy: res.dy, k: res.k });
  await li.screenshot({ path: `${OUT}/apple-mark-new.png` });
  // Zoomed, side by side with the words exactly as drawn.
  await pg.evaluate(() => { const g = document.querySelector('[data-kbb-ap-li] .pay-logo-applepay'); g.style.zoom = '3'; });
  await li.screenshot({ path: `${OUT}/apple-mark-new-zoom.png` });
  console.log(JSON.stringify(res)); await b.close();
})();
