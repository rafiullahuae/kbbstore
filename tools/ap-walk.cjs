/*
 * Lane AP -- open every sidebar row and record what drew.
 *   node tools/ap-walk.cjs <port> <out.json>
 * For each row (and the pinned Console row): the title, the size of what the
 * screen drew into #content, and every page error / console error raised while
 * it opened. Run once with the console's static blocks inline and once with
 * them served as files (KBB_ADMIN_EXTERNAL_ASSETS) and compare: the two must
 * draw the same screens with the same errors.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const PORT = process.argv[2]; const OUT = process.argv[3];
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36' });
  const page = await ctx.newPage();
  let errs = [];
  page.on('pageerror', (e) => errs.push('pageerror: ' + String(e).slice(0, 160)));
  page.on('console', (m) => { if (m.type() === 'error') errs.push('console: ' + m.text().slice(0, 160)); });
  page.on('response', (r) => { if (r.status() >= 400) errs.push('http ' + r.status() + ' ' + r.url().replace(/^https?:\/\/[^/]+/, '')); });
  await page.goto(`http://127.0.0.1:${PORT}/admin/login`);
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.waitForTimeout(1200);
  const out = { boot: errs, external: await page.evaluate(() => document.querySelectorAll('script[src*="admin-"],link[href*="admin-"]').length), screens: {} };
  const ids = await page.$$eval('#nav .nav-item, .side-pin .nav-item', (bs) => bs.map((b) => b.dataset.go));
  for (const id of ids) {
    errs = [];
    await page.evaluate((id) => { const b = document.querySelector('.side [data-go="' + id + '"]'); b.click(); }, id);
    await page.waitForTimeout(1300);
    const r = await page.evaluate(() => ({ title: document.getElementById('ptitle').textContent, crumb: document.getElementById('crumb').textContent, drawn: document.getElementById('content').innerHTML.length > 400, lit: (document.querySelector('#nav .nav-item.on') || {}).dataset?.go || null }));
    out.screens[id] = { ...r, errors: errs };
  }
  fs.writeFileSync(OUT, JSON.stringify(out, null, 1));
  const bad = Object.entries(out.screens).filter(([, v]) => v.errors.length || !v.drawn);
  console.log(OUT, 'external', out.external, 'screens', ids.length, 'with errors/undrawn', bad.length, bad.map(([k, v]) => k + (v.drawn ? '' : '(undrawn)') + ':' + v.errors.join('|')).join('\n'));
  await browser.close();
})();
