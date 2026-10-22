/* Lane FG3: Growth & Marketing -> Free gifts, at 1280 and 390 -- the range
   tabs, the unsaved dot, instant search while typing, the warning
   highlights, and Overview in Testing despite them -- plus the numbers: page
   width, console errors, search requests per word, aborted stale searches.
   FG_SEED=fg2-seed.php FG_ON=0 sh tools/fg-preview.sh 10760
   BASE=http://127.0.0.1:10760 node tools/fg3-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-fg3');
fs.mkdirSync(OUT, { recursive: true });
const wait = (p, ms) => p.waitForTimeout(ms);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [], searches = [], aborted = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.url().includes('/admin-api/free-gifts/products?')) searches.push(decodeURIComponent(r.url().split('q=')[1].split('&')[0])); });
    page.on('requestfailed', (r) => { if (r.url().includes('/products?')) aborted.push(decodeURIComponent(r.url().split('q=')[1].split('&')[0]) + ' ' + (r.failure() || {}).errorText); });
    // A slow server for the first question, a quick one for the next: the
    // first answer would land LAST if nothing aborted it.
    await page.route('**/admin-api/free-gifts/products?*', async (route) => {
      const q = decodeURIComponent(route.request().url().split('q=')[1].split('&')[0]);
      await new Promise((r) => setTimeout(r, q === 'mu' ? 1500 : 250));
      route.continue().catch(() => {});
    });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    // Start each width from the same state: Off, nobody on the Testing list.
    await page.evaluate(async () => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x };
      const base = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '');
      await fetch(base + '/admin-api/free-gifts/switch', { method: 'POST', headers: h, body: JSON.stringify({ mode: 'off' }), credentials: 'same-origin' });
      await fetch(base + '/admin-api/free-gifts/testers', { method: 'POST', headers: h, body: JSON.stringify({ testers: [] }), credentials: 'same-origin' });
      try { localStorage.removeItem('kbb.freegifts.range'); } catch (e) {}
    });
    await page.evaluate(() => window.go('freegifts'));
    await page.locator('[data-screen="freegifts"] .fg-status').waitFor();
    await page.locator('.fg-kpis .kpi .val').first().filter({ hasNotText: '…' }).waitFor();
    await wait(page, 400);
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

    // 1. Overview, Off: "Things to check" as highlighted warnings.
    m.checks = await page.$$eval('.fg-checks li', (l) => l.map((e) => e.className + ' | ' + e.textContent));
    m.modeButtonsDisabled = await page.$$eval('.fg-mode', (b) => b.map((e) => e.textContent.slice(0, 7) + ':' + e.disabled));
    await shot('1-overview-warnings'); await sw('overview');

    // 2. Testing, with warnings open and nobody on the list: it switches, and
    //    the owner's IP is added with a toast.
    await page.locator('[data-fk="mode-testing"]').click();
    await page.locator('#toast.show').waitFor(); await wait(page, 250);
    m.testingToast = await page.locator('#toast').textContent();
    m.modeAfter = await page.locator('.fg-mode[aria-checked="true"]').textContent();
    await shot('2-overview-testing-despite-warnings', { fullPage: false }); await sw('overview-testing');
    await shot('2b-overview-testing-full');

    // 3. "Open range" from the checklist opens that range's tab.
    const openLinks = page.locator('.fg-checks li.lv-warn .fg-link', { hasText: 'Open range' });
    await openLinks.first().click(); await wait(page, 300);
    m.openedFromOverview = await page.locator('.fg-rtab[aria-selected="true"]').textContent();
    // Pick the range whose tab carries the red "!" (the out-of-stock balm).
    await page.locator('#toast.show').waitFor({ state: 'detached' }).catch(() => {}); await wait(page, 300);
    const warnTab = page.locator('.fg-rtab:has(.fg-rt-warn)').first();
    await warnTab.click(); await wait(page, 300);
    m.selectedTab = await page.locator('.fg-rtab[aria-selected="true"]').textContent();
    m.outBadges = await page.$$eval('.fg-gi.out .pill', (p) => p.map((e) => e.textContent));
    m.editorsShown = await page.locator('.fg-rg.open').count();
    m.tabs = await page.$$eval('.fg-rtab', (t) => t.map((e) => e.textContent + (e.className.includes('off') ? ' [off]' : '') + (e.getAttribute('aria-selected') === 'true' ? ' [selected]' : '')));
    m.tabStrip = await page.$eval('.fg-rtabs', (e) => ({ client: e.clientWidth, scroll: e.scrollWidth, overflowX: getComputedStyle(e).overflowX }));
    await shot('3-range-tabs-selected'); await sw('ranges');
    await page.locator('.fg-gi.out').first().screenshot({ path: `${OUT}/4-out-of-stock-highlight-${w}.png` });

    // 5. Edit this range, move to another tab: the edit is kept and the tab
    //    carries the unsaved dot.
    const editedTab = await page.locator('.fg-rtab[aria-selected="true"]').getAttribute('data-fk');
    const origLabel = await page.locator('.fg-rg.open [data-fk^="label_en-"]').inputValue();
    await page.locator('.fg-rg.open [data-fk^="label_en-"]').fill(origLabel + ' (edited)'); await wait(page, 150);
    // The neighbouring tab, so the edited one stays in view on a phone too.
    await page.locator('.fg-rtab:not([aria-selected="true"]):not(.add)').last().click(); await wait(page, 300);
    m.unsavedDotOn = await page.locator(`[data-fk="${editedTab}"] .fg-rt-dirty`).count();
    m.saveButtonsOnPage = await page.locator('button', { hasText: 'Save range' }).count();
    m.globalBar = await page.locator('.fg-bar').count();
    await page.locator('.fg-rtabs').scrollIntoViewIfNeeded();
    await page.locator(`[data-fk="${editedTab}"]`).scrollIntoViewIfNeeded(); await wait(page, 150);
    await shot('5-unsaved-dot', { fullPage: false });
    await page.locator(`[data-fk="${editedTab}"]`).click(); await wait(page, 250);
    m.editKept = await page.locator('.fg-rg.open [data-fk^="label_en-"]').inputValue();

    // 6. Instant search: type, no Enter, results arrive; a slow first answer is aborted.
    await page.locator('.fg-rg.open button', { hasText: 'Add gift' }).click();
    await page.locator('.fg-modal').waitFor(); await wait(page, 600);
    const before = searches.length;
    m.searchButtons = await page.locator('.fg-modal button', { hasText: /^Search$/ }).count();
    await page.locator('[data-fk="pk-q"]').pressSequentially('mu', { delay: 60 }); await wait(page, 420);
    await page.locator('[data-fk="pk-q"]').pressSequentially('g', { delay: 60 }); await wait(page, 120);
    m.searchingState = await page.locator('[data-fk="pk-st"]').textContent();
    await page.locator('.fg-modal').screenshot({ path: `${OUT}/6a-searching-${w}.png` });
    await wait(page, 1900);
    m.searchesForMug = searches.slice(before);
    m.abortedSearches = aborted.slice();
    m.resultsForMug = await page.$$eval('.fg-res b', (b) => b.map((e) => e.textContent));
    m.statusAfter = await page.locator('[data-fk="pk-st"]').textContent();
    await page.locator('[data-fk="pk-q"]').press('Enter'); await wait(page, 500);
    m.requestsAfterEnter = searches.length - before - m.searchesForMug.length;
    await page.locator('[data-fk="pk-q"]').fill(''); await page.locator('[data-fk="pk-q"]').pressSequentially('mask', { delay: 70 }); await wait(page, 900);
    await shot('6b-instant-search-results', { fullPage: false }); await sw('picker');
    await page.keyboard.press('Escape'); await wait(page, 300);

    // 7. Save that range alone: the toast names it, the dot goes.
    await page.locator('.fg-rg.open button', { hasText: 'Save range' }).click();
    await page.locator('#toast.show').waitFor(); await wait(page, 250);
    m.saveToast = await page.locator('#toast').textContent();
    m.unsavedDotAfterSave = await page.locator(`[data-fk="${editedTab}"] .fg-rt-dirty`).count();
    await shot('7-range-saved-toast', { fullPage: false });

    // 8. The selected tab is remembered across a reload.
    await page.reload(); await page.evaluate(() => window.go('freegifts'));
    await page.locator('[data-screen="freegifts"] .fg-status').waitFor(); await wait(page, 300);
    await page.locator('.fg-tab', { hasText: 'Ranges & gifts' }).click(); await wait(page, 300);
    m.rememberedAfterReload = await page.locator('.fg-rtab[aria-selected="true"]').getAttribute('data-fk') === editedTab;
    // Put the wording back for the next width.
    await page.locator('.fg-rg.open [data-fk^="label_en-"]').fill(origLabel);
    await page.locator('.fg-rg.open button', { hasText: 'Save range' }).click(); await wait(page, 900);

    // 9. Display has its own Save.
    await page.locator('.fg-tab', { hasText: 'Display' }).click(); await wait(page, 250);
    await page.locator('[data-fk="t-titleen"]').fill('Your free gifts!'); await wait(page, 150);
    m.displaySaveEnabled = await page.locator('[data-fk="save-display"]').isEnabled();
    await page.locator('.fg-save button', { hasText: 'Discard' }).click(); await wait(page, 300);
    await sw('display');

    m.errors = errors;
    report[w] = m;
    console.log(w, JSON.stringify(m, null, 1));
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(report, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
