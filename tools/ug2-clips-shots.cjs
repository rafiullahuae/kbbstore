/*
 * Lane UG2 — Content → Shoppable video → All clips, with a row whose file is
 * gone.
 *
 *   BASE=http://127.0.0.1:8991 node tools/ug2-clips-shots.cjs
 *
 * The claim being photographed is one badge. `media_state` is computed from
 * three COLUMNS, so a clip whose video file had been deleted off the server
 * went on badging "Loops from full video" — a clip described as looping with
 * nothing left to loop. The seed's `ug2-lost-file` row is exactly that shape:
 * a cover that loads perfectly beside a video that is not there.
 *
 * It reports the badge text per row as well as shooting it, because a
 * screenshot of a list of seven is not a number anybody can check.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8991';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ug2-shots';
const TAG = process.env.TAG || 'clips';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const report = {};

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
    await page.evaluate(() => window.go('ugcvideo'));
    await page.waitForTimeout(1800);

    report[String(width)] = await page.evaluate(() => {
      return [...document.querySelectorAll('.ugs-tile')].map((t) => ({
        name: (t.querySelector('.ugs-tn') || {}).textContent || null,
        pills: [...t.querySelectorAll('.ugs-pills > *')].map((p) => p.textContent.trim()),
      }));
    });

    console.log('== ' + width + ' ==');
    console.log(JSON.stringify(report[String(width)], null, 1));

    await page.screenshot({ path: `${OUT}/admin-${TAG}-${width}.png`, fullPage: true });
    await ctx.close();
  }

  fs.writeFileSync(`${OUT}/${TAG}.json`, JSON.stringify(report, null, 1));
  await browser.close();
})();
