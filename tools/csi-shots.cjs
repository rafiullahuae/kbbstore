/* Lane CSI: the checkout Contact bar -- no "Not you?" line, a user icon before Sign in. BASE=... PID=... node tools/csi-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = process.env.BASE, PID = process.env.PID, OUT = path.resolve(__dirname, '../docs/lane-csi-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 } });
    const page = await ctx.newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/404/.test(m.text())) errors.push(m.text()); });
    await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    await page.evaluate(async (pid) => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: +pid, qty: 1 }) }); }, PID);
    await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    const h2 = page.locator('.kbb-checkout .sec > h2').first();
    await h2.scrollIntoViewIfNeeded();
    const hb = await h2.boundingBox();
    await page.screenshot({ path: `${OUT}/contact-bar-${w}.png`, clip: { x: 0, y: Math.max(0, hb.y - 60), width: w, height: Math.min(260, hb.height + 200) } });
    await page.screenshot({ path: `${OUT}/checkout-${w}.png` });
    const m = await page.evaluate(() => {
      const a = document.querySelector('.co-signin'); const s = a && a.querySelector('svg');
      const ar = a.getBoundingClientRect(), sr = s.getBoundingClientRect();
      const t = a.lastChild; const r = document.createRange(); r.selectNodeContents(t); const tr = r.getBoundingClientRect();
      const h = document.elementFromPoint(ar.x + ar.width / 2, ar.y + ar.height / 2);
      return { notYou: !!document.getElementById('kbbRememberClear'), iconW: sr.width, iconH: sr.height, iconLeftOfWord: sr.right <= tr.left, gap: Math.round(tr.left - sr.right), linkH: ar.height, fontSize: getComputedStyle(a).fontSize, hit: h === a || a.contains(h), scrollW: document.documentElement.scrollWidth };
    });
    console.log(w, JSON.stringify({ ...m, errors }));
    await ctx.close();
  }
  await browser.close();
})();
