// Lane RP2: Appearance → Product page → You may also like — the Global group
// and one block's per-device override, at 1280.
// node tools/rp2-admin-shots.cjs [port] -> docs/lane-rp2-shots/admin-*.png
const { chromium } = require('playwright');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = `http://127.0.0.1:${process.argv[2] || 8861}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-rp2-shots');

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
  // The Global group and Block 1, the first eleven rows.
  const rows = page.locator('.mmcard .mmrow');
  const a = await rows.nth(0).boundingBox();
  const b = await rows.nth(10).boundingBox();
  const c = await card.boundingBox();
  await page.screenshot({ path: path.join(OUT, 'admin-1280-global-and-block1.png'), clip: { x: c.x, y: c.y, width: c.width, height: b.y + b.height - c.y + 8 } });
  const labels = await page.$$eval('.mmcard .mmlbl b', (els) => els.map((e) => e.textContent));
  const values = await page.$$eval('.mmcard select[data-pya]', (els) => els.map((e) => e.dataset.pya + '=' + e.value));
  console.log(JSON.stringify({ labels, values, errors }, null, 1));
  await browser.close();
})();
