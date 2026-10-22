/* Lane FG2: the redesigned Growth & Marketing -> Free gifts screen, every tab
   at 1280 and 390, a range open, the picker, the save bar, the mini preview,
   the inline overlap message and the delete confirm -- plus the numbers: page
   width, console errors, and how many search requests typing makes.
   FG_SEED=fg2-seed.php FG_ON=0 sh tools/fg-preview.sh 10660
   BASE=http://127.0.0.1:10660 node tools/fg2-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-fg2');
fs.mkdirSync(OUT, { recursive: true });
const wait = (p, ms) => p.waitForTimeout(ms);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [], searches = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.url().includes('/admin-api/free-gifts/products?')) searches.push(r.url()); });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.evaluate(() => window.go('freegifts'));
    await page.locator('[data-screen="freegifts"] .fg-status').waitFor();
    await page.locator('.fg-kpis .kpi .val').first().filter({ hasNotText: '…' }).waitFor();
    await wait(page, 400);
    /* The console scrolls inside #content, not the document, so "full page"
       means growing the window to the content's own height for the shot. */
    const H = w === 390 ? 844 : 900;
    const shot = async (name, opts) => {
      const full = !(opts && opts.fullPage === false);
      if (full) {
        const need = await page.evaluate(() => { const c = document.querySelector('#content'); return c.scrollHeight + c.getBoundingClientRect().top + 4; });
        await page.setViewportSize({ width: w, height: Math.max(H, Math.ceil(need)) }); await wait(page, 150);
      }
      await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
      if (full) { await page.setViewportSize({ width: w, height: H }); await wait(page, 100); }
    };
    const m = { scrollWidth: {} };
    const sw = async (k) => { m.scrollWidth[k] = await page.evaluate(() => document.documentElement.scrollWidth); };

    await shot('1-overview'); await sw('overview');
    m.kpis = await page.$$eval('.fg-kpis .kpi', (ks) => ks.map((k) => k.querySelector('.lbl').textContent + ': ' + k.querySelector('.val').textContent));

    // Mode: add this device's IP, a labelled second one, then Testing.
    if (await page.locator('[data-fk="tester-mine"]:not([disabled])').count()) {
      await page.locator('[data-fk="tester-mine"]').click(); await wait(page, 700);
    }
    if (!(await page.locator('.fg-iplist code', { hasText: '203.0.113.7' }).count())) {
      await page.locator('[data-fk="tester-ip"]').fill('203.0.113.7');
      await page.locator('[data-fk="tester-label"]').fill('Rafi phone');
      await page.locator('button', { hasText: 'Add IP' }).click(); await wait(page, 700);
    }
    await page.locator('[data-fk="tester-ip"]').fill('203.0.113.300');
    await page.locator('button', { hasText: 'Add IP' }).click(); await wait(page, 700);
    m.invalidIpMessage = await page.locator('.fg-note, .fg-testers .fg-msg').first().textContent().catch(() => null);
    await page.locator('[data-fk="tester-ip"]').fill('');
    await page.locator('[data-fk="mode-testing"]').click(); await wait(page, 800);
    m.banner = await page.locator('.fg-banner').textContent();
    await shot('1b-overview-testing'); await sw('overview-testing');

    await page.locator('.fg-tab', { hasText: 'Ranges & gifts' }).click(); await wait(page, 250);
    await shot('2-ranges-collapsed'); await sw('ranges');
    m.rangeRowHeight = await page.$eval('.fg-rg-h', (e) => e.offsetHeight);

    await page.locator('.fg-rg-h').nth(2).click(); await wait(page, 300);
    await shot('3-range-expanded'); await sw('range-open');
    await page.locator('.fg-rg.open .fg-pvcol').screenshot({ path: `${OUT}/4-mini-preview-${w}.png` });

    // The picker: type a word one letter at a time and count the requests.
    await page.locator('.fg-rg.open button', { hasText: 'Add gift' }).click();
    await page.locator('.fg-modal').waitFor(); await wait(page, 500);
    const before = searches.length;
    await page.locator('[data-fk="pk-q"]').pressSequentially('mugwort', { delay: 60 });
    await wait(page, 900);
    m.searchRequestsForSevenLetters = searches.length - before;
    await page.locator('[data-fk="pk-q"]').fill('');
    await page.locator('[data-fk="pk-q"]').pressSequentially('m', { delay: 60 });
    await wait(page, 600);
    m.searchRequestsForOneLetter = searches.length - before - m.searchRequestsForSevenLetters;
    await page.locator('[data-fk="pk-q"]').fill('mask'); await wait(page, 900);
    await page.locator('.fg-res[aria-pressed="false"]:not([disabled])').first().click(); await wait(page, 150);
    await shot('5-picker-open', { fullPage: false }); await sw('picker');
    await page.keyboard.press('Escape'); await wait(page, 300);

    // A quantity change: the save bar appears, the preview follows.
    await page.locator('.fg-rg.open .fg-step button[aria-label^="One more"]').first().click(); await wait(page, 200);
    await page.locator('.fg-bar').scrollIntoViewIfNeeded();
    await shot('6-save-bar', { fullPage: false });
    m.saveBarVisible = await page.locator('.fg-bar').isVisible();

    // The inline overlap message while typing.
    await page.locator('.fg-rg.open [data-fk^="min-"]').fill('450'); await wait(page, 200);
    m.inlineMessages = await page.$$eval('.fg-rg.open .fg-msg', (x) => x.map((e) => e.textContent));
    await page.locator('.fg-rg.open .fg-ed').screenshot({ path: `${OUT}/7-overlap-inline-${w}.png` });
    await page.locator('.fg-bar button', { hasText: 'Discard' }).click(); await wait(page, 300);
    m.saveBarAfterDiscard = await page.locator('.fg-bar').isVisible();

    // Remove with undo; delete with the confirm built into the page.
    await page.locator('.fg-rg-h').nth(2).click(); await wait(page, 150);
    await page.locator('.fg-rg-h').nth(2).click(); await wait(page, 250);
    await page.locator('.fg-rg.open .fg-btn-i.danger').first().click(); await wait(page, 200);
    await page.locator('.fg-rg.open button', { hasText: 'Delete…' }).click(); await wait(page, 200);
    await page.locator('.fg-rg.open .fg-ed').screenshot({ path: `${OUT}/8-undo-and-delete-confirm-${w}.png` });
    await page.locator('.fg-rg.open button', { hasText: 'Keep it' }).click(); await wait(page, 150);
    await page.locator('.fg-rg.open .fg-undo button').click(); await wait(page, 200);

    // A real save: one quantity up, Save, the toast says what was saved.
    await page.locator('.fg-rg.open .fg-step button[aria-label^="One more"]').first().click(); await wait(page, 150);
    await page.locator('.fg-bar button', { hasText: 'Save' }).click();
    await page.locator('#toast.show').waitFor(); await wait(page, 200);
    m.toast = await page.locator('#toast').textContent();
    await shot('9-saved-toast', { fullPage: false });
    await page.locator('.fg-rg.open .fg-step button[aria-label^="One fewer"]').first().click();
    await page.locator('.fg-bar button', { hasText: 'Save' }).click(); await wait(page, 900);

    await page.locator('.fg-tab', { hasText: 'Gift products' }).click(); await wait(page, 250);
    await shot('10-gift-products'); await sw('products');
    await page.locator('button', { hasText: 'New gift-only product' }).click(); await wait(page, 600);
    await page.locator('.fg-create').screenshot({ path: `${OUT}/11-create-gift-product-${w}.png` });

    await page.locator('.fg-tab', { hasText: 'Display' }).click(); await wait(page, 250);
    await shot('12-display'); await sw('display');
    await page.locator('[data-fk="t-titleen"]').fill('Your free gifts'); await wait(page, 150);
    m.displayPreviewTitle = await page.locator('.fg-disp .fgp legend span').first().textContent();
    await page.locator('.fg-tab', { hasText: 'Homepage section' }).click(); await wait(page, 250);
    await shot('13-homepage-section'); await sw('home');

    m.errors = errors;
    report[w] = m;
    console.log(w, JSON.stringify(m));
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(report, null, 2));
  await browser.close();
})();
