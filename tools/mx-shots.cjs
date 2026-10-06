/*
 * Lane MX screenshots: Store -> Mega Menu -> Add items and the bottom
 * scrollbar, in Chromium at 1280 and 390, then the storefront header drawing
 * what was added. Run against tools/mx-preview.sh:
 *
 *     node tools/mx-shots.cjs http://127.0.0.1:10620 docs/mx-shots
 *
 * Every number printed is read from the page after it has rendered; the
 * shop's own code measures nothing. Chromium is launched WITHOUT Playwright's
 * default --hide-scrollbars, or the bar this lane built would not be drawn.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:10620';
const OUT = path.resolve(process.argv[3] || 'docs/mx-shots');
const lines = [];
const note = (s) => { console.log(s); lines.push(s); };

function ctxOpts(w) {
  return w < 600
    ? { viewport: { width: w, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
    : { viewport: { width: w, height: 900 } };
}

async function login(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
}

const nums = (page) => page.evaluate(() => {
  const b = document.querySelector('[data-mo-board]'), s = document.querySelector('[data-mo-strack]');
  return {
    boardScrollWidth: b.scrollWidth, boardClientWidth: b.clientWidth, boardRange: b.scrollWidth - b.clientWidth,
    trackRange: s.scrollWidth - s.clientWidth, boardLeft: Math.round(b.scrollLeft), trackLeft: Math.round(s.scrollLeft),
    docScrollWidth: document.documentElement.scrollWidth, innerWidth,
    barBottomGap: Math.round(innerHeight - document.querySelector('[data-mo-sbar]').getBoundingClientRect().bottom),
  };
});

async function openEditor(page, reqs) {
  await page.goto(`${BASE}/admin/?go=megamenu`, { waitUntil: 'networkidle' });
  await page.waitForSelector('[data-mo-board]');
  // The live desktop menu (the seed's kbeautybliss.com menu with "K-Beauty" first).
  const live = await page.$('[data-mgmswitch] .mgm-loctag');
  if (live) {
    const id = await live.evaluate((n) => n.closest('[data-mgmswitch]').dataset.mgmswitch);
    if (!(await page.$(`.mgm-menutab.on[data-mgmswitch="${id}"]`))) {
      await page.click(`[data-mgmswitch="${id}"]`);
      await page.waitForFunction((i) => document.querySelector(`.mgm-menutab.on[data-mgmswitch="${i}"]`), id);
      await page.waitForSelector('[data-mo-board]');
    }
  }
  reqs.length = 0;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({
    executablePath: process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    ignoreDefaultArgs: ['--hide-scrollbars'],
  });

  /* ================= 1280 ================= */
  {
    const ctx = await browser.newContext(ctxOpts(1280));
    const page = await ctx.newPage();
    const reqs = [];
    page.on('request', (r) => { if (r.url().includes('/admin-api/mega-menu')) reqs.push(r.method() + ' ' + new URL(r.url()).pathname); });
    page.on('pageerror', (e) => note('PAGE ERROR ' + e.message));
    await login(page);
    await page.evaluate(() => { try { localStorage.removeItem('kbb.mx.open'); } catch (e) {} });
    await openEditor(page, reqs);

    // 1. The panel open: one request, whatever the tab.
    await page.click('[data-mx-open]');
    await page.waitForSelector('[data-mx-row]');
    for (const t of ['brands', 'pages', 'posts', 'collections', 'categories']) await page.click(`[data-mx-tab="${t}"]`);
    note(`open + five tab switches: requests=${JSON.stringify(reqs)} (${reqs.length})`);
    const n1 = await nums(page);
    note(`1280 board: ${JSON.stringify(n1)}`);
    await page.screenshot({ path: path.join(OUT, 'mx-1280-01-panel-open.png') });

    // 2. Search filters in the browser: no request per keystroke.
    reqs.length = 0;
    await page.type('[data-mx-find]', 'ser', { delay: 40 });
    const shown = await page.$$eval('[data-mx-row]', (rs) => rs.filter((r) => !r.hidden).map((r) => r.textContent.replace('✓', '').trim()));
    note(`search "ser": shown=${JSON.stringify(shown)} requests while typing=${reqs.length}`);
    await page.screenshot({ path: path.join(OUT, 'mx-1280-02-search.png') });
    await page.fill('[data-mx-find]', '');
    await page.dispatchEvent('[data-mx-find]', 'input');

    // 3. Tick three, choose K-Beauty › Shop by category, add.
    for (const name of ['Cleansers', 'Toners', 'Face Serums']) {
      await page.locator('[data-mx-row]', { hasText: new RegExp('^' + name) }).locator('input').check();
    }
    const kb = await page.$eval('[data-mx-menu]', (s) => [...s.options].find((o) => o.text === 'K-Beauty').value);
    await page.selectOption('[data-mx-menu]', kb);
    const col = await page.$eval('[data-mx-col]', (s) => [...s.options].find((o) => o.text === 'Shop by category').value);
    await page.selectOption('[data-mx-col]', col);
    await page.evaluate(() => document.querySelector('.mgm-card').scrollIntoView({ block: 'start' }));
    await page.screenshot({ path: path.join(OUT, 'mx-1280-03-ticked.png') });
    reqs.length = 0;
    await page.click('[data-mx-add]');
    await page.waitForFunction(() => document.querySelectorAll('.mx-row.mx-on').length >= 4);
    note(`add 3 ticked: requests=${JSON.stringify(reqs)}`);
    await page.$eval('[data-mo-board]', (b) => { b.scrollLeft = 0; });
    await page.evaluate(() => document.querySelector('.mgm-card').scrollIntoView({ block: 'start' }));
    await page.screenshot({ path: path.join(OUT, 'mx-1280-04-added-to-column.png') });

    // 4. Drag a brand from the list straight onto the "Top brands" column.
    await page.click('[data-mx-tab="brands"]');
    await page.fill('[data-mx-find]', 'anua');
    await page.dispatchEvent('[data-mx-find]', 'input');
    reqs.length = 0;
    const target = page.locator('.mo-grp', { has: page.locator('.mo-ghead', { hasText: 'Top brands' }) });
    await page.locator('[data-mx-row]:not([hidden])').first().dragTo(target.first());
    await page.waitForFunction(() => [...document.querySelectorAll('.mo-link .mo-name')].some((n) => n.textContent === 'Anua' && n.closest('.mo-grp').querySelector('.mo-ghead').textContent.includes('Top brands')));
    note(`drag Anua onto Top brands: requests=${JSON.stringify(reqs)}`);
    await page.$eval('[data-mo-board]', (b) => { b.scrollLeft = 0; });
    await page.evaluate(() => document.querySelector('.mgm-card').scrollIntoView({ block: 'start' }));
    await page.screenshot({ path: path.join(OUT, 'mx-1280-05-dragged-brand.png') });

    // 5. Custom link: javascript: refused before a request; a path accepted.
    await page.click('[data-mx-tab="custom"]');
    await page.fill('[data-mx-clabel]', 'Gift cards');
    await page.fill('[data-mx-curl]', 'javascript:alert(1)');
    reqs.length = 0;
    await page.click('[data-mx-add]');
    note(`custom javascript: -> requests=${reqs.length} (refused in the panel; the server refuses it too, see MegaMenuPickerTest)`);
    await page.screenshot({ path: path.join(OUT, 'mx-1280-06-custom-refused.png') });

    // 6. The bottom scrollbar: thumb mid-drag, both ranges equal, keyboard and ‹ ›.
    await page.click('[data-mx-close]');
    await page.$eval('[data-mo-board]', (b) => { b.scrollLeft = 0; });
    await page.evaluate(() => document.querySelector('.content').scrollTo(0, 260));
    const tr = await page.$eval('[data-mo-strack]', (s) => { const r = s.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height }; });
    await page.mouse.move(tr.x + 30, tr.y + tr.h / 2);
    await page.mouse.down();
    await page.mouse.move(tr.x + 230, tr.y + tr.h / 2, { steps: 8 });
    const mid = await nums(page);
    note(`thumb mid-drag (+200px): ${JSON.stringify(mid)}`);
    await page.screenshot({ path: path.join(OUT, 'mx-1280-07-scrollbar-mid-drag.png') });
    await page.mouse.up();
    await page.focus('[data-mo-strack]');
    const k0 = (await nums(page)).boardLeft;
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(300);
    const k1 = (await nums(page)).boardLeft;
    await page.click('[data-mo-sstep="1"]');
    await page.waitForTimeout(600);
    const k2 = (await nums(page)).boardLeft;
    note(`keyboard: ArrowRight x2 on the track moved the board ${k0} -> ${k1}; › moved it ${k1} -> ${k2} (one column = 164)`);

    // 7. The Mac back-swipe: a horizontal over-scroll at the left end, on the board and on the page.
    await page.$eval('[data-mo-board]', (b) => { b.scrollLeft = 0; });
    const h0 = await page.evaluate(() => ({ len: history.length, href: location.href }));
    const bb = await page.$eval('[data-mo-board]', (b) => { const r = b.getBoundingClientRect(); return { x: r.x + 100, y: r.y + 60 }; });
    await page.mouse.move(bb.x, bb.y);
    for (let i = 0; i < 6; i++) await page.mouse.wheel(-400, 0);
    await page.mouse.move(400, 200);
    for (let i = 0; i < 6; i++) await page.mouse.wheel(-400, 0);
    await page.waitForTimeout(500);
    const h1 = await page.evaluate(() => ({ len: history.length, href: location.href }));
    const ob = await page.evaluate(() => ['html', 'body', '.content', '[data-mo-board]', '[data-mo-strack]'].map((s) => s + '=' + getComputedStyle(document.querySelector(s)).overscrollBehaviorX).join(' '));
    note(`horizontal over-scroll at the left end: history.length ${h0.len} -> ${h1.len}, url unchanged=${h0.href === h1.href}; overscroll-behavior-x: ${ob}`);
    await page.screenshot({ path: path.join(OUT, 'mx-1280-08-after-overscroll.png') });
    await ctx.close();

    // 8. The storefront: the K-Beauty mega panel with the added items.
    const sctx = await browser.newContext(ctxOpts(1280));
    const shop = await sctx.newPage();
    await shop.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    const kbNav = shop.locator('.mbar .navitem', { has: shop.locator('a.navlink', { hasText: 'K-Beauty' }) });
    await kbNav.hover();
    await shop.waitForTimeout(400);
    const links = await kbNav.locator('.drop a').evaluateAll((as) => as.map((a) => a.textContent.trim() + ' ' + a.getAttribute('href')));
    note(`storefront K-Beauty panel: ${JSON.stringify(links)}`);
    await shop.screenshot({ path: path.join(OUT, 'mx-1280-09-storefront-mega.png'), clip: { x: 0, y: 0, width: 1280, height: 560 } });
    await sctx.close();
  }

  /* ================= 390 ================= */
  {
    const ctx = await browser.newContext(ctxOpts(390));
    const page = await ctx.newPage();
    const reqs = [];
    page.on('request', (r) => { if (r.url().includes('/admin-api/mega-menu')) reqs.push(r.method() + ' ' + new URL(r.url()).pathname); });
    page.on('pageerror', (e) => note('PAGE ERROR 390 ' + e.message));
    await login(page);
    await page.evaluate(() => { try { localStorage.removeItem('kbb.mx.open'); } catch (e) {} });
    await openEditor(page, reqs);
    await page.click('[data-mx-open]');
    await page.waitForSelector('[data-mx-row]');
    await page.click('[data-mx-tab="pages"]');
    await page.fill('[data-mx-find]', 'about');
    await page.dispatchEvent('[data-mx-find]', 'input');
    await page.locator('[data-mx-row]:not([hidden]) input').first().check();
    const kb = await page.$eval('[data-mx-menu]', (s) => [...s.options].find((o) => o.text === 'K-Beauty').value);
    await page.selectOption('[data-mx-menu]', kb);
    const col = await page.$eval('[data-mx-col]', (s) => [...s.options].find((o) => o.text === 'Top brands').value);
    await page.selectOption('[data-mx-col]', col);
    await page.locator('[data-mx-panel]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(OUT, 'mx-390-01-panel-ticked.png') });
    await page.click('[data-mx-add]');
    await page.waitForFunction(() => [...document.querySelectorAll('.mo-link .mo-name')].some((n) => n.textContent === 'About us'));
    note(`390 requests: ${JSON.stringify(reqs)}`);
    await page.locator('.mo-col').first().scrollIntoViewIfNeeded();
    const n = await nums(page);
    note(`390 board: ${JSON.stringify(n)}`);
    await page.screenshot({ path: path.join(OUT, 'mx-390-02-added-bar.png') });
    await ctx.close();

    const sctx = await browser.newContext(ctxOpts(390));
    const shop = await sctx.newPage();
    await shop.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await shop.click('#burger');
    await shop.waitForTimeout(500);
    const kbRow = shop.locator('#mmenu', { hasText: 'K-Beauty' });
    const toggle = shop.locator('#mmenu button, #mmenu summary').filter({ hasText: 'K-Beauty' }).first();
    if (await toggle.count()) { await toggle.click(); await shop.waitForTimeout(400); }
    const sw = await shop.evaluate(() => document.documentElement.scrollWidth);
    note(`390 storefront menu: K-Beauty present=${await kbRow.count() > 0} scrollWidth=${sw}`);
    await shop.screenshot({ path: path.join(OUT, 'mx-390-03-storefront-menu.png') });
    await sctx.close();
  }

  await browser.close();
  fs.writeFileSync(path.join(OUT, 'mx-numbers.txt'), lines.join('\n') + '\n');
})().catch((e) => { console.error(e); process.exit(1); });
