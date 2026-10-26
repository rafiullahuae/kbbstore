/* Lane SSB: the on-page "Edit banner" and the console's tab, photographed and
   measured. sh tools/ssb-preview.sh 11460, then
     BASE=http://127.0.0.1:<port> node tools/ssb-editor-shots.cjs
   Writes storage/ssb-shots/ed-*.png and ed-numbers.json. Puts the banner back
   to what it was before it ran. */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const BASE = process.env.BASE, OUT = path.resolve(__dirname, '../storage/ssb-shots');
fs.mkdirSync(OUT, { recursive: true });
const shot = (page, name, clip) => page.screenshot({ path: `${OUT}/ed-${name}.png`, ...(clip ? { clip } : {}) });

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};

  // 1. A shopper: no pill, no editor, no admin request.
  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const admin = [];
    page.on('request', (r) => { if (/admin-api|super-sale-banner-editor|storefront-admin/.test(r.url())) admin.push(r.url()); });
    await page.goto(BASE + '/super-sale/', { waitUntil: 'networkidle' });
    report[`guest-${w}`] = { pill: await page.locator('text=Edit banner').count(), adminRequests: admin };
    await shot(page, `guest-${w}`, { x: 0, y: 0, width: w, height: w === 390 ? 640 : 560 });
    await ctx.close();
  }

  // 2. The owner, at 1280 and at 390.
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1 });
    const page = await ctx.newPage();
    const errors = [];
    const requests = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('console', (m) => { if (m.type() === 'error' && !/favicon/.test(m.location().url || '')) errors.push(m.text()); });
    page.on('request', (r) => requests.push(`${r.method()} ${r.url().replace(BASE, '')}`));
    await page.goto(BASE + '/admin/login');
    await page.fill('#email', 'owner@example.com');
    await page.fill('#password', 'preview-password');
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.goto(BASE + '/super-sale/', { waitUntil: 'networkidle' });
    const pill = page.locator('.kbb-qe-pill', { hasText: 'Edit banner' });
    await pill.waitFor({ timeout: 10000 });
    const r = { pillBox: await pill.boundingBox() };
    await shot(page, `admin-pill-${w}`, { x: 0, y: 0, width: w, height: w === 390 ? 640 : 560 });

    await pill.click();
    await page.locator('.ssb-ed').waitFor();
    await page.waitForTimeout(300);
    await shot(page, `open-${w}`);

    const before = requests.length;
    // Edit: the SUPER SALE heading's size and colour on this device, and a word.
    const title = page.locator('.ssb-ed-el', { hasText: 'SUPER SALE heading' });
    await title.locator('input[type=number]').first().fill(w === 390 ? '36' : '64');
    await title.locator('.ssb-ed-hex').fill('#9B1C5A');
    await page.locator('.ssb-ed-sec summary', { hasText: 'Words' }).click();
    await page.locator('.ssb-ed-sec', { hasText: 'Words' }).locator('input').nth(2).fill('MEGA SALE');
    await page.waitForTimeout(200);
    r.requestsWhileEditing = requests.slice(before);
    r.liveTitle = await page.evaluate(() => { const t = document.querySelector('.kbb-ssb .ssb-super'); return { text: t.textContent, color: getComputedStyle(t).color, size: getComputedStyle(t).fontSize }; });
    await shot(page, `edited-${w}`);

    // The device toggle: the other layout, whatever the window's width.
    await page.locator('.ssb-ed-seg button', { hasText: w === 390 ? 'Computer' : 'Phone' }).click();
    await page.waitForTimeout(200);
    r.otherDevice = await page.evaluate(() => { const b = document.querySelector('.kbb-ssb'); return { cls: b.className, w: Math.round(b.getBoundingClientRect().width), h: Math.round(b.getBoundingClientRect().height) }; });
    await shot(page, `other-device-${w}`);
    // The language toggle: Arabic, inheriting English's colour until changed.
    await page.locator('.ssb-ed-seg button', { hasText: 'العربية' }).click();
    await page.waitForTimeout(200);
    r.arabic = await page.evaluate(() => { const b = document.querySelector('.kbb-ssb'); const t = b.querySelector('.ssb-super'); return { dir: b.dir, title: t.textContent, chip: document.querySelector('.ssb-ed-el .ssb-ed-chip')?.textContent }; });
    await shot(page, `arabic-${w}`);
    r.requestsWhileToggling = requests.slice(before).filter((x) => !/\.(woff2?|webp|png|jpe?g)/.test(x));

    if (w === 1280) {
      // Save, reload, and see it on the shop.
      await page.locator('.ssb-ed-seg button', { hasText: 'English' }).click();
      await Promise.all([page.waitForResponse((x) => x.url().includes('/admin-api/page-banners') && x.request().method() === 'POST'), page.click('.ssb-ed-btn.is-primary')]);
      await page.waitForTimeout(200);
      r.saveMessage = await page.locator('.ssb-ed-msg').textContent();
      await shot(page, `saved-${w}`);
      await page.reload({ waitUntil: 'networkidle' });
      r.afterReload = await page.evaluate(() => { const t = document.querySelector('.kbb-ssb .ssb-super'); return { text: t.textContent, color: getComputedStyle(t).color, size: getComputedStyle(t).fontSize, style: document.querySelector('.kbb-ssb').getAttribute('style') }; });
      await shot(page, `after-reload-${w}`, { x: 0, y: 0, width: w, height: 560 });

      // Close with unsaved changes: kept as a draft, no popup.
      await page.locator('.kbb-qe-pill', { hasText: 'Edit banner' }).click();
      await page.locator('.ssb-ed').waitFor();
      await page.locator('.ssb-ed-el', { hasText: 'Tagline' }).locator('.ssb-ed-hex').fill('#0055AA');
      let dialogs = 0;
      page.on('dialog', (d) => { dialogs += 1; d.dismiss(); });
      await page.click('.ssb-ed-x');
      await page.locator('.kbb-qe-pill', { hasText: 'Edit banner' }).click();
      r.draft = { dialogs, message: await page.locator('.ssb-ed-msg').textContent() };
      await page.click('text=Discard changes');
      // Put the shipped banner back through the same endpoint.
      const restored = await page.evaluate(async () => {
        const res = await fetch('/admin-api/storefront/context?path=/super-sale/', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const ctx = await res.json();
        const c = ctx.salebanner.config;
        c.title_en = 'SUPER SALE'; c.st = { en: {}, ar: {} };
        const put = await fetch('/admin-api/page-banners', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': ctx.csrf }, body: JSON.stringify({ super_sale_banner: c }) });
        return put.status;
      });
      r.restored = restored;
      await page.click('.ssb-ed-x');

      // The console's tab: the same controls, the same preview.
      await page.goto(BASE + '/admin');
      await page.evaluate(() => window.go('pagebanners'));
      await page.locator('[data-pbs-tab="ssb"]').click();
      await page.locator('[data-pbs-ssbhost] .ssb-ed-body').waitFor({ timeout: 10000 });
      await shot(page, 'console-1280');
      await page.locator('[data-pbs-ssbhost] .ssb-ed-seg button', { hasText: 'العربية' }).click();
      await page.locator('[data-pbs-ssbhost] .ssb-ed-seg button', { hasText: 'Phone' }).click();
      await page.waitForTimeout(200);
      await shot(page, 'console-ar-phone-1280');
      r.console = await page.evaluate(() => ({ stage: document.querySelector('[data-pbs-ssbhost] .kbb-ssb')?.className, dir: document.querySelector('[data-pbs-ssbhost] .kbb-ssb')?.dir }));
    }
    r.errors = errors;
    r.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    report[`admin-${w}`] = r;
    await ctx.close();
  }

  fs.writeFileSync(`${OUT}/ed-numbers.json`, JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
