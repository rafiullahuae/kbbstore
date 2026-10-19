const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const p = await ctx.newPage();
  p.on('response', async (r) => { if (r.status() >= 400) console.log(r.status(), r.request().method(), r.url()); });
  await p.goto('http://127.0.0.1:10570/collections/vpa-makeup/', { waitUntil: 'networkidle' });
  const card = p.locator('.kbb-tile', { hasText: 'MISSHA' }).first();
  await card.evaluate((c) => { c.scrollIntoView({ block: 'start' }); window.scrollBy(0, -90); });
  console.log(await card.locator('a.cn').evaluate((a) => { const r = a.getBoundingClientRect(); const el = document.elementFromPoint(r.left + 8, r.top + r.height / 2); return [r.left, r.top, r.width, r.height, el && el.outerHTML.slice(0, 160)]; }));
  await p.screenshot({ path: 'storage/vpa-logs/dbg.png' });
  await b.close();
})();
