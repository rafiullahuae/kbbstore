// Lane BC: Appearance → Product page → You may also like — the top controls
// (show as, tab that opens first, the four switches) and the whole tab, at 1280 and 390.
// node tools/bc-admin-shots.cjs [port] -> docs/lane-bc-shots/admin-*.png
const { chromium } = require('playwright');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = `http://127.0.0.1:${process.argv[2] || 8861}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-bc-shots');

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const page = await browser.newPage({ viewport: { width: 1280, height: 4200 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(`${BASE}/admin?go=productpage`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.click('[data-pptab="ymal"]');
  await page.waitForTimeout(500);
  const card = page.locator('.mmcard').first();
  await card.screenshot({ path: path.join(OUT, 'admin-1280-all-controls.png') });
  // The first six rows: Show as, Tab that opens first, and the four switches.
  const rows = page.locator('.mmcard .mmrow');
  const b = await rows.nth(5).boundingBox();
  const c = await card.boundingBox();
  await page.screenshot({ path: path.join(OUT, 'admin-1280-top.png'), clip: { x: c.x, y: c.y, width: c.width, height: b.y + b.height - c.y + 8 } });
  await page.setViewportSize({ width: 390, height: 4200 });
  await page.waitForTimeout(400);
  const c2 = await card.boundingBox();
  const b2 = await rows.nth(5).boundingBox();
  await page.screenshot({ path: path.join(OUT, 'admin-390-top.png'), clip: { x: 0, y: c2.y, width: 390, height: b2.y + b2.height - c2.y + 8 } });
  const labels = await page.$$eval('.mmcard .mmlbl b', (els) => els.map((e) => e.textContent));
  const values = await page.$$eval('.mmcard select[data-pya]', (els) => els.map((e) => e.dataset.pya + '=' + e.value));
  console.log(JSON.stringify({ labels, values, errors }, null, 1));
  await browser.close();
})();
