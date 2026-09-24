const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8942';

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const reqs = [];
  page.on('request', r => { if (r.url().includes('/admin-api/')) reqs.push(r.method() + ' ' + r.url().replace(BASE, '')); });

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);

  reqs.length = 0;
  await page.evaluate(() => window.go('routines'));
  await page.waitForTimeout(1500);
  console.log('OPENING THE SCREEN  ->', reqs.length, 'requests:', JSON.stringify(reqs));

  // Switching between the three non-step tabs must cost nothing.
  reqs.length = 0;
  for (const t of ['concern-pages', 'wording', 'settings']) {
    await page.click('[data-rtn-tab="' + t + '"]'); await page.waitForTimeout(500);
  }
  console.log('CONCERN/WORDING/SETTINGS ->', reqs.length, 'requests:', JSON.stringify(reqs));

  // Switching to a step costs exactly one.
  reqs.length = 0;
  await page.click('[data-rtn-tab="protect"]'); await page.waitForTimeout(1200);
  console.log('SWITCHING TO SPF    ->', reqs.length, 'requests:', JSON.stringify(reqs));

  // Keyboard: ArrowRight walks the strip.
  await page.focus('[data-rtn-tab="protect"]');
  await page.keyboard.press('ArrowRight');
  await page.waitForTimeout(600);
  console.log('ARROWRIGHT FROM SPF ->', await page.evaluate(() =>
    document.querySelector('[aria-selected="true"]').getAttribute('data-rtn-tab')));

  // THE PER-TAB ACTION. Stand on SPF, search, press Use on the first row.
  await page.click('[data-rtn-tab="protect"]'); await page.waitForTimeout(1000);
  await page.click('#rtn-q'); await page.type('#rtn-q', 'centella', { delay: 20 });
  await page.press('#rtn-q', 'Enter'); await page.waitForTimeout(1400);

  const before = await page.evaluate(() => ({
    rows: [...document.querySelectorAll('[data-rtn-row]')].map(r => r.getAttribute('data-rtn-row')),
    firstBtn: document.querySelector('[data-rtn-use]')?.getAttribute('data-rtn-use'),
  }));

  reqs.length = 0;
  await page.click('[data-rtn-use]');
  await page.waitForTimeout(1500);
  console.log('PRESSING "USE FOR SPF" ->', JSON.stringify(reqs));

  const after = await page.evaluate(() => ({
    selected: document.querySelector('[aria-selected="true"]').getAttribute('data-rtn-tab'),
    labels: [...document.querySelectorAll('[data-rtn-tab]')].map(t => t.innerText.replace(/\s+/g, ' ').trim()),
  }));
  console.log('PRODUCT PRESSED    ->', before.firstBtn);
  console.log('TAB STILL OPEN     ->', after.selected);
  console.log('STRIP NOW          ->', JSON.stringify(after.labels));

  await browser.close();
})();
