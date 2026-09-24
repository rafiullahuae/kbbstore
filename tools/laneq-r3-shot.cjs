const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8943';

(async () => {
  const [what, out, w, h] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  page.on('dialog', d => d.accept());

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.evaluate(() => window.go('routines'));
  await page.waitForTimeout(1800);

  if (what === 'search') {
    await page.click('#rtn-q');
    await page.type('#rtn-q', 'centella', { delay: 60 });
    await page.waitForTimeout(1800);
  } else if (what === 'settings' || what === 'demo-on') {
    await page.click('[data-rtn-tab="settings"]');
    await page.waitForTimeout(900);
    if (what === 'demo-on') {
      await page.click('#rtn-demo-import');
      await page.waitForTimeout(2600);
    }
  }

  const m = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
    status: document.querySelector('.rtn-status')?.innerText.replace(/\s+/g, ' ').trim() ?? null,
    rows: document.querySelectorAll('[data-rtn-row]').length,
    moduleTitle: [...document.querySelectorAll('.rtn-title')].find(t => /routine section is/.test(t.textContent))?.textContent ?? null,
    moduleBtn: document.querySelector('#rtn-module-toggle')?.textContent.trim() ?? null,
    demoBtn: (document.querySelector('#rtn-demo-import') || document.querySelector('#rtn-demo-remove'))?.textContent.trim() ?? null,
    standing: document.querySelector('.rtn-next-demo')?.innerText.replace(/\s+/g, ' ').trim().slice(0, 120) ?? null,
    tabs: [...document.querySelectorAll('[data-rtn-tab]')].map(t => t.innerText.replace(/\s+/g, ' ').trim()).slice(0, 5),
  }));
  console.log(JSON.stringify(m, null, 1));
  await page.screenshot({ path: out, fullPage: true });
  await browser.close();
})();
