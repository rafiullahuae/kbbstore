/*
 * LANE OA4 — App → Owner App, with docs/oa4-wiring.json applied (php tools/oa4-wire.php).
 *
 *   KBB_BASE=http://127.0.0.1:P node tools/oa4-screen-shots.cjs
 *
 * The sidebar row, the screen (Access & security, Customise app) at 1280 and
 * 390, and the pointer left on Users & Roles → Owner app.
 * Output: docs/oa4-shots/screen-*.jpg and docs/oa4-shots/screen-numbers.txt.
 */
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const BASE = process.env.KBB_BASE;
const OUT = path.join(__dirname, '..', 'docs', 'oa4-shots');
const lines = [];
const say = (s) => { lines.push(s); process.stdout.write(s + '\n'); };

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 700 ? 844 : 900 }, deviceScaleFactor: w < 700 ? 2 : 1 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text().slice(0, 160)); });
    page.on('pageerror', (e) => errs.push('PAGE ERROR ' + e.message));
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@example.com');
    await page.fill('input[name=password]', 'preview-password');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
    await page.goto(BASE + '/admin?go=ownerapp', { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-oa-screen] [data-oac-tab]', { timeout: 15000 });
    await page.waitForTimeout(600);
    const nav = await page.evaluate(() => {
      const b = document.querySelector('#nav [data-go="ownerapp"]');
      const g = b && b.closest('.nav-group');
      return { rows: document.querySelectorAll('#nav [data-go="ownerapp"]').length, group: g ? g.getAttribute('data-sec') : '(none)', title: (document.querySelector('#ptitle') || {}).textContent, crumb: (document.querySelector('#crumb') || {}).textContent };
    });
    await page.screenshot({ path: path.join(OUT, 'screen-' + w + '--1-access.jpg'), type: 'jpeg', quality: 82 });
    await page.click('[data-oa-screen] [data-oac-tab="ui"]');
    await page.waitForSelector('[data-oa-screen] .oac-ctl');
    await page.waitForTimeout(700);
    await page.screenshot({ path: path.join(OUT, 'screen-' + w + '--2-customise.jpg'), type: 'jpeg', quality: 82 });
    const sw = await page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);

    await page.goto(BASE + '/admin?go=users', { waitUntil: 'networkidle' });
    await page.waitForSelector('#rlt_ownerapp', { timeout: 15000 });
    await page.click('#rlt_ownerapp');
    await page.waitForSelector('[data-go-ownerapp]');
    const pointer = (await page.textContent('[data-rl-screen] [data-oa-admin]')).trim();
    await page.screenshot({ path: path.join(OUT, 'screen-' + w + '--3-users-roles-pointer.jpg'), type: 'jpeg', quality: 82 });
    await page.click('[data-go-ownerapp]');
    await page.waitForSelector('[data-oa-screen]');
    say(w + 'px: sidebar rows for ownerapp ' + nav.rows + ' in group "' + nav.group + '"; title "' + nav.title + '", crumb "' + nav.crumb + '"; scrollWidth ' + sw.join('/')
      + '; Users & Roles tab says "' + pointer + '" and its button opens the screen; console errors: ' + (errs.length ? errs.join(' | ') : 'none'));
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(path.join(OUT, 'screen-numbers.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
