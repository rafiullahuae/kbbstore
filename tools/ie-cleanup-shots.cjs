/*
 * Store → Import → Clean up before the migration, at both widths. (Lane IE)
 *
 *   IE_BASE=http://127.0.0.1:8951 node tools/ie-cleanup-shots.cjs
 *
 * It DRIVES the screen rather than posing it: it reads the counts the page
 * drew, ticks the boxes, types the word, presses the button and reads the
 * counts back — so a button that is drawn and does nothing shows up here as a
 * number that did not move.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.IE_BASE || 'http://127.0.0.1:8951';
const OUT = process.env.IE_OUT || path.resolve(__dirname, '..') + '/docs/ie-cleanup-shots';

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

  const url = `${BASE}/admin-api/cleanup/page`;

  for (const w of [1280, 390]) {
    await page.setViewportSize({ width: w, height: w === 390 ? 900 : 1100 });
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.waitForSelector('input[data-bucket]', { timeout: 10000 });
    await page.waitForTimeout(400);

    const m = await page.evaluate(() => {
      const counts = [...document.querySelectorAll('.card')].map((c) => {
        const h = c.querySelector('h2');
        const n = c.querySelector('.count');
        return (h ? h.textContent.trim() : '(no heading)') + ' = ' + (n ? n.textContent.trim() : '-');
      });
      return {
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        buckets: [...document.querySelectorAll('input[data-bucket]')].map((i) => i.getAttribute('data-bucket')),
        disabled: [...document.querySelectorAll('input[data-bucket]')].filter((i) => i.disabled).length,
        goDisabled: document.getElementById('go').disabled,
        counts,
        bodyFont: getComputedStyle(document.body).fontSize,
      };
    });

    console.log(`--- ${w}px`, JSON.stringify(m, null, 2));

    await page.screenshot({ path: `${OUT}/cleanup-${w}.png`, fullPage: true });
  }

  /* ---- now DRIVE it: tick, type, press, and read the numbers back -------- */

  await page.setViewportSize({ width: 1280, height: 1100 });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('input[data-bucket]');

  const before = await page.evaluate(() =>
    [...document.querySelectorAll('input[data-bucket]')].map((i) => ({
      k: i.getAttribute('data-bucket'),
      n: i.closest('.card').querySelector('.count').textContent.trim(),
    })));

  // The button stays dead until BOTH a box is ticked and the word is typed.
  await page.check('input[data-bucket=demo_products]');
  const afterTickOnly = await page.evaluate(() => document.getElementById('go').disabled);

  await page.fill('#confirm', 'yes please');
  const afterWrongWord = await page.evaluate(() => document.getElementById('go').disabled);

  await page.fill('#confirm', 'DELETE');
  const afterRightWord = await page.evaluate(() => document.getElementById('go').disabled);

  console.log('go disabled — ticked only:', afterTickOnly,
              '| wrong word:', afterWrongWord, '| DELETE:', afterRightWord);

  await page.screenshot({ path: `${OUT}/cleanup-armed-1280.png`, fullPage: true });

  await page.check('input[data-bucket=patch_archives]');
  await page.fill('#confirm', 'DELETE');
  await page.click('#go');
  await page.waitForSelector('.msg', { timeout: 10000 });
  await page.waitForTimeout(900);

  const after = await page.evaluate(() => ({
    msg: document.querySelector('.msg') ? document.querySelector('.msg').textContent.trim() : '(none)',
    counts: [...document.querySelectorAll('input[data-bucket]')].map((i) => ({
      k: i.getAttribute('data-bucket'),
      n: i.closest('.card').querySelector('.count').textContent.trim(),
    })),
  }));

  console.log('BEFORE', JSON.stringify(before));
  console.log('AFTER ', JSON.stringify(after, null, 2));

  await page.screenshot({ path: `${OUT}/cleanup-after-1280.png`, fullPage: true });

  await page.setViewportSize({ width: 390, height: 900 });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForSelector('input[data-bucket]');
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${OUT}/cleanup-after-390.png`, fullPage: true });

  await browser.close();
})();
