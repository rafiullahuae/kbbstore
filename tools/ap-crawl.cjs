/*
 * Lane AP -- what each sidebar row READS when it opens. Logs every same-origin
 * GET a screen makes in the 1.5 s after its row is clicked, as the owner.
 *   node tools/ap-crawl.cjs <port> <out.json>
 * The first /admin-api GET of each screen is the read the role filter keys on:
 * a role sees a row only when AdminCapabilities lets it make that read.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const PORT = process.argv[2]; const OUT = process.argv[3];
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
  const page = await ctx.newPage();
  await page.goto(`http://127.0.0.1:${PORT}/admin/login`);
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.waitForTimeout(1500);
  let log = null;
  page.on('request', (r) => { if (log && r.method() === 'GET' && r.url().startsWith(`http://127.0.0.1:${PORT}/`)) log.push(r.url().replace(/^https?:\/\/[^/]+\//, '').replace(/\?.*$/, '')); });
  // frames too
  const ids = await page.$$eval('#nav .nav-item, .side-pin .nav-item', (bs) => bs.map((b) => b.dataset.go));
  const out = {};
  for (const id of ids) {
    log = [];
    await page.evaluate((id) => { try { const p = window.go(id); if (p && p.catch) p.catch(() => {}); } catch (e) {} }, id);
    await page.waitForTimeout(1500);
    out[id] = [...new Set(log)];
    log = null;
  }
  fs.writeFileSync(OUT, JSON.stringify(out, null, 1));
  console.log(Object.keys(out).length, 'screens');
  await browser.close();
})();
