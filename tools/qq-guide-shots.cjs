/*
 * Store -> Quiqup -> Setup guide, at 1280 and 390 (the owner, 14 Nov 2026: "i need a complete guide ...
 * make this guide available in the quiqup tab too"). Against tools/qq-preview.sh.
 *   NODE_PATH=/home/user/kbbstore/node_modules QQ_BASE=http://127.0.0.1:8931 node tools/qq-guide-shots.cjs
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.QQ_BASE || 'http://127.0.0.1:8931';
const OUT = process.env.QQ_OUT || path.join(__dirname, '..', 'docs', 'lane-qq-shots');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const browser = await chromium.launch();
  const out = {};
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('quiqup'));
    await page.waitForSelector('[data-qq-tab="guide"]');
    await page.click('[data-qq-tab="guide"]');
    await page.waitForSelector('.qq-guide');
    await new Promise((r) => setTimeout(r, 300));
    out[w] = {
      steps: await page.$$eval('.qq-guide .qq-step', (e) => e.length),
      links: await page.$$eval('.qq-guide a', (e) => e.map((a) => a.getAttribute('href'))),
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
      errors,
    };
    await page.screenshot({ path: path.join(OUT, `guide-${w}.png`), fullPage: true });
    await ctx.close();
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
