/* Lane GT: Growth & Marketing -> Free gifts -> Ranges & gifts -> Templates,
   at 1280 and 390: the empty card, Save as template, the ranges changed
   ("Edited since"), Make live through the shared "Are you sure?" box (the
   ranges switch back in place), the automatic "Before ..." entry, Rename and
   Delete. Numbers: scrollWidth, console errors, the requests and time an
   apply takes.
     sh tools/gt-preview.sh 10990
     BASE=http://127.0.0.1:10990 node tools/gt-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-gt-shots');
fs.mkdirSync(OUT, { recursive: true });
const wait = (p, ms) => p.waitForTimeout(ms);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [1280, 390]) {
    const H = w === 390 ? 844 : 900;
    const ctx = await browser.newContext({ viewport: { width: w, height: H }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [], reqs = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' ' + ((m.location() || {}).url || '').replace(BASE, '')); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.url().includes('/admin-api/')) reqs.push({ t: Date.now(), m: r.method(), u: r.url().replace(BASE, '') }); });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    const open = async () => {
      await page.evaluate(() => window.go('freegifts'));
      await page.locator('.fg-tab', { hasText: 'Ranges & gifts' }).click();
      await page.locator('[data-fk-panel="tpl"] .fg-ql[aria-busy="false"]').waitFor();
      await wait(page, 250);
    };
    await open();
    const m = { scrollWidth: {} };
    const sw = async (k) => { m.scrollWidth[k] = await page.evaluate(() => document.documentElement.scrollWidth); };
    const tpl = page.locator('[data-fk-panel="tpl"]');
    const shot = async (name) => { await tpl.scrollIntoViewIfNeeded(); await tpl.screenshot({ path: `${OUT}/${name}-${w}.png` }); };
    const strip = async () => page.$$eval('.fg-rtab .fg-rt-l', (n) => n.map((e) => e.textContent));
    const sfx = w === 390 ? ' (phone)' : '';

    await shot('1-card'); await sw('card');

    // Save as template.
    await page.fill('[data-fk="tpl-name"]', 'Everyday set' + sfx);
    await page.click('[data-fk="tpl-save"]');
    await tpl.locator('.fg-tpl-r', { hasText: 'Everyday set' + sfx }).waitFor();
    await wait(page, 200);
    await shot('2-saved'); await sw('saved');
    m.rangesSaved = await strip();

    // Change the ranges: the top range is deleted through the ordinary endpoint.
    await page.evaluate(async () => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const root = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
      const d = await (await fetch(root + '/admin-api/free-gifts', { headers: { Accept: 'application/json' } })).json();
      const top = d.ranges.slice().sort((a, b) => b.min - a.min)[0];
      await fetch(root + '/admin-api/free-gifts/ranges/' + top.id, { method: 'DELETE', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': x } });
    });
    await open();
    m.rangesChanged = await strip();
    await shot('3-edited-since');
    await page.screenshot({ path: `${OUT}/3b-ranges-changed-page-${w}.png` });
    const rangesCard = page.locator('section.card:has(.fg-rtabs)');
    await rangesCard.scrollIntoViewIfNeeded(); await rangesCard.screenshot({ path: `${OUT}/3c-range-tabs-changed-${w}.png` });

    // Make live: the shared box asks first.
    const row = tpl.locator('.fg-tpl-r', { hasText: 'Everyday set' + sfx });
    await row.locator('button', { hasText: 'Make live' }).click();
    await page.locator('#kbbSureBg.on').waitFor();
    m.confirmText = await page.textContent('#kbbSureText');
    await page.screenshot({ path: `${OUT}/4-make-live-confirm-${w}.png` });
    const before = reqs.length, navs = [];
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) navs.push(f.url()); });
    const t0 = Date.now();
    await page.click('#kbbSureYes');
    await tpl.locator('.fg-tpl-r', { hasText: 'Before Everyday set' }).first().waitFor();
    m.applyMs = Date.now() - t0;
    await wait(page, 300);
    m.applyRequests = reqs.slice(before).map((r) => r.m + ' ' + r.u);
    m.reloads = navs.length;
    m.rangesAfterApply = await strip();
    m.state = await page.textContent('[data-fk="tpl-state"]');
    await shot('5-applied-before-entry'); await sw('applied');
    await page.screenshot({ path: `${OUT}/5b-ranges-back-page-${w}.png` });
    await page.locator('section.card:has(.fg-rtabs)').scrollIntoViewIfNeeded();
    await page.locator('section.card:has(.fg-rtabs)').screenshot({ path: `${OUT}/5c-range-tabs-back-${w}.png` });

    // Rename the automatic entry.
    const auto = tpl.locator('.fg-tpl-r', { hasText: 'Before Everyday set' + sfx }).first();
    await auto.locator('button', { hasText: 'Rename' }).click();
    await page.fill('[data-fk="tpl-ren-in"]', 'Without the top range' + sfx);
    await shot('6-rename-edit');
    await page.click('[data-fk="tpl-ren-go"]');
    await page.locator('#kbbSureBg.on').waitFor();
    m.renameConfirm = await page.textContent('#kbbSureText');
    await page.click('#kbbSureYes');
    await tpl.locator('.fg-tpl-r', { hasText: 'Without the top range' + sfx }).waitFor();
    await wait(page, 200);
    await shot('7-renamed');

    // Delete it.
    await tpl.locator('.fg-tpl-r', { hasText: 'Without the top range' + sfx }).locator('button', { hasText: 'Delete' }).click();
    await page.locator('#kbbSureBg.on').waitFor();
    m.deleteConfirm = await page.textContent('#kbbSureText');
    await page.screenshot({ path: `${OUT}/8-delete-confirm-${w}.png` });
    await page.click('#kbbSureYes');
    await tpl.locator('.fg-tpl-r', { hasText: 'Without the top range' + sfx }).waitFor({ state: 'detached' });
    await wait(page, 200);
    await shot('9-deleted'); await sw('deleted');

    m.errors = errors;
    report[w] = m;
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
})();
