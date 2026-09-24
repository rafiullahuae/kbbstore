const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8943';

(async () => {
  const label = process.argv[2] || 'race';
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();

  // THE RACE, ARRANGED. The reply to the SHORTER word is held back so it lands
  // after the reply to the longer one — which is what a slow network does by
  // itself, intermittently, and is why this bug is so hard to catch in the wild.
  await page.route('**/admin-api/routine-products*', async route => {
    if (/[?&]q=cer(&|$)/.test(route.request().url())) {
      await new Promise(r => setTimeout(r, 2000));
    }
    await route.continue();
  });

  const landed = [];
  page.on('response', r => {
    if (r.url().includes('/routine-products')) {
      landed.push(decodeURIComponent((r.url().split('q=')[1] || '(none)').split('&')[0]));
    }
  });

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.go('routines'));
  await page.waitForTimeout(1800);

  landed.length = 0;
  await page.click('#rtn-q');
  await page.type('#rtn-q', 'cer', { delay: 20 });
  await page.waitForTimeout(450);              // "cer" goes out (debounce 250)
  await page.type('#rtn-q', 'amide', { delay: 20 });
  await page.waitForTimeout(450);              // "ceramide" goes out
  await page.waitForTimeout(3200);             // and "cer" comes back late

  const end = await page.evaluate(() => ({
    box: document.querySelector('#rtn-q')?.value ?? null,
    status: document.querySelector('.rtn-status')?.innerText.trim() ?? null,
    // The PAGER's total comes from the rendered response body, so it shows
    // which reply actually painted; the status label reads the query variable
    // and would go on saying "ceramide" over stale rows.
    pager: document.querySelector('.rtn-pager span')?.textContent.trim() ?? null,
  }));

  console.log('┌─ ' + label);
  console.log('│  replies landed in this order : ' + JSON.stringify(landed));
  console.log('│  box still reads              : ' + end.box);
  console.log('│  status label says            : ' + end.status);
  console.log('│  PAINTED RESPONSE (pager)     : ' + end.pager + '    166 = "ceramide", 339 = the stale "cer"');
  console.log('└─');
  await browser.close();
})();
