/*
 * Lane QK: Appearance -> Banners -> (homepage set) -> Text box -> Position on
 * a phone, with "Up and down" at Bottom, then 100 typed into "Custom up and
 * down" -- what the select says afterwards, and a picture of the block.
 *   QK_BASE=... QK_TAG=before|after QK_OUT=dir node tools/qk-admin-shots.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.QK_BASE, TAG = process.env.QK_TAG || 'x', OUT = process.env.QK_OUT;
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const width of [1280, 390]) {
    const page = await b.newPage({ viewport: { width, height: 1400 } });
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('banners'));
    await page.waitForTimeout(1400);
    await page.click('[data-bns-open]');
    await page.waitForTimeout(1800);
    await page.addStyleTag({ content: '.bns-foot{position:static !important}' });
    const sel = '[data-bns-set="tb_vpos_m"]', num = '[data-bns-set="tb_vval_m"]';
    await page.selectOption(sel, 'bottom');
    await page.fill(num, '');
    await page.type(num, '100');
    const state = await page.evaluate(([s, n]) => ({ select: document.querySelector(s).value, number: document.querySelector(n).value }), [sel, num]);
    const c = await page.evaluate(() => {
      const labs = [...document.querySelectorAll('#bns-editor .bns-lab')];
      const i = labs.findIndex((l) => /Position on a phone/.test(l.textContent));
      const top = labs[i].getBoundingClientRect().top + scrollY - 8;
      const grid = labs[i].parentElement.querySelectorAll('.bns-grid');
      const g = [...grid].filter((x) => x.getBoundingClientRect().top + scrollY > top).shift();
      return { top, h: g.getBoundingClientRect().bottom + scrollY + 8 - top };
    });
    await page.setViewportSize({ width, height: Math.ceil(c.top + c.h + 40) });
    await page.screenshot({ path: path.join(OUT, `admin-${TAG}-${width}.png`), clip: { x: 0, y: c.top, width, height: c.h } });
    console.log(JSON.stringify({ tag: TAG, width, ...state, scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), errors }));
    await page.close();
  }
  await b.close();
})();
