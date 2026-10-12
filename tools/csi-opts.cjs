/* Lane CSI2: "Already a member? Sign in" options on the real checkout. BASE=... PID=... node tools/csi-opts.cjs */
const { chromium } = require('playwright');
const path = require('path'); const fs = require('fs');
const BASE = process.env.BASE, PID = process.env.PID, OUT = path.resolve(__dirname, '../docs/lane-csi-options');
fs.mkdirSync(OUT, { recursive: true });
const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const OPTS = {
  A: { html: 'Already a member? <u>Sign in</u>', css: 'font-size:12px;font-weight:400;color:#a3a3a3;text-decoration:none' },
  B: { html: 'Already a member? <u style="color:#7a7a7a">Sign in</u>', css: 'font-size:12px;font-weight:400;color:#b0b0b0;text-decoration:none' },
  C: { html: 'Already a member? Sign in ›', css: 'font-size:11.5px;font-weight:400;color:#a8a8a8;text-decoration:none' },
  D: { html: 'Already a member? <span style="font-weight:500;color:#8a8a8a">Sign in</span>', css: 'font-size:11.5px;font-weight:400;color:#b5b5b5;text-decoration:none' },
};
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: w, height: 900 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
    await page.evaluate(async (pid) => { const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || ''); await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ product_id: +pid, qty: 1 }) }); }, PID);
    await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    const sec = page.locator('.kbb-checkout .sec').filter({ has: page.locator('.co-signin') }).first();
    await sec.scrollIntoViewIfNeeded();
    for (const [k, o] of Object.entries(OPTS)) {
      await page.evaluate((o) => { const a = document.querySelector('.co-signin'); a.className = 'co-signin'; a.innerHTML = o.html; a.style.cssText = o.css; }, o);
      const hb = await sec.boundingBox();
      await page.screenshot({ path: `${OUT}/${k}-${w}.png`, clip: { x: w === 390 ? 0 : hb.x - 10, y: hb.y - 12, width: w === 390 ? w : hb.width + 20, height: 150 } });
    }
    await ctx.close();
  }
  await browser.close();
})();
