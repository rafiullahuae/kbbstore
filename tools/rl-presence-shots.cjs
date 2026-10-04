// Lane RL -- edit presence + Take over, in TWO browser contexts, at 390 and 1280.
//   node tools/rl-presence-shots.cjs http://127.0.0.1:9970 [productId]
// Sara (SEO Manager, tools/rl-seed.php) opens a product and types; the owner
// opens the same product, sees "Sara Ahmed is editing this product", presses
// Take over; Sara's tab is told on its next beat (measured), and her Save is
// refused by the server with a 409. Fails loudly on any page error.
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:9970';
const PID = Number(process.argv[3] || 1);
const OUT = path.join(__dirname, '..', 'docs', 'lane-rl-shots');

async function signIn(b, w, email) {
  const ctx = await b.newContext({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
  const page = await ctx.newPage();
  page.errors = [];
  page.on('pageerror', e => page.errors.push(String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

async function openProduct(page) {
  await Promise.all([
    page.waitForResponse(r => r.url().includes(`/admin-api/product-editor-load/${PID}`) && r.ok()),
    page.evaluate(id => window.peoEdit(id), PID),
  ]);
  await page.waitForSelector('#peo-save');
}

const measure = page => page.evaluate(() => {
  const bar = document.getElementById('kbpBar');
  const cs = bar && getComputedStyle(bar);   // the shot script measures; the page never does
  return {
    scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth,
    bar: bar && !bar.hidden ? { text: bar.innerText.replace(/\s+/g, ' ').trim(), height: Math.round(bar.getBoundingClientRect().height), width: Math.round(bar.getBoundingClientRect().width), fontSize: cs.fontSize } : null,
  };
});

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const report = {};
  for (const w of [1280, 390]) {
    const sara = await signIn(b, w, 'sara@preview.test');
    await openProduct(sara);
    const field = sara.locator('#content input[type=text]:visible').first();
    await field.click();
    await field.press('End');
    await field.type(' — spring copy (unsaved)');
    const unsaved = await field.inputValue();

    const owner = await signIn(b, w, 'owner@preview.test');
    await openProduct(owner);
    await owner.waitForSelector('#kbpBar:not([hidden]) [data-kbp="take"]', { timeout: 20000 });
    report[`viewer-${w}`] = await measure(owner);
    await owner.screenshot({ path: `${OUT}/presence-viewer-${w}.png` });

    const tookAt = Date.now();
    await owner.click('[data-kbp="take"]');
    await owner.waitForSelector('#kbpBar.ok:not([hidden])');
    report[`took-${w}`] = await measure(owner);
    await owner.screenshot({ path: `${OUT}/presence-took-${w}.png` });

    // Sara is told on her own next beat -- no reload, no click.
    await sara.waitForSelector('#kbpBar.bad:not([hidden])', { timeout: 20000 });
    report[`taken-over-${w}`] = { ...(await measure(sara)), msAfterTakeOver: Date.now() - tookAt, unsavedStillOnScreen: (await field.inputValue()) === unsaved };
    await sara.screenshot({ path: `${OUT}/presence-taken-over-${w}.png` });

    // Her Save is refused by the server, naming the owner.
    const [res] = await Promise.all([
      sara.waitForResponse(r => r.url().includes(`/product-editor-save/${PID}`)),
      sara.click('#peo-save'),
    ]);
    report[`save-refused-${w}`] = { status: res.status(), body: await res.json(), lockHeader: !!res.request().headers()['x-kbb-edit-lock'] };
    await sara.waitForTimeout(300);
    await sara.screenshot({ path: `${OUT}/presence-save-refused-${w}.png` });

    // Hidden tab: no beats at all while hidden.
    let beats = 0;
    owner.on('request', r => { if (r.url().includes('/presence/beat')) beats++; });
    await owner.evaluate(() => { Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true }); document.dispatchEvent(new Event('visibilitychange')); });
    await owner.waitForTimeout(16500);
    report[`hidden-beats-16s-${w}`] = beats;

    report[`errors-${w}`] = [...sara.errors, ...owner.errors];
    await sara.context().close();
    await owner.context().close();
  }
  await b.close();
  require('fs').writeFileSync(`${OUT}/presence-measurements.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  const errs = Object.keys(report).filter(k => k.startsWith('errors-')).flatMap(k => report[k]);
  if (errs.length) { console.error('PAGE ERRORS', errs); process.exit(1); }
})();
