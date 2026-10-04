// Lane PX: the new controls, where they sit in the admin, at 1280.
// node tools/px-admin-shots.cjs [port]  -> docs/lane-px-shots/admin-*.png
const { chromium } = require('playwright');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = `http://127.0.0.1:${process.argv[2] || 10040}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-px-shots');

async function shotText(page, name, text) {
  const el = page.getByText(text, { exact: true }).first();
  await el.scrollIntoViewIfNeeded();
  const b = await el.boundingBox();
  const sy = await page.evaluate(() => scrollY);
  await page.screenshot({ path: path.join(OUT, `admin-${name}.png`), fullPage: true,
    clip: { x: 0, y: Math.max(0, b.y + sy - 260), width: 1280, height: 560 } });
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  page.on('pageerror', (e) => console.log('pageerror', String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

  const go = async (id) => { await page.goto(`${BASE}/admin?go=${id}`, { waitUntil: 'networkidle' }); await page.waitForTimeout(800); };

  await go('layout');
  await shotText(page, 'product-grid-soldout', 'Sold-out label');

  await go('prodstyles');
  await page.click('[data-pstab="content"]');
  await page.waitForTimeout(400);
  await shotText(page, 'product-styles-soldout', 'Sold-out label');

  await go('rev-settings');
  await shotText(page, 'review-settings-compact', 'Compact summary');

  await go('productpage');
  await page.click('[data-pptab="ty_buy"]');
  await page.waitForTimeout(400);
  await shotText(page, 'product-page-brand-capsule', 'Brand name background');

  await page.click('[data-pptab="ymal"]');
  await page.waitForTimeout(400);
  await shotText(page, 'product-page-ymal-phone', 'Cards in view on a phone');
  await browser.close();
  console.log('admin shots written');
})();
