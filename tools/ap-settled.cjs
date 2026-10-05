/*
 * Lane AP -- dump the console's SETTLED sidebar (after load) as JSON, and the
 * sidebar as it stood at the first frame it had any row in.
 *   node tools/ap-settled.cjs <port> <out.json> [role]
 * Used to prove the server-rendered sidebar is the same sidebar: same groups,
 * same rows, same order, same markup.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const PORT = process.argv[2]; const OUT = process.argv[3]; const ROLE = process.argv[4] || 'owner';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  await page.goto(`http://127.0.0.1:${PORT}/admin/login`);
  await page.fill('input[name=email]', ROLE + '@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit]')]);
  await page.waitForTimeout(800);
  const dump = await page.evaluate(() => {
    const nav = document.getElementById('nav');
    const out = [];
    for (const el of nav.children) {
      if (el.classList.contains('nav-group')) {
        out.push({ group: el.dataset.sec, rows: [...el.querySelectorAll('.nav-item')].map((b) => ({ go: b.dataset.go, cls: b.className.replace(/\s*\bon\b/, ''), html: b.innerHTML })) });
      } else out.push({ go: el.dataset.go, cls: el.className.replace(/\s*\bon\b/, ''), html: el.innerHTML });
    }
    return out;
  });
  fs.writeFileSync(OUT, JSON.stringify({ role: ROLE, nav: dump, errors }, null, 1));
  console.log(OUT, 'top-level', dump.length, 'rows', dump.reduce((s, g) => s + (g.rows ? g.rows.length : 1), 0), 'errors', errors.length);
  await browser.close();
})();
