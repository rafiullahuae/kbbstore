/*
 * Lane UG2 — the Motion screen saying what the storefront will do.
 *
 *   BASE=http://127.0.0.1:8989 node tools/ug2-admin-shots.cjs
 *
 * The screen is #ugcstyle — Content → Shoppable video → Appearance — and the
 * panel lives on its Motion tab, which is where the two controls it talks about
 * (the loop switch and the cap) already are.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8989';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ug2-shots';
const TAG = process.env.TAG || 'motion';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: 1100 } });
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
    await page.evaluate(() => window.go('ugcstyle'));
    await page.waitForTimeout(1600);
    await page.evaluate(() => {
      const tab = [...document.querySelectorAll('[data-ugy-tab]')]
        .find((b) => b.getAttribute('data-ugy-tab') === 'motion');
      if (tab) tab.click();
    });
    await page.waitForTimeout(700);

    const said = await page.evaluate(() => {
      const n = document.querySelector('.ugy-pl');
      const note = n ? n.closest('.ugy-note') : null;
      return note ? note.innerText.replace(/\n{2,}/g, '\n') : '(no playback panel)';
    });
    console.log('== ' + width + ' ==\n' + said);

    await page.screenshot({ path: `${OUT}/admin-${TAG}-${width}.png`, fullPage: true });
    await ctx.close();
  }

  await browser.close();
})();
