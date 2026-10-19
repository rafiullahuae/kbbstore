/* Lane VPA: the order confirmation (EN, AR), the admin order screen and
   Product editor -> Options, photographed against tools/vpa-preview.sh.
   node tools/vpa-admin-shots.cjs <base> <out-dir> [mail|admin|all] */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:10570';
const OUT = path.resolve(process.argv[3] || 'docs/lane-vpa-shots');
const WHAT = process.argv[4] || 'all';
fs.mkdirSync(OUT, { recursive: true });
let failed = 0;
const check = (name, ok, detail) => { if (!ok) { failed++; console.log('FAIL', name, detail || ''); } else console.log('ok  ', name); };

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

  if (WHAT !== 'admin') {
    for (const tag of ['en', 'ar']) {
      const ctx = await browser.newContext({ viewport: { width: 390, height: 900 }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      // The preview's router serves .html as a download, so the rendered mail is
      // set as the page's content on the preview's own origin (its pictures load).
      await page.goto(`${BASE}/kbb-preview-id.txt`);
      await page.setContent(fs.readFileSync(path.resolve(__dirname, `../storage/framework/testing/vpa-preview/webroot/vpa-mail-${tag}.html`), 'utf8'), { waitUntil: 'networkidle' });
      const text = await page.evaluate(() => document.body.innerText);
      check(`mail ${tag}: names the shade`, tag === 'en' ? /Shade: #13 Bright Beige/.test(text) : /الدرجة/.test(text), text.slice(0, 120));
      await page.evaluate((t) => {
        const el = [...document.querySelectorAll('div,td,span')].find((n) => n.children.length === 0 && (t === 'en' ? /Shade:/.test(n.textContent) : /الدرجة/.test(n.textContent)));
        if (el) { el.scrollIntoView({ block: 'center' }); }
      }, tag);
      await page.screenshot({ path: path.join(OUT, `email-${tag}-390.png`) });
      await ctx.close();
    }
  }

  if (WHAT !== 'mail') {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });

    // Store -> Orders -> VPA-EN
    await page.evaluate(() => window.go('orders'));
    await page.waitForSelector('[data-olview]', { timeout: 15000 });
    await page.waitForTimeout(300);
    await page.evaluate(() => {
      const b = [...document.querySelectorAll('[data-olview]')].find((x) => x.closest('tr').innerText.includes('VPA-EN'));
      b.click();
    });
    await page.waitForSelector('#odItems', { timeout: 15000 });
    await page.waitForTimeout(500);
    const items = await page.evaluate(() => document.querySelector('#odItems').innerText);
    check('admin order: names the shade and its SKU', /Shade: #13 Bright Beige · SKU MS-BB-1/.test(items) && /Shade: 21N Ivory/.test(items), items.slice(0, 200));
    await page.evaluate(() => document.querySelector('#odItems').scrollIntoView({ block: 'start' }));
    await page.screenshot({ path: path.join(OUT, 'admin-order-1280.png') });

    // Catalog -> Product editor -> TIRTIR -> Options
    await page.evaluate(() => window.go('product-editor'));
    await page.waitForSelector('[data-peo-open]', { timeout: 15000 });
    await page.evaluate(() => {
      const b = [...document.querySelectorAll('[data-peo-open]')].find((x) => /TIRTIR/.test(x.innerText));
      b.click();
    });
    await page.waitForSelector('[data-vpo] table', { timeout: 15000 });
    await page.waitForTimeout(400);
    const rows = await page.evaluate(() => document.querySelectorAll('[data-vpo] tbody tr').length);
    check('editor: the Options panel lists nine shades', rows === 9, rows);
    // Below the editor's sticky save bar.
    await page.evaluate(() => { const c = document.querySelector('[data-vpo]'); const host = document.scrollingElement; c.scrollIntoView({ block: 'start' }); window.scrollBy(0, -150); });
    await page.screenshot({ path: path.join(OUT, 'admin-editor-options-1280.png') });
    // Save round-trip: change a price, save, read the message.
    await page.fill('[data-vpo-f="1:price_aed"]', '146');
    await page.click('[data-vpo-save]');
    await page.waitForFunction(() => /saved|Could not/.test((document.querySelector('#vpoMsg') || {}).textContent || ''), null, { timeout: 15000 });
    const msg = await page.evaluate(() => document.querySelector('#vpoMsg').textContent);
    check('editor: Save options answers', /Options saved/.test(msg), msg);
    check('admin: no page errors', errors.length === 0, errors.join(' | '));
    await ctx.close();
  }

  await browser.close();
  console.log(failed ? `${failed} FAILED` : 'all checks passed');
  process.exit(failed ? 1 : 0);
})();
