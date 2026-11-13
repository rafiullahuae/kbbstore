// Lane GS: Appearance → Product page → Free gifts strip, at 1280 and 390,
// plus a save through the screen (colour + laptop position) to prove the round
// trip. node tools/gs-admin-shots.cjs <base url> <out dir> [save]
const { chromium } = require('playwright');
const path = require('path');
const [base, out, mode] = process.argv.slice(2);

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.evaluate(() => window.go('productpage'));
    await page.waitForSelector('[data-gsatab]', { timeout: 15000 });
    await page.click('[data-gsatab]');
    await page.waitForSelector('#gsaSample .gsa-s');
    await page.waitForTimeout(500);
    const tabs = await page.$$eval('#ppStrip .ectab', (b) => b.map((x) => x.textContent.trim()));
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, rows: document.querySelectorAll('#ppCol .mmrow').length }));
    console.log(width, JSON.stringify({ tabs, ...m, errors }));
    // The console scrolls inside its own column: a tall viewport shows all of it.
    const tall = await page.evaluate(() => Math.ceil(document.getElementById('ppCol').scrollHeight + 400));
    await page.setViewportSize({ width, height: Math.min(4200, tall) });
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(out, `admin-giftstrip-${width}.png`) });
    await page.setViewportSize({ width, height: 900 });

    if (mode === 'save' && width === 1280) {
      // Colour: start → #7B5CFF; laptop position → Above Add to cart.
      await page.fill('[data-gsa-hex="c1"]', '#7B5CFF');
      await page.selectOption('[data-gsa-opt="desk"]', 'cart');
      await page.waitForTimeout(200);
      await (await page.$('#gsaSample')).screenshot({ path: path.join(out, 'admin-giftstrip-sample-after-colour.png') });
      const [resp] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/admin-api/product-page') && r.request().method() === 'POST'),
        page.click('#ppSave'),
      ]);
      const j = await resp.json();
      console.log('save', resp.status(), j.ok, j.saved, JSON.stringify(j.giftstrip.options[0].fields.filter((f) => ['c1', 'desk'].includes(f.key)).map((f) => [f.key, f.value])));
    }
    await page.close();
  }
  await browser.close();
})();
