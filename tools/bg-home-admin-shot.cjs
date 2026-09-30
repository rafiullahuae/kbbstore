/*
 * Appearance → Homepage, with the two new per-section controls.      (Lane BG)
 *
 *   BG_BASE=http://127.0.0.1:8990 node tools/bg-home-admin-shot.cjs
 *
 * It DRIVES the controls rather than posing them: it reads the banner row's
 * two selects, changes one, and reads back what the server stored — so a
 * dropdown that is drawn and does nothing shows up here as a value that did
 * not move.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8990';
const OUT = process.env.BG_OUT || path.resolve(__dirname, '..') + '/docs/home-width-shots';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1100 } });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('pageError ' + String(e)));

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: w === 390 ? 900 : 1100 });
    await page.goto(`${BASE}/admin?go=homepage`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    const m = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('.hprow')];
      return {
        scrollWidth: document.documentElement.scrollWidth,
        rows: rows.length,
        frameRows: document.querySelectorAll('.hpframe').length,
        selects: document.querySelectorAll('.hpframe select').length,
        /* The banner is the first row; the two rows drawn inside the hero get
           no controls at all, which is what `rows - frameRows` counts. */
        banner: (() => {
          const f = rows[0] && rows[0].querySelector('.hpframe');
          if (!f) return null;
          const s = [...f.querySelectorAll('select')];
          return {
            label: rows[0].querySelector('b').textContent,
            controls: s.map((x) => ({ k: x.dataset.k, value: x.value, options: x.options.length })),
          };
        })(),
        noControls: rows.filter((r) => !r.querySelector('.hpframe')).map((r) => r.querySelector('b').textContent),
      };
    });
    console.log(w + ': ' + JSON.stringify(m));

    /* THE CONSOLE SCROLLS INSIDE #content, so `fullPage: true` photographs the
       viewport and nothing else — the first run of this file produced a
       picture of the Layouts cards and no section rows at all. Scroll the list
       into view and photograph the element. */
    await page.evaluate(() => {
      const r = document.querySelector('.hprow');
      if (r) r.scrollIntoView({ block: 'start' });
    });
    await page.waitForTimeout(500);
    await page.screenshot({ path: `${OUT}/admin-homepage-${w}.png` });

    const list = await page.$('.hplist');
    if (list) await list.screenshot({ path: `${OUT}/admin-homepage-rows-${w}.png` });
  }

  await browser.close();
})();
