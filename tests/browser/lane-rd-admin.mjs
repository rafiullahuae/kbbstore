/*
 * Lane RD — the admin control: Appearance → Site layout → Press feedback.
 * Opens the tab, photographs it at 390 and 1280, presses a "Try it" sample
 * mid-animation, and (RD_SET) chooses a letter in the select and presses Save,
 * which is how a screenshot run moves the shop between letters through the
 * real screen rather than behind it.
 *
 *   RD_BASE, RD_OUT, RD_CHROME   as lane-rd-press-feedback.mjs
 *   RD_EMAIL / RD_PASSWORD       an owner (tools/rd-seed.php seeds one)
 *   RD_SET                       a letter to choose and save, or empty
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.RD_BASE || 'http://127.0.0.1:9960';
const OUT = process.env.RD_OUT || './storage/rd-logs/shots';
const CHROME = process.env.RD_CHROME;
const SET = process.env.RD_SET || '';
const SHOOT = process.env.RD_SHOOT !== '0';

mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);
const report = {};

for (const [label, opts] of SHOOT
  ? [['1280', { viewport: { width: 1280, height: 1000 } }], ['390', { viewport: { width: 390, height: 900 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }]]
  : [['1280', { viewport: { width: 1280, height: 1000 } }]]) {
  const ctx = await browser.newContext(opts);
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  if (await page.locator('input[type=email]').count()) {
    await page.fill('input[type=email]', process.env.RD_EMAIL || 'owner@preview.test');
    await page.fill('input[type=password]', process.env.RD_PASSWORD || 'preview-secret-1');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
  }
  await page.goto(`${BASE}/admin?go=sitelayout`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  await page.locator('[data-sls-tab="press"]').click();
  await page.waitForTimeout(400);

  if (SET && label === '1280') {
    await page.selectOption('#sls-press', SET);
    await page.waitForTimeout(300);
    await page.locator('[data-sls-save]').click();
    await page.waitForTimeout(1200);
  }

  report[label] = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    innerWidth: window.innerWidth,
    selected: document.querySelector('#sls-press') ? document.querySelector('#sls-press').value : null,
    options: [...document.querySelectorAll('#sls-press option')].map((o) => o.value + ' = ' + o.textContent),
    crumb: (document.querySelector('#crumb') || {}).textContent,
    title: (document.querySelector('#ptitle') || {}).textContent,
    tab: (document.querySelector('[data-sls-tab][aria-selected="true"]') || {}).textContent,
    preview: (document.querySelector('[data-sls-press-box]') || { dataset: {} }).getAttribute ? document.querySelector('[data-sls-press-box]').getAttribute('data-sls-press') : null,
  }));

  if (SHOOT) {
    const tag = SET ? `admin-saved-${SET}` : 'admin';
    await page.screenshot({ path: `${OUT}/${tag}-${label}.png`, fullPage: false });
    const sample = page.locator('.slp .slp-b');
    if (await sample.count()) {
      await sample.scrollIntoViewIfNeeded();
      const box = page.locator('[data-sls-press-box]');
      await sample.dispatchEvent('pointerdown', { bubbles: true, button: 0, isPrimary: true });
      await page.waitForTimeout(90);
      await box.screenshot({ path: `${OUT}/${tag}-${label}-try-press.png` });
      await sample.dispatchEvent('pointerup', { bubbles: true, button: 0, isPrimary: true });
    }
  }
  await ctx.close();
}

await browser.close();
writeFileSync(`${OUT}/report-admin${SET ? '-' + SET : ''}.json`, JSON.stringify(report, null, 2));
console.log(JSON.stringify(report));
