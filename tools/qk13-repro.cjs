/* Lane QK13: reproduce, then prove. Store -> New Order at 390/1280/1530/1920,
   and Catalog -> Products paging and per-page.
   BASE=http://127.0.0.1:PORT OUT=docs/lane-qk13-shots TAG=before node tools/qk13-repro.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT, TAG = process.env.TAG || 'shot';
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const w of [390, 1280, 1530, 1920]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 950 } })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('order-new'));
    await page.waitForSelector('#moScreen');
    const fill = process.env.FILL === '1';
    if (fill) {
      await page.fill('#moCustSearch', 'aisha');
      await page.waitForTimeout(900);
      await page.locator('#moCustResults button, #moCustResults [data-cust]').first().click();
      await page.waitForTimeout(500);
      await page.fill('#moProdSearch', 'Medicube');
      await page.waitForTimeout(900);
      await page.locator('#moProdResults button, #moProdResults [data-prod]').first().click();
      await page.waitForTimeout(800);
      await page.fill('#mo_line1', 'Villa 12, Al Wasl Road');
      await page.fill('#mo_city', 'Dubai');
      if (await page.locator('select#mo_state').count()) await page.selectOption('#mo_state', { index: 1 }); else { await page.fill('#mo_state', 'Dubai'); }
      await page.locator('#mo_state').dispatchEvent('change');
      await page.waitForTimeout(1800);
    }
    const m = await page.evaluate(() => {
      const r = (s) => { const e = document.querySelector(s); if (!e) return null; const b = e.getBoundingClientRect(); return { x: Math.round(b.x), w: Math.round(b.width) }; };
      const cols = [...document.querySelectorAll('#moScreen .mo-grid > *')].map((e) => { const b = e.getBoundingClientRect(); return { x: Math.round(b.x), w: Math.round(b.width) }; });
      const sel = document.querySelector('#moScreen select');
      return { vw: document.documentElement.clientWidth, sw: document.documentElement.scrollWidth, grid: r('#moScreen .mo-grid'), cols,
        firstSelectW: sel ? Math.round(sel.getBoundingClientRect().width) : null,
        total: (document.querySelector('.mo-tot-r.grand') || {}).innerText || null };
    });
    out.push({ screen: 'neworder', w, ...m, errors: errors.slice() });
    await page.screenshot({ path: `${OUT}/${TAG}-neworder-${w}.png`, fullPage: true });

    if (w === 1530 || w === 390) {
      await page.evaluate(() => window.go('catalog', 'products'));
      await page.waitForSelector('#cplPerPage', { timeout: 15000 });
      const pager = async () => page.evaluate(() => ({ text: document.querySelector('.cplpager span').innerText, num: (document.querySelector('.cplnum') || {}).innerText, rows: document.querySelectorAll('#cplListArea tbody tr').length,
        first: (document.querySelector('#cplListArea tbody tr') || {}).innerText?.replace(/\s+/g, ' ').slice(0, 80), opts: [...document.querySelectorAll('#cplPerPage option')].map((o) => o.value), sel: document.querySelector('#cplPerPage').value }));
      const steps = [];
      steps.push({ at: 'load', ...(await pager()) });
      await page.selectOption('#cplPerPage', process.env.PP || '200'); await page.waitForTimeout(1500);
      steps.push({ at: 'perpage', ...(await pager()) });
      await page.click('#cplNext'); await page.waitForTimeout(1500);
      steps.push({ at: 'next', ...(await pager()) });
      if (await page.locator('#cplNext:not([disabled])').count()) { await page.click('#cplNext'); await page.waitForTimeout(1500); }
      steps.push({ at: 'next2', ...(await pager()) });
      out.push({ screen: 'catalog', w, steps, sw: await page.evaluate(() => document.documentElement.scrollWidth), errors });
      await page.screenshot({ path: `${OUT}/${TAG}-catalog-${w}.png` });
      await page.locator('.cplpager').scrollIntoViewIfNeeded();
      // Clickable on the first try: what is under the middle of each pager control is that control.
      const hits = await page.evaluate(() => ['#cplPerPage', '#cplPrev', '#cplNext'].map((s) => {
        const e = document.querySelector(s), b = e.getBoundingClientRect();
        const at = document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2);
        return s + ':' + (at === e || e.contains(at));
      }));
      out[out.length - 1].pagerHits = hits;
      out[out.length - 1].pagerNum = await page.evaluate(() => document.querySelector('.cplpager .cplnum').innerText);
      await page.screenshot({ path: `${OUT}/${TAG}-catalog-pager-${w}.png` });
    }
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
