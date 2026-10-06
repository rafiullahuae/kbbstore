/*
 * Lane EB — the evidence. Run against tools/eb-preview.sh.
 *   PHASE=before node tools/eb-shots.cjs   product / category / brand page + the check screen (dry run)
 *   (apply: . storage/framework/testing/eb-preview/env.sh && php artisan tinker --execute="require 'tools/eb-apply.php';")
 *   PHASE=after  node tools/eb-shots.cjs   the same, after the migration ran
 * Chromium 1194, deviceScaleFactor 1, 390x844 and 1280x900.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:10581';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-eb-shots';
const PHASE = process.env.PHASE || 'before';
const URLS = JSON.parse(fs.readFileSync('storage/framework/testing/eb-preview/urls.json', 'utf8'));
const numbers = {};

const measure = (page) => page.evaluate(() => {
  const text = document.body.innerText;
  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    visibleOldName: (text.match(/extra[\s-]?beauty/gi) || []).length,
    visibleNewName: (text.match(/K-Beauty Bliss/g) || []).length,
    title: document.title,
    h1: (document.querySelector('h1') || {}).innerText || '',
  };
});

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  for (const [what, path] of Object.entries(URLS)) {
    for (const [w, h] of [[390, 844], [1280, 900]]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      await page.goto(BASE + path, { waitUntil: 'networkidle' });
      await page.waitForTimeout(400);
      const full = await page.evaluate(() => document.documentElement.scrollHeight);
      await page.screenshot({ path: `${OUT}/${PHASE}-${what}-${w}.png`, clip: { x: 0, y: 0, width: w, height: Math.min(full, w < 500 ? 2200 : 1600) } });
      numbers[`${PHASE}-${what}-${w}`] = await measure(page);
      if (what === 'product') {
        const desc = page.locator('text=/Formulated for/').first();
        if (await desc.count()) {
          await desc.scrollIntoViewIfNeeded();
          await page.waitForTimeout(200);
          await page.screenshot({ path: `${OUT}/${PHASE}-product-description-${w}.png` });
        }
        const rev = page.locator('text=/Ordered from/').first();
        if (await rev.count()) {
          await rev.scrollIntoViewIfNeeded();
          await page.waitForTimeout(200);
          await page.screenshot({ path: `${OUT}/${PHASE}-product-review-${w}.png` });
        }
      }
      await ctx.close();
    }
  }
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: 1000 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    page.on('dialog', (d) => d.accept());
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(600);
    await page.evaluate(() => window.go('seokeywords'));
    await page.waitForSelector('[data-skw-tab="brand"]', { timeout: 15000 });
    await page.click('[data-skw-tab="brand"]');
    await page.waitForSelector('#skwBrTitles', { timeout: 15000 });
    await page.evaluate(() => { for (const d of document.querySelectorAll('#content details')) if (/Every area checked/.test(d.innerText)) d.open = true; });
    await page.waitForTimeout(300);
    await page.locator('#content').screenshot({ path: `${OUT}/${PHASE}-check-screen-${w}.png` });
    numbers[`${PHASE}-check-screen-${w}`] = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      summary: [...document.querySelectorAll('#content details summary')].map((s) => s.innerText),
      warn: ((document.querySelector('#content .skw-warn, #content .skw-ok') || {}).innerText || '').slice(0, 120),
    }));
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/${PHASE}-numbers.json`, JSON.stringify(numbers, null, 1));
  console.log(JSON.stringify(numbers, null, 1));
})();
