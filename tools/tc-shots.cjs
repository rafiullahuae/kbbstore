/* Lane TC: the auto-capture switch on the payments screen, at two widths.
   Measures rather than asserts (CLAUDE.md rule 2 and rule 4): the numbers the
   report quotes come out of here, including scrollWidth against the viewport so
   a new wide control cannot have introduced a sideways scroll. */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8973';

(async () => {
  const [out, w, h] = process.argv.slice(2);
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

  await page.goto(BASE + '/admin#payments/tamara', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2200);

  const sel = '#pay_tamara_auto_capture';
  await page.waitForSelector(sel, { timeout: 15000 });
  await page.locator(sel).scrollIntoViewIfNeeded();
  await page.waitForTimeout(400);

  const m = await page.evaluate((s) => {
    const el = document.querySelector(s);
    const opt = el ? [...el.options].map(o => o.textContent + (o.selected ? ' (selected)' : '')) : null;
    const row = el ? el.closest('.ecopt') : null;
    const label = row ? row.querySelector('label') : null;
    const help = row ? row.querySelector('.echelp') : null;
    const px = (e, p) => e ? Math.round(parseFloat(getComputedStyle(e)[p])) : null;
    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
      switchFound: !!el,
      switchValue: el ? el.value : null,
      options: opt,
      labelText: label ? label.textContent : null,
      helpFirst80: help ? help.textContent.slice(0, 80) : null,
      rowWidth: row ? Math.round(row.getBoundingClientRect().width) : null,
      selectHeight: el ? Math.round(el.getBoundingClientRect().height) : null,
      labelFontSize: px(label, 'fontSize'),
      helpFontSize: px(help, 'fontSize'),
      settingsColumnFields: [...document.querySelectorAll('[data-paycard=tamara] .is-set [data-payf]')].map(e => e.dataset.payf),
    };
  }, sel);

  console.log(JSON.stringify(m, null, 1));
  const row = page.locator(sel).locator('xpath=ancestor::*[contains(@class,"mmcard")]');
  await row.screenshot({ path: out });
  await browser.close();
})();
