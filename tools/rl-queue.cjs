/* Lane RL: reproduce "queue several moves, Save, it fails". Records every
   reorder request and its answer. BASE=http://127.0.0.1:PORT node tools/rl-queue.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE;
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const log = [];
  page.on('pageerror', (e) => log.push('PAGEERROR ' + e));
  page.on('dialog', (d) => { log.push('DIALOG ' + d.message()); d.accept(); });
  page.on('response', async (r) => { if (/catalog\/reorder\/.*\/(save-page|move|apply)/.test(r.url())) { let b = ''; try { b = (await r.text()).slice(0, 200); } catch (e) {} log.push(r.request().method() + ' ' + r.url().replace(BASE, '') + ' -> ' + r.status() + ' ' + b + ' req=' + (r.request().postData() || '')); } });
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com'); await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.evaluate(() => window.go('catalog', 'reorder'));
  await page.waitForSelector('#rePerPage', { timeout: 30000 });
  await page.selectOption('#reCat', process.env.CAT || '1'); await page.waitForTimeout(2500);
  const ids = await page.$$eval('#rlist [data-rankinput]', (els) => els.map((e) => +e.dataset.rankinput));
  // 1) red "to the end" on two rows, 2) red "to the top" on a row further down, 3) number boxes to ranks on other pages
  await page.click(`#rlist [data-rlast="${ids[2]}"]`); await page.waitForTimeout(300);
  await page.click(`#rlist [data-rlast="${ids[5]}"]`); await page.waitForTimeout(300);
  for (const [i, rank] of [[8, 120], [11, 300], [14, 77]]) {
    const inp = page.locator(`#rlist [data-rankinput="${ids[i]}"]`); await inp.fill(String(rank)); await inp.press('Enter'); await page.waitForTimeout(300);
  }
  await page.click(`#rlist [data-rdown="${ids[0]}"]`); await page.waitForTimeout(300);  // a local move too
  const pending = await page.evaluate(() => (window.reorderPending || []).length);
  log.push('pending before save: ' + pending + ', total ' + await page.evaluate(() => reorderData.total));
  await page.click('#reSave'); await page.waitForTimeout(8000);
  log.push('toast: ' + await page.evaluate(() => [...document.querySelectorAll('.toast, #toast, [role=status]')].map((t) => t.innerText).join(' | ')));
  const rank = async (id) => page.evaluate(async (a) => { for (const pg of [1, 2]) { const r = await fetch(reorderApiBase() + '/category/' + a.c + '/products?per_page=500&page=' + pg, { credentials: 'same-origin', headers: { Accept: 'application/json' } }); const j = await r.json(); const i = j.products.findIndex((p) => p.id === a.id); if (i >= 0) return (pg - 1) * 500 + i + 1; } return null; }, { id, c: process.env.CAT || '1' });
  for (const [label, id] of [['end1', ids[2]], ['end2', ids[5]], ['r120', ids[8]], ['r300', ids[11]], ['r77', ids[14]]]) log.push(label + ' now at ' + await rank(id));
  console.log(log.join('\n'));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
