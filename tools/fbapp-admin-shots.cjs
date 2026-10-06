/*
 * Lane FB: Appearance → Footer → Site footer · Desktop / · Mobile → App row,
 * photographed the way the owner reaches it (sign in, open Appearance → Footer,
 * pick the page, the App row section). Nothing is saved.
 * Usage: node tools/fbapp-admin-shots.cjs <base>
 */
const { chromium } = require('playwright');

const BASE = process.argv[2];
const OUT = __dirname + '/../docs/fbapp-shots';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.evaluate(() => window.go('slimfooter'));
  await page.waitForTimeout(1800);
  const out = {};
  for (const key of ['site-d', 'site-m']) {
    await page.click(`[data-sfs-page="${key}"]`);
    await page.waitForTimeout(900);
    const sec = page.locator('.sfs-sec', { has: page.locator('h3', { hasText: 'App row' }) });
    await sec.scrollIntoViewIfNeeded();
    out[key] = await sec.evaluate((s) => ({
      crumb: (document.querySelector('#crumb') || {}).textContent,
      heading: s.querySelector('h3').textContent,
      labels: [...s.querySelectorAll('label, b')].map((e) => e.textContent.trim()).filter(Boolean).slice(0, 30),
      scrollWidth: document.documentElement.scrollWidth,
    }));
    await sec.screenshot({ path: `${OUT}/admin-app-row-${key}.png` });
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
