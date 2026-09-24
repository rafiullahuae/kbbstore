const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8941';

(async () => {
  const [out, w, h, search] = process.argv.slice(2);
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
  await page.waitForTimeout(1600);

  if (search) {
    // The screen searches on Enter or on change, not on every keystroke.
    await page.click('#rtn-q');
    await page.type('#rtn-q', search, { delay: 30 });
    await page.press('#rtn-q', 'Enter');
    await page.waitForTimeout(1600);
  }

  const m = await page.evaluate(() => {
    const px = (el, p) => el ? Math.round(parseFloat(getComputedStyle(el)[p])) : null;
    const card = [...document.querySelectorAll('.rtn-card')]
      .find(c => (c.querySelector('.rtn-title') || {}).textContent?.includes('Concern landing pages'));
    const rows = [...document.querySelectorAll('.rtn-cprow')];
    const bar = document.querySelector('.rtn-cpbar');
    const hit = [...document.querySelectorAll('.rtn-meta')].find(e => e.textContent.startsWith('Ingredients:'));
    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
      countdownCardTitle: card?.querySelector('.rtn-title')?.textContent ?? null,
      countdownCardWidth: card ? Math.round(card.getBoundingClientRect().width) : null,
      countdownRows: rows.length,
      rowTexts: rows.slice(0, 3).map(r => r.innerText.replace(/\n/g, ' | ')),
      cpnameFontSize: px(document.querySelector('.rtn-cpname'), 'fontSize'),
      cpnoteFontSize: px(document.querySelector('.rtn-cpnote'), 'fontSize'),
      barHeight: bar ? Math.round(bar.getBoundingClientRect().height) : null,
      barFillPct: document.querySelector('.rtn-cpfill')?.style.width ?? null,
      searchPlaceholder: document.querySelector('#rtn-q')?.placeholder ?? null,
      resultCount: document.querySelectorAll('.rtn-row').length,
      ingredientHit: hit?.textContent ?? null,
    };
  });
  console.log(JSON.stringify(m, null, 1));
  await page.screenshot({ path: out, fullPage: true });
  await browser.close();
})();
