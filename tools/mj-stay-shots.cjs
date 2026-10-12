/* Lane MJ: a sending campaign's Review & send must not come back over another
   admin screen. BASE=... ID=<campaign id> TAG=before|after node tools/mj-stay-shots.cjs */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.BASE, ID = process.env.ID, TAG = process.env.TAG || 'after';
const OUT = path.resolve(__dirname, '../docs/lane-mj-shots');
require('fs').mkdirSync(OUT, { recursive: true });
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const errors = []; page.on('pageerror', (e) => errors.push(String(e)));
  let steps = 0;
  // Keep the campaign "sending" for as long as the test runs, whatever the
  // server would say, so the page's loop is exercised the whole time.
  await page.route('**/admin-api/email-marketing/campaigns/*/step', async (route) => {
    steps++;
    const res = await route.fetch(); let body = {};
    try { body = await res.json(); } catch (e) {}
    const p = Object.assign({ sent: 1, total: 100 }, body.progress || {}, { status: 'sending', done: false, room: { allowed: 1, day_left: 99 } });
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(Object.assign({}, body, { progress: p })) });
  });
  await page.route('**/admin-api/email-marketing/campaigns/*/progress', async (route) => {
    const res = await route.fetch(); let body = {};
    try { body = await res.json(); } catch (e) {}
    const p = Object.assign({ sent: 1, total: 100 }, body.progress || {}, { status: 'sending', done: false, room: { allowed: 1, day_left: 99 } });
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(Object.assign({}, body, { progress: p })) });
  });
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@example.com');
  await page.fill('input[name=password]', 'preview-password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.evaluate(() => window.go('mkt-email'));
  await page.waitForSelector('#content [data-mke]', { timeout: 20000 });
  await page.waitForTimeout(800);
  await page.evaluate((id) => { const b = document.createElement('button'); b.setAttribute('data-mke', 'review'); b.setAttribute('data-id', id); document.querySelector('[data-mke]').appendChild(b); b.click(); b.remove(); }, ID);
  await page.waitForTimeout(3000);
  const onReview = await page.evaluate(() => !!document.querySelector('#content [data-mke]') && /Review/.test(document.querySelector('#ptitle')?.textContent || ''));
  const stepsBefore = steps;
  const out = { onReview, stepsWhileOnReview: stepsBefore };
  for (const screen of ['dash', 'orders', 'payments']) {
    await page.evaluate((s) => window.go(s), screen);
    await page.waitForTimeout(12000);
    out[screen] = await page.evaluate(() => ({ campaignPageBack: !!document.querySelector('#content [data-mke]'), title: document.querySelector('#ptitle')?.textContent || '' }));
    await page.screenshot({ path: `${OUT}/${TAG}-${screen}-after-12s-1280.png` });
  }
  out.stepsAfterLeaving = steps - stepsBefore;
  out.errors = errors;
  console.log(TAG, JSON.stringify(out));
  await browser.close();
})();
