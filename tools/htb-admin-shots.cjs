// Lane HTB -- Appearance → Grid sections → "Product tabs" preset, in the real
// console: create it, open the Product tabs card (EN / AR fields, Translate),
// press Translate, publish it, save. Pictures at 1280 and 390.
// Usage: node tools/htb-admin-shots.cjs <base> <out-dir>
const { chromium } = require(process.env.NODE_PATH ? 'playwright' : '/opt/node22/lib/node_modules/playwright');

const BASE = process.argv[2];
const OUT = process.argv[3];

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.waitForTimeout(500);
    await page.evaluate(() => window.go('gridsections'));
    await page.waitForSelector('[data-gss-add="tabs"]', { timeout: 15000 });
    await page.screenshot({ path: OUT + '/admin-list-' + w + '.png' });

    if (w === 1280) {
      await page.click('[data-gss-add="tabs"]');
    } else {
      await page.click('[data-gss-edit]');
    }
    await page.waitForSelector('[data-gss-tabson]', { timeout: 15000 });
    await page.waitForTimeout(1200);
    const card = page.locator('.gss-tabs');
    await card.scrollIntoViewIfNeeded();
    await page.screenshot({ path: OUT + '/admin-tabs-' + w + '.png' });
    await card.screenshot({ path: OUT + '/admin-tabs-card-' + w + '.png' });

    // Translate, with no provider connected: the button says so.
    const tr = page.locator('[data-gss-tr="list.0.title_ar"]');
    const label = await tr.textContent();
    await tr.click();
    await page.waitForFunction(() => (document.querySelector('[data-gss-trnote="list.0.title_ar"]') || {}).textContent, null, { timeout: 10000 });
    const note = await page.textContent('[data-gss-trnote="list.0.title_ar"]');
    await page.locator('.gss-tab').first().screenshot({ path: OUT + '/admin-translate-' + w + '.png' });

    if (w === 1280) {
      // Publish and save, through the screen's own Save button.
      await page.selectOption('[data-gss-key="status"]', 'publish');
      await page.click('#gss-save');
      await page.waitForFunction(() => /Saved/.test((document.querySelector('#gss-state') || {}).textContent || ''), null, { timeout: 15000 });
      // The preview, Arabic.
      await page.click('[data-gss-lang="ar"]');
      await page.waitForTimeout(2500);
      await page.locator('#gss-stage').scrollIntoViewIfNeeded();
      await page.screenshot({ path: OUT + '/admin-preview-ar-' + w + '.png' });
    }

    console.log(JSON.stringify({ w, translateLabel: label, translateNote: note, errors, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) }));
    await ctx.close();
  }
  await browser.close();
})();
