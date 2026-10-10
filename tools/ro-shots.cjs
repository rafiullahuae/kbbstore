/* Lane RO: Catalog -> Reorder and Catalog -> Products at live volume, timed
   from the click to the painted list, plus the error answer.
   BASE=http://127.0.0.1:PORT OUT=docs/lane-ro-shots TAG=before|after CAT=1 node tools/ro-shots.cjs */
const { chromium } = require('playwright');
const BASE = process.env.BASE, OUT = process.env.OUT, TAG = process.env.TAG || 'shot', CAT = process.env.CAT || '1';
const listed = () => !!document.querySelector('#reListArea') && !/Loading/.test(document.querySelector('#reListArea').innerText) && !!document.querySelector('#rePerPage');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const out = [];
  for (const w of [390, 1280]) {
    const page = await (await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 } })).newPage();
    const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'page-cost-admin@example.test');
    await page.fill('input[name=password]', 'page-cost-secret');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('catalog', 'reorder'));
    await page.waitForFunction(listed, null, { timeout: 60000 });
    const timed = async (label, act) => {
      const t = Date.now(); await act();
      try { await page.waitForFunction(listed, null, { timeout: 20000 }); } catch (e) { return { label, ms: null, stuck: true }; }
      return { label, ms: Date.now() - t };
    };
    const steps = [];
    steps.push(await timed('open category ' + CAT, () => page.selectOption('#reCat', CAT)));
    steps.push(await timed('show 200 per page', () => page.selectOption('#rePerPage', '200')));
    steps.push(await timed('show 50 per page', () => page.selectOption('#rePerPage', '50')));
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, vw: document.documentElement.clientWidth,
      rows: document.querySelectorAll('#reListArea [draggable], #reListArea tr, #reListArea li').length,
      head: (document.querySelector('#catBody .toolbar span:last-child') || {}).innerText }));
    await page.screenshot({ path: `${OUT}/${TAG}-reorder-${w}.png` });

    // The error answer: a 500 from the products endpoint, as a timeout or a
    // database fault gives. Before: "Loading…" for good. After: the reason.
    await page.route('**/catalog/reorder/**/products?**', (r) => r.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) }));
    await page.selectOption('#rePerPage', '100');
    await page.waitForTimeout(2500);
    const errState = await page.evaluate(() => (document.querySelector('#reListArea') || document.querySelector('#catBody')).innerText.slice(0, 120));
    await page.screenshot({ path: `${OUT}/${TAG}-reorder-error-${w}.png` });
    await page.unroute('**/catalog/reorder/**/products?**');

    // Catalog -> Products, the page sizes the owner uses.
    await page.evaluate(() => window.go('catalog', 'products'));
    await page.waitForSelector('#cplPerPage', { timeout: 30000 });
    const cpl = [];
    for (const pp of ['300', '100']) {
      const t = Date.now(); await page.selectOption('#cplPerPage', pp);
      await page.waitForFunction((n) => document.querySelectorAll('#cplListArea tbody tr').length >= Math.min(+n, 100) && document.querySelector('#cplPerPage').value === n, pp, { timeout: 20000 });
      cpl.push({ pp, ms: Date.now() - t });
    }
    await page.screenshot({ path: `${OUT}/${TAG}-products-${w}.png` });
    out.push({ w, steps, ...m, errorAnswer: errState, products: cpl, errors: errors.filter((e) => !/500|Server Error/.test(e)) });
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
