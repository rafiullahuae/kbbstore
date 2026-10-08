/*
 * Lane CB admin screenshots, driven the way the owner drives it -- sign in,
 * open the screen, press the tab:
 *   Appearance -> Site layout -> Category banner
 *   Catalog -> Categories -> Edit -> Category header -> Banner layout
 *   Appearance -> Header -> Breadcrumbs
 *     NODE_PATH=/opt/node22/lib/node_modules node tools/cb-admin-shots.cjs http://127.0.0.1:10734 docs/lane-cb-shots <categoryId>
 */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.argv[2] || 'http://127.0.0.1:10734';
const OUT = path.resolve(process.argv[3] || 'docs/lane-cb-shots');
const CAT = process.argv[4] || '1';

(async () => {
  const browser = await chromium.launch();
  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e.message).slice(0, 160)));
    page.on('console', (c) => { if (c.type() === 'error') errs.push(c.text().slice(0, 160)); });
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);

    // 1. Appearance -> Site layout -> Category banner
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('sitelayout'));
    await page.waitForTimeout(1500);
    await page.click('[data-sls-tab="catbanner"]');
    await page.waitForTimeout(900);
    const sl = await page.evaluate(() => ({
      crumb: (document.querySelector('#crumb') || {}).textContent,
      tab: (document.querySelector('[data-sls-tab].on, [data-sls-tab][aria-selected="true"]') || {}).textContent,
      // Lane CB2: the strip as he sees it -- the old header's tabs are gone while the brand design is on.
      strip: [...document.querySelectorAll('[data-sls-tab]')].map((t) => t.textContent.trim()),
      oldNote: !!document.querySelector('[data-sls-oldnote]'),
      labels: [...document.querySelectorAll('.sls-fields label, .sls-fields .sls-lab')].map((l) => l.textContent.trim()).slice(0, 40),
      sw: document.documentElement.scrollWidth,
    }));
    console.log(JSON.stringify({ width, step: 'site layout', ...sl }));
    await page.screenshot({ path: `${OUT}/admin-category-banner-tab-${width}.png`, fullPage: true });

    // 2. Catalog -> Categories -> Edit (the storefront's own hand-off link)
    await page.goto(BASE + '/admin?kbb-open=category:' + CAT + '#catalog/categories', { waitUntil: 'networkidle' });
    await page.waitForTimeout(2200);
    const open = await page.$('#ct-cbl');
    if (open) {
      await page.evaluate(() => { const d = document.getElementById('ct-cbl'); d.open = true; d.scrollIntoView({ block: 'start' }); });
      await page.waitForTimeout(700);
      // The dialog scrolls inside itself; let it run full length for the picture only.
      await page.addStyleTag({ content: '.ct-modal{position:absolute!important;inset:0 0 auto 0!important;overflow:visible!important;min-height:100%}.ct-modal-box{max-height:none!important;overflow:visible!important}' });
      await page.waitForTimeout(300);
      await open.screenshot({ path: `${OUT}/admin-category-edit-banner-layout-${width}.png` });
      // And where it sits: the Category header panel's top, above it.
      await page.evaluate(() => document.getElementById('ct-hdr').scrollIntoView({ block: 'start' }));
      await page.waitForTimeout(400);
      await page.screenshot({ path: `${OUT}/admin-category-edit-header-top-${width}.png` });
      const ce = await page.evaluate(() => ({
        fields: [...document.querySelectorAll('#ct-cbl label')].map((l) => l.textContent.trim()),
        first: (document.querySelector('#ct-cbl select') || {}).innerText,
        placeholder: (document.querySelector('#ct-cbl input[type=number]') || {}).placeholder,
        sw: document.documentElement.scrollWidth,
      }));
      console.log(JSON.stringify({ width, step: 'category edit', count: ce.fields.length, first: ce.fields.slice(0, 4), shopHint: ce.placeholder, sw: ce.sw }));
    } else {
      console.log(JSON.stringify({ width, step: 'category edit', error: 'no #ct-cbl' }));
      await page.screenshot({ path: `${OUT}/admin-category-edit-MISSING-${width}.png`, fullPage: true });
    }

    // 3. Appearance -> Header -> Breadcrumbs
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('header'));
    await page.waitForTimeout(1500);
    await page.click('[data-hdtab="crumbs"]');
    await page.waitForTimeout(700);
    const crumbs = await page.evaluateHandle(() => {
      const all = [...document.querySelectorAll('[data-hd="bc_mobile"]')];
      const el = all[0];
      if (!el) return null;
      let box = el; for (let i = 0; i < 8 && box.parentElement; i++) { box = box.parentElement; if (box.querySelectorAll('[data-hd^="bc_"]').length >= 10 && box.querySelector('.mmhd')) break; }
      box.scrollIntoView({ block: 'start' });
      return box;
    });
    await page.waitForTimeout(500);
    const el = crumbs.asElement();
    if (el) await el.screenshot({ path: `${OUT}/admin-breadcrumbs-${width}.png` });
    else await page.screenshot({ path: `${OUT}/admin-breadcrumbs-${width}.png`, fullPage: true });
    console.log(JSON.stringify({ width, step: 'breadcrumbs', controls: await page.evaluate(() => [...document.querySelectorAll('[data-hd^="bc_"]')].map((e) => e.getAttribute('data-hd'))), errors: errs }));
    await ctx.close();
  }
  await browser.close();
})();
