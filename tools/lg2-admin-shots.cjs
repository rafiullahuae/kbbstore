/*
 * Lane LG2 admin screenshots, driven the way the owner drives it -- sign in,
 * open Appearance -> Header, press the Logo tab:
 *     NODE_PATH=/opt/node22/lib/node_modules node tools/lg2-admin-shots.cjs <base>
 */
const { chromium } = require('playwright');
const BASE = process.argv[2];
const OUT = __dirname + '/../docs/lane-lg2-shots/';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));
    page.on('console', (c) => { if (c.type() === 'error') errs.push(c.text().slice(0, 160)); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('header'));
    await page.waitForTimeout(1500);
    await page.click('[data-hdtab="logo"]');
    await page.waitForTimeout(700);
    const info = await page.evaluate(() => ({
      crumb: (document.querySelector('#crumb') || {}).textContent,
      tab: (document.querySelector('.ectab.on') || {}).textContent,
      labels: [...document.querySelectorAll('.mmbody .mmlbl b')].map((b) => b.textContent.trim()),
      preview: !!document.querySelector('#hdPhone .hdpv-lgx svg'),
      sw: document.documentElement.scrollWidth,
    }));
    console.log(JSON.stringify({ width, ...info }));
    await page.screenshot({ path: `${OUT}admin-header-logo-tab-${width}.png`, fullPage: true });
    // The "Text only (as before)" choice, as the preview shows it (not saved).
    await page.selectOption('select[data-hd="logo_style"]', 'text');
    await page.waitForTimeout(300);
    await page.locator('#hdPhone').screenshot({ path: `${OUT}admin-preview-text-only-${width}.png` });
    await page.selectOption('select[data-hd="logo_style"]', 'lotus');
    await page.waitForTimeout(300);
    await page.locator('#hdPhone').screenshot({ path: `${OUT}admin-preview-lotus-${width}.png` });
    console.log(width, 'errors', errs.length ? errs.join(' ; ') : 0);
    await ctx.close();
  }
  await browser.close();
})();
