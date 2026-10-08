/*
 * Lane TY — draw a body the race produced, in Chromium, with the preview's own
 * stylesheets: the BEFORE picture of the owner's page.
 *
 *   node tools/ty-shots/replay.mjs PORT BODY.html LABEL [SHOTS_DIR]
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright-core/index.mjs';
import fs from 'fs';

const [PORT, BODY, LABEL] = process.argv.slice(2);
const SHOTS = process.argv[5] || 'docs/lane-ty-shots';
const BASE = `http://127.0.0.1:${PORT}`;
const html = fs.readFileSync(BODY, 'utf8');
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const out = {};
for (const width of [390, 1280]) {
  const context = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : 900 }, serviceWorkers: 'block' });
  const page = await context.newPage();
  await page.route(`${BASE}/checkout/success?order=replay`, r => r.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html }));
  await page.goto(`${BASE}/checkout/success?order=replay`, { waitUntil: 'networkidle' });
  out[width] = await page.evaluate(() => {
    const sum = [...document.querySelectorAll('.co-received .sec')].find(s => (s.querySelector('h2')?.textContent || '').includes('Your order'));
    const h2 = sum.querySelector('h2');
    return {
      summaryBelowHeading: Math.round(sum.getBoundingClientRect().bottom - h2.getBoundingClientRect().bottom),
      lines: sum.querySelectorAll('.ci').length,
      totalRow: !!sum.querySelector('.sumrow.tot'),
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
  await page.locator('.co-received .sec', { hasText: 'Your order' }).first().scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${SHOTS}/${LABEL}-${width}.png`, fullPage: true });
  await context.close();
}
console.log(JSON.stringify(out));
await browser.close();
