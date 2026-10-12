/* Lane CSM: "Already a member? Sign in" (option B) on the checkout Contact bar. BASE=... PID=... node tools/csm-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const BASE = process.env.BASE, PID = process.env.PID, OUT = path.resolve(__dirname, '../docs/lane-csm-shots');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 2 });
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
      const q = document.querySelector('.co-signin-q'), a = document.querySelector('.co-signin'), h = q.parentElement;
      const qr = q.getBoundingClientRect(), ar = a.getBoundingClientRect(), hr = h.getBoundingClientRect();
      const hit = document.elementFromPoint(ar.x + ar.width / 2, ar.y + ar.height / 2);
      const cq = getComputedStyle(q), ca = getComputedStyle(a);
      return { lead: q.textContent, leadColor: cq.color, leadSize: cq.fontSize, linkColor: ca.color, linkWeight: ca.fontWeight, underline: ca.textDecorationLine,
        gap: Math.round(ar.left - qr.right), oneLine: Math.abs(qr.top - ar.top) < 4, barH: Math.round(hr.height), linkInsideBar: ar.right <= hr.right, hit: hit === a || a.contains(hit), scrollW: document.documentElement.scrollWidth };
    });
    await a_click(page);
    const dlg = await page.evaluate(() => { const d = document.getElementById('kbbSignIn'); return !!(d && d.open); });
    console.log(w, JSON.stringify({ ...m, signInWindowOpens: dlg, errors }));
    await ctx.close();
  }
  await browser.close();
})();
async function a_click(page) { await page.locator('.co-signin').click(); await page.waitForTimeout(400); }
