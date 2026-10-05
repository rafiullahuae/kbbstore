/*
 * Lane PW: App -> Site App in the console, at 1280 and 390, against the wired
 * preview (sh tools/pwa-shop-preview.sh). Reports the sidebar group and row,
 * the breadcrumb and title, and scrollWidth against clientWidth.
 *   PW_BASE=http://127.0.0.1:8731 node tools/pwa-admin-shots.cjs
 */
const { chromium } = require('playwright');
const BASE = process.env.PW_BASE || 'http://127.0.0.1:8731';
const OUT = __dirname + '/../docs/pwa-shots';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin?go=siteapp', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    const m = await page.evaluate(() => {
      const groups = [...document.querySelectorAll('#nav .nav-group')].map((g) => g.getAttribute('data-sec'));
      const app = document.querySelector('#nav .nav-group[data-sec="App"]');
      return {
        clientWidth: document.documentElement.clientWidth, scrollWidth: document.documentElement.scrollWidth,
        groupsAroundApp: groups.slice(Math.max(0, groups.indexOf('App') - 1), groups.indexOf('App') + 2),
        appRows: app ? [...app.querySelectorAll('.nav-item')].map((b) => b.dataset.go + ':' + b.textContent.trim()) : null,
        crumb: (document.querySelector('#crumb') || {}).textContent, title: (document.querySelector('#ptitle') || {}).textContent,
        screen: !!document.querySelector('[data-screen="siteapp"]'),
        switchOn: (document.querySelector('.sap-sw') || {}).getAttribute ? document.querySelector('.sap-sw').getAttribute('aria-checked') : null,
        name: (document.querySelector('#sapName') || {}).value,
        icons: document.querySelectorAll('.sap-icons img').length,
        iconsLoaded: [...document.querySelectorAll('.sap-icons img')].every((i) => i.complete && i.naturalWidth > 0),
      };
    });
    await page.screenshot({ path: `${OUT}/admin-site-app-${width}.png`, fullPage: true });
    if (width === 390) {
      // The phone console keeps its sidebar in a drawer; show the App group open there too.
      await page.evaluate(() => { const s = document.querySelector('#side'); if (s) s.classList.add('open'); const g = document.querySelector('#nav .nav-group[data-sec="App"]'); if (g) { g.classList.add('open'); g.scrollIntoView(); } });
      await page.waitForTimeout(400);
      await page.screenshot({ path: `${OUT}/admin-site-app-390-sidebar.png` });
    }
    console.log(width, JSON.stringify(m), 'errors:', JSON.stringify(errors));
    await ctx.close();
  }
  await browser.close();
})();
