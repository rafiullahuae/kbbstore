const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8942';

(async () => {
  const [tab, out, w, h, search] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  await page.evaluate(() => window.go('routines'));
  await page.waitForTimeout(1500);

  if (tab !== 'cleanse') {
    await page.click('[data-rtn-tab="' + tab + '"]');
    await page.waitForTimeout(1300);
  }
  if (search) {
    await page.click('#rtn-q');
    await page.type('#rtn-q', search, { delay: 25 });
    await page.press('#rtn-q', 'Enter');
    await page.waitForTimeout(1500);
  }

  const m = await page.evaluate(() => {
    const strip = document.querySelector('.rtn-tabs');
    const tabs = [...document.querySelectorAll('[data-rtn-tab]')];
    const sel = tabs.find(t => t.getAttribute('aria-selected') === 'true');
    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
      tabCount: tabs.length,
      tabLabels: tabs.map(t => t.innerText.replace(/\s+/g, ' ').trim()),
      selected: sel?.getAttribute('data-rtn-tab') ?? null,
      selectedCount: tabs.filter(t => t.getAttribute('aria-selected') === 'true').length,
      stripWidth: strip ? Math.round(strip.getBoundingClientRect().width) : null,
      stripHeight: strip ? Math.round(strip.getBoundingClientRect().height) : null,
      stripRows: strip ? new Set(tabs.map(t => Math.round(t.getBoundingClientRect().top))).size : null,
      tabFontSize: tabs[0] ? Math.round(parseFloat(getComputedStyle(tabs[0]).fontSize)) : null,
      bodyTitle: document.querySelector('.rtn-card .rtn-title')?.textContent ?? null,
      cardTitles: [...document.querySelectorAll('.rtn-title')].map(e => e.textContent).slice(0, 4),
      nextAction: document.querySelector('.rtn-next')?.innerText.replace(/\s+/g, ' ').trim() ?? null,
      useButtons: document.querySelectorAll('[data-rtn-use]').length,
      rows: document.querySelectorAll('.rtn-row').length,
      tiles: [...document.querySelectorAll('.rtn-stat')].map(e => e.innerText.replace(/\n/g, ' ')),
    };
  });
  console.log(JSON.stringify(m, null, 1));
  await page.screenshot({ path: out, fullPage: true });
  await browser.close();
})();
