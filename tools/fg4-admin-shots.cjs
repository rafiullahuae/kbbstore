/* Lane FG4: Growth & Marketing -> Free gifts -> Ranges & gifts, gift types
   and criteria, at 1280 and 390 -- the three boxes with one short (notice
   visible), the criteria steppers, the picker opened from "Add mini
   products", a range that matches (green), Overview "Things to check" -- plus
   the numbers: page width, console errors on every tab, the picker's
   requests.
   FG_SEED=fg4-seed.php FG_ON=0 sh tools/fg-preview.sh 10860
   BASE=http://127.0.0.1:10860 node tools/fg4-admin-shots.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../docs/lane-fg4');
fs.mkdirSync(OUT, { recursive: true });
const wait = (p, ms) => p.waitForTimeout(ms);

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};
  for (const w of [1280, 390]) {
    const H = w === 390 ? 844 : 900;
    const ctx = await browser.newContext({ viewport: { width: w, height: H }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [], searches = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text() + ' @ ' + ((m.location() && m.location().url) || '')); });
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    page.on('request', (r) => { if (r.url().includes('/admin-api/free-gifts/products?')) searches.push(r.url().split('?')[1]); });
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    // Each width starts from the seeded state: AED 500 - 999 one mini short
    // (the 1280 run adds the Clay Mask Mini and saves).
    await page.evaluate(async () => {
      try { localStorage.removeItem('kbb.freegifts.range'); } catch (e) {}
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x };
      const base = location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api/free-gifts';
      const d = await (await fetch(base, { headers: h, credentials: 'same-origin' })).json();
      const r = d.ranges.filter((v) => v.min === 500)[0];
      const gifts = r.gifts.filter((g) => !(g.product && /Mugwort/.test(g.product.name))).map((g) => ({ product_id: g.product_id, quantity: g.quantity, kind: g.kind }));
      await fetch(base + '/ranges/' + r.id, { method: 'PUT', headers: h, credentials: 'same-origin', body: JSON.stringify({ min: r.min, max: r.max, label_en: r.label_en, label_ar: r.label_ar, enabled: r.enabled, starts_on: r.starts_on, ends_on: r.ends_on, gifts: gifts, needs: r.needs }) });
    });
    await page.evaluate(() => window.go('freegifts'));
    await page.locator('[data-screen="freegifts"] .fg-status').waitFor();
    await page.locator('.fg-kpis .kpi .val').first().filter({ hasNotText: '…' }).waitFor();
    await wait(page, 300);
    const m = { scrollWidth: {} };
    const sw = async (k) => { m.scrollWidth[k] = await page.evaluate(() => document.documentElement.scrollWidth); };
    const full = async (name) => {
      const need = await page.evaluate(() => { const c = document.querySelector('#content'); return c.scrollHeight + c.getBoundingClientRect().top + 4; });
      await page.setViewportSize({ width: w, height: Math.max(H, Math.ceil(need)) }); await wait(page, 150);
      await page.screenshot({ path: `${OUT}/${name}-${w}.png` });
      await page.setViewportSize({ width: w, height: H }); await wait(page, 100);
    };
    const tab = async (label) => { await page.locator('.fg-rtab', { hasText: label }).first().click(); await wait(page, 250); };

    // 5. Overview "Things to check": the criteria items, each with "Open range".
    m.checks = await page.$$eval('.fg-checks li', (l) => l.map((e) => e.textContent));
    await page.locator('.fg-checks').scrollIntoViewIfNeeded();
    await page.locator('.fg-checks').evaluate((e) => e.closest('section').scrollIntoView({ block: 'start' }));
    await wait(page, 150);
    await page.locator('.fg-checks').evaluate(() => {});
    await page.screenshot({ path: `${OUT}/5-overview-things-to-check-${w}.png` });
    await sw('overview');

    // "Open range" on a criteria item opens that range.
    await page.locator('.fg-checks li', { hasText: 'Mini products: 1 of 2' }).locator('.fg-link').click();
    await wait(page, 300);
    m.openedFromOverview = await page.locator('.fg-rtab[aria-selected="true"]').textContent();

    // 1. AED 500 - 999: three boxes, Minis one short, the notice and the tab's mark.
    m.tabWarn = await page.locator('.fg-rtab[aria-selected="true"] .fg-rt-warn').getAttribute('aria-label');
    m.notice = await page.locator('.fg-cnote').innerText();
    m.counters = await page.$$eval('.fg-box', (b) => b.map((e) => e.getAttribute('aria-label') + ': ' + e.querySelector('.fg-cnt').textContent + ' [' + e.querySelector('.fg-cnt').className + ']'));
    m.total = await page.locator('.fg-tot').innerText();
    await full('1-range-boxes-mini-short'); await sw('ranges');

    // 2. The criteria steppers, close up; then one + on Minis and back.
    await page.locator('.fg-crit').scrollIntoViewIfNeeded();
    await page.locator('.fg-crit').screenshot({ path: `${OUT}/2-criteria-steppers-${w}.png` });
    m.critSum = await page.locator('.fg-crit-sum').textContent();
    m.useWordsDisabled = await page.locator('[data-fk^="usewords-"]').isDisabled();

    // 3. The picker from "Add mini products": pre-set to Minis, a search, the type pills.
    await page.locator('[data-fk^="add-mini-"]').click();
    await page.locator('.fg-modal').waitFor();
    await page.locator('.fg-res').first().waitFor();
    await page.fill('[data-fk="pk-q"]', 'mini');
    await page.waitForFunction(() => document.querySelector('.fg-pk-st') && /match/.test(document.querySelector('.fg-pk-st').textContent));
    await wait(page, 200);
    m.pickerTitle = await page.locator('#fg-pk-t').textContent();
    m.pickerInto = await page.locator('[data-fk^="pk-into-"][aria-checked="true"]').textContent();
    m.pickerPills = await page.$$eval('.fg-res .fg-kp', (p) => p.map((e) => e.textContent));
    await page.screenshot({ path: `${OUT}/3-picker-from-mini-${w}.png` });
    await sw('picker');
    // Pick the Clay Mask Mini (remembered as mini by the migration): it lands in Minis.
    await page.locator('.fg-res', { hasText: 'Mugwort Calming Clay Mask Mini' }).click();
    await wait(page, 150);
    m.pickerStatus = await page.locator('.fg-pk-st').textContent();
    m.pickerFoot = await page.locator('.fg-pk-foot .fg-sub').textContent();
    await page.locator('.fg-pk-foot .btn').click();
    await wait(page, 300);
    m.afterPickCounters = await page.$$eval('.fg-box', (b) => b.map((e) => e.getAttribute('aria-label') + ': ' + e.querySelector('.fg-cnt').textContent));
    m.afterPickNoticeHidden = await page.locator('.fg-cnote').isHidden();
    m.afterPickTotal = await page.locator('.fg-tot').innerText();
    // 4. Everything matches: green -- and it saves.
    await full('4-range-matches-green');
    await page.locator('[data-fk^="save-"]').click();
    await page.locator('#toast.show').waitFor(); await wait(page, 300);
    m.saveToast = await page.locator('#toast').textContent();
    m.tabWarnAfterSave = await page.locator('.fg-rtab[aria-selected="true"] .fg-rt-warn').count();

    // The untagged box and the "ask" chooser, on AED 2,000+.
    await tab('2,000+');
    m.boxes2000 = await page.$$eval('.fg-box', (b) => b.map((e) => e.getAttribute('aria-label') + ': ' + e.querySelector('.fg-cnt').textContent));
    await full('6-range-untagged-box');
    await page.locator('[data-fk^="add-"]:not([data-fk*="-mask-"]):not([data-fk*="-mini-"]):not([data-fk*="-full-"])').first().click();
    await page.locator('.fg-modal').waitFor();
    await page.fill('[data-fk="pk-q"]', 'ritual');
    await page.waitForFunction(() => document.querySelector('.fg-pk-st') && /match/.test(document.querySelector('.fg-pk-st').textContent));
    await page.locator('.fg-res').first().click();
    await wait(page, 150);
    m.askShown = await page.locator('.fg-ask').count();
    await page.screenshot({ path: `${OUT}/7-picker-asks-which-box-${w}.png` });
    await page.keyboard.press('Escape');
    await wait(page, 200);

    // Every other tab: no console error, no sideways scroll.
    for (const t of ['products', 'display', 'home', 'overview']) {
      await page.locator('#fg-tab-' + t).click(); await wait(page, 300); await sw(t);
    }
    m.searches = searches;
    m.errors = errors;
    report[w] = m;
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
