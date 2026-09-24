const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8943';

(async () => {
  const [label, word, mode] = process.argv.slice(2);   // mode: 'type' | 'enter'
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();

  const calls = [];
  page.on('response', async r => {
    if (!r.url().includes('/admin-api/routine-products')) return;
    let note = '';
    try { const j = await r.json(); note = 'total=' + j.total + ' rows=' + (j.products || []).length; }
    catch (e) { note = '(body not json)'; }
    calls.push(r.status() + '  ' + r.url().replace(BASE, '') + '  -> ' + note);
  });

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.go('routines'));
  await page.waitForTimeout(1800);

  calls.length = 0;
  await page.click('#rtn-q');
  await page.type('#rtn-q', word, { delay: 90 });
  if (mode === 'enter') await page.press('#rtn-q', 'Enter');
  await page.waitForTimeout(2400);

  const seen = await page.evaluate(() => ({
    rows: document.querySelectorAll('[data-rtn-row]').length,
    empty: document.querySelector('.rtn-empty')?.textContent?.trim() ?? null,
    banner: document.querySelector('.rtn-banner')?.textContent?.trim() ?? null,
    status: document.querySelector('.rtn-status')?.innerText?.trim() ?? null,
  }));

  console.log('┌─ ' + label + '   typed "' + word + '"' + (mode === 'enter' ? ' then Enter' : ' (no Enter)'));
  console.log('│  requests fired : ' + (calls.length || 'NONE'));
  calls.forEach(c => console.log('│    ' + c));
  console.log('│  rows on screen : ' + seen.rows);
  console.log('│  status line    : ' + (seen.status ?? '—'));
  console.log('│  empty message  : ' + (seen.empty ?? '—'));
  console.log('│  error banner   : ' + (seen.banner ?? '—'));
  console.log('└─');
  await browser.close();
})();
