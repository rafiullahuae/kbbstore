// Lane RP: Appearance → Product page → You may also like, at 1280, with the
// three blocks' controls. node tools/rp-admin-shots.cjs [port]
//   -> docs/lane-rp-shots/admin-*.png
const { chromium } = require('playwright');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = `http://127.0.0.1:${process.argv[2] || 8761}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-rp-shots');

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const page = await browser.newPage({ viewport: { width: 1280, height: 3400 } });
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
  // A tall window so the whole card is on screen at once (the console
  // scrolls its own column, so a full-page clip cannot reach it).
  await page.locator('.mmcard').first().screenshot({ path: path.join(OUT, 'admin-1280-recs-controls.png') });
  const labels = await page.$$eval('.mmcard .mmlbl b', (els) => els.map((e) => e.textContent));
  console.log(JSON.stringify({ labels, errors }, null, 1));
  await browser.close();
})();
