// Lane MO screenshots: Appearance -> Mega Menu (Store -> Mega Menu) as the
// column board. Run against tools/mo-preview.sh:
//   node tools/mo-shots.cjs [port]
// Writes docs/mo-shots/*.png and docs/mo-shots/numbers.txt.
// getBoundingClientRect here is the HARNESS measuring the page; the board
// itself measures nothing.
const fs = require('node:fs');
const { chromium } = require('playwright');
const BASE = `http://127.0.0.1:${process.argv[2] || 10530}`;
const OUT = `${__dirname}/../docs/mo-shots`;
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
const lines = [];
const say = (...a) => { const s = a.map((x) => (typeof x === 'string' ? x : JSON.stringify(x))).join(' '); lines.push(s); console.log(s); };

async function login(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
}
async function open(page) {
  await page.evaluate(() => window.go('megamenu'));
  await page.waitForSelector('.mo-board .mo-col');
  await page.waitForTimeout(400);
}
const measure = (page) => page.evaluate(() => {
  const r = (el) => el && el.getBoundingClientRect();
  const board = document.querySelector('.mo-board'), b = r(board);
  const cols = [...document.querySelectorAll('.mo-col')];
  const cs = (sel) => { const e = document.querySelector(sel); return e ? getComputedStyle(e).fontSize : null; };
  const nav = document.querySelector('.side, .sidebar, aside');
  return {
    innerWidth: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    sidebarWidth: nav ? Math.round(r(nav).width) : null,
    boardWidth: Math.round(b.width),
    columns: cols.length,
    columnsFullyVisible: cols.filter((c) => r(c).right <= b.right + 0.5 && r(c).left >= b.left - 0.5).length,
    colWidth: Math.round(r(cols[0]).width),
    colGap: cols[1] ? Math.round(r(cols[1]).left - r(cols[0]).right) : null,
    rowHeight: Math.round(r(document.querySelector('.mo-link')).height),
    headHeight: Math.round(r(document.querySelector('.mo-head')).height),
    numBox: [Math.round(r(document.querySelector('.mo-num')).width), Math.round(r(document.querySelector('.mo-num')).height)],
    arrow: [Math.round(r(document.querySelector('.mo-ib')).width), Math.round(r(document.querySelector('.mo-ib')).height)],
    fontLink: cs('.mo-link'), fontHead: cs('.mo-head'),
    fab: (() => { const f = r(document.querySelector('.mo-fab')); return [Math.round(f.width), Math.round(f.height), Math.round(window.innerWidth - f.right), Math.round(window.innerHeight - f.bottom)]; })(),
  };
});
const order = (page, sel) => page.evaluate((s) => [...document.querySelectorAll(s)].map((e) => e.querySelector(':scope > .mo-name, :scope > [data-mo-grab] > .mo-name, .mo-name').textContent), sel);
const serverTree = (page) => page.evaluate(async () => {
  const base = location.pathname.replace(/\/+$/, '').replace(/\/[^/]*$/, '') + '/admin-api/mega-menu';
  const j = await (await fetch(base, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })).json();
  const w = (l) => l.map((n) => (n.children && n.children.length ? { [n.label]: w(n.children) } : n.label));
  return w(j.tree);
});
// A column by its exact name (the title also carries the link, after two spaces).
const colOf = (label) => `.mo-col:has(> .mo-head .mo-name[title^="${label}  "])`;
const center = async (page, sel) => { const l = page.locator(sel).first(); await l.scrollIntoViewIfNeeded(); const b = await l.boundingBox(); return [b.x + b.width / 2, b.y + b.height / 2]; };

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const errors = [];
  for (const w of [1280, 1920, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : (w === 1920 ? 1080 : 900) }, deviceScaleFactor: w === 390 ? 2 : 1, userAgent: UA });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(`${w}: ${e}`));
    page.on('response', (r) => { if (r.url().includes('/admin-api/mega-menu') && r.request().method() === 'POST') say(`  ${w} POST ${r.url().replace(BASE, '')} -> ${r.status()}`); });
    await login(page);
    await open(page);
    say(`== ${w}px board`, await measure(page));
    await page.screenshot({ path: `${OUT}/board-${w}.png` });

    if (w === 1280) {
      // 1. Typing a number: Brands (11th) to 1.
      say('top order before', await order(page, '.mo-cols > .mo-col'));
      await page.locator(`${colOf('Brands')} > .mo-head .mo-num`).fill('1');
      await page.locator(`${colOf('Brands')} > .mo-head .mo-num`).press('Enter');
      await page.waitForTimeout(500);
      say('top order after typing 1 on Brands', await order(page, '.mo-cols > .mo-col'));
      await page.screenshot({ path: `${OUT}/number-typed-1280.png` });
      // out of range clamps, a word reverts
      await page.locator(`${colOf('Blog')} > .mo-head .mo-num`).fill('99');
      await page.locator(`${colOf('Blog')} > .mo-head .mo-num`).press('Enter');
      await page.waitForTimeout(500);
      say('after typing 99 on Blog (clamps to last)', await order(page, '.mo-cols > .mo-col'));
      await page.locator(`${colOf('Skincare')} > .mo-head .mo-num`).first().fill('abc');
      await page.locator(`${colOf('Skincare')} > .mo-head .mo-num`).first().press('Enter');
      await page.waitForTimeout(300);
      say('after typing abc on Skincare: box reads', await page.locator(`${colOf('Skincare')} > .mo-head .mo-num`).first().inputValue());
      await page.locator(`${colOf('Skincare')} > .mo-head .mo-num`).fill('2');
      await page.locator(`${colOf('Skincare')} > .mo-head .mo-num`).press('Enter');
      await page.waitForTimeout(500);
      say('after typing 2 on Skincare', await order(page, '.mo-cols > .mo-col'));
      await page.evaluate(() => { document.querySelector('.mo-board').scrollLeft = 0; });

      // 2. Up / down inside Skincare -> Cleanse.
      const cleanse = '.mo-grp:has(> .mo-ghead .mo-name[title^="Cleanse"])';
      say('Cleanse before', await order(page, `${cleanse} .mo-link`));
      await page.locator(`${cleanse} .mo-link:has(.mo-name[title^="Cleansers"]) [data-mo-step="1"]`).click();
      await page.waitForTimeout(150);
      await page.screenshot({ path: `${OUT}/arrow-down-1280.png` });
      await page.waitForTimeout(400);
      say('Cleanse after ↓ on Cleansers', await order(page, `${cleanse} .mo-link`));
      say('focus stays on', await page.evaluate(() => document.activeElement && document.activeElement.getAttribute('aria-label')));

      // 3. Drag mid-way: Serums (Skincare > Treat) to Brands > Popular, by the row itself.
      const from = await center(page, '.mo-link:has(.mo-name[title^="Serums"]) .mo-name');
      const via = await center(page, '.mo-link:has(.mo-name[title^="Medicube"])');
      const to = await center(page, '.mo-link:has(.mo-name[title^="COSRX"])');
      await page.mouse.move(...from); await page.mouse.down();
      await page.mouse.move(from[0] + 10, from[1] + 4, { steps: 3 });
      await page.mouse.move(via[0], via[1] + 30, { steps: 12 });
      await page.mouse.move(via[0], via[1], { steps: 6 });
      await page.mouse.move(to[0], to[1] + 6, { steps: 10 });
      await page.mouse.move(to[0], to[1] - 4, { steps: 4 });
      await page.waitForTimeout(60);
      await page.screenshot({ path: `${OUT}/drag-midway-1280.png` });
      say('mid-drag: Popular reads', await order(page, '.mo-grp:has(> .mo-ghead .mo-name[title^="Popular"]) .mo-link'),
        'lifted', await page.locator('.mo-lift').count(), 'hot column', await page.evaluate(() => (document.querySelector('.mo-col.mo-hot .mo-head .mo-name') || {}).textContent),
        'fab opacity', await page.evaluate(() => getComputedStyle(document.querySelector('.mo-fab')).opacity));
      await page.mouse.up();
      await page.waitForTimeout(600);
      await page.screenshot({ path: `${OUT}/drag-dropped-1280.png` });
      say('after release: Popular', await order(page, '.mo-grp:has(> .mo-ghead .mo-name[title^="Popular"]) .mo-link'),
        'Treat', await order(page, '.mo-grp:has(> .mo-ghead .mo-name[title^="Treat"]) .mo-link'));

      // 4. Refused visibly: Popular (has links) dragged over a link in Skincare.
      const p0 = await center(page, '.mo-ghead:has(.mo-name[title^="Popular"]) .mo-name');
      const p1 = await center(page, '.mo-link:has(.mo-name[title^="Exfoliators"])');
      await page.mouse.move(...p0); await page.mouse.down();
      await page.mouse.move(p0[0] + 12, p0[1] + 6, { steps: 3 });
      await page.mouse.move(p1[0], p1[1], { steps: 20 });
      await page.waitForTimeout(60);
      await page.screenshot({ path: `${OUT}/drag-refused-1280.png` });
      say('refused zone marked', await page.locator('.mo-no').count());
      await page.keyboard.press('Escape');
      await page.mouse.up();
      await page.waitForTimeout(300);
      say('after Esc: Brands sub-menus', await order(page, `${colOf('Brands')} .mo-grp`));

      // 5. Column drag by its header: Hair Care onto Everything Under 54 AED's place.
      await page.evaluate(() => { document.querySelector('.mo-board').scrollLeft = 0; });
      const h0 = await center(page, `${colOf('Super Sale')} > .mo-head .mo-name`);
      const h1 = await center(page, `${colOf('Everything Under 54 AED')} > .mo-head .mo-name`);
      await page.mouse.move(...h0); await page.mouse.down();
      await page.mouse.move(h0[0] - 10, h0[1], { steps: 3 });
      await page.mouse.move(h1[0], h1[1], { steps: 25 });
      await page.screenshot({ path: `${OUT}/drag-column-midway-1280.png` });
      await page.mouse.up();
      await page.waitForTimeout(500);
      say('columns after dragging Super Sale left onto Everything Under 54 AED', await order(page, '.mo-cols > .mo-col'));

      // 6. Move to column…
      await page.locator('.mo-link:has(.mo-name[title^="Torriden"]) [data-mo-more]').click();
      await page.screenshot({ path: `${OUT}/more-panel-1280.png` });
      say('Move to column… options for Torriden', await page.locator('[data-mo-under] option').allTextContents());
      say('Move to column… options for Cleanse (a sub-menu with links)', await page.evaluate(() => KBBMenuOrder.parentChoices(MGM.tree, KBBMenuOrder.locate(MGM.tree, [...document.querySelectorAll('.mo-ghead .mo-name')].find((n) => n.textContent === 'Cleanse').dataset.moEdit).node.id).map((c) => c.label)));
      await page.locator('[data-mo-under]').selectOption({ label: 'Skincare › Masks' });
      await page.waitForTimeout(500);
      say('Masks after Move to column', await order(page, '.mo-grp:has(> .mo-ghead .mo-name[title^="Masks"]) .mo-link'));

      // 7. Inline add in a sub-menu.
      await page.locator('.mo-grp:has(> .mo-ghead .mo-name[title^="Masks"]) [data-mo-add]').click();
      await page.keyboard.type('Sleeping Masks');
      await page.keyboard.press('Tab');
      await page.keyboard.type('/collections/sleeping-masks/');
      await page.screenshot({ path: `${OUT}/inline-add-typing-1280.png` });
      await page.keyboard.press('Enter');
      await page.waitForTimeout(250);
      await page.screenshot({ path: `${OUT}/inline-add-saved-1280.png` });
      say('Masks after inline add', await order(page, '.mo-grp:has(> .mo-ghead .mo-name[title^="Masks"]) .mo-link'));

      // 8. The floating + and its sheet.
      await page.locator('.mo-fab').focus();
      await page.screenshot({ path: `${OUT}/fab-focus-1280.png` });
      await page.keyboard.press('Enter');
      await page.locator('[data-mo-choice="link"]').click();
      await page.locator('[data-mo-f="col"]').selectOption({ label: 'Brands' });
      await page.locator('[data-mo-f="sub"]').selectOption({ label: 'M – Z' });
      await page.locator('#moSheet [data-mo-f="label"]').fill('Purito');
      await page.screenshot({ path: `${OUT}/fab-sheet-1280.png` });
      say('sheet', await page.evaluate(() => ({ expanded: document.querySelector('.mo-fab').getAttribute('aria-expanded'), choices: [...document.querySelectorAll('[data-mo-choice]')].map((b) => b.textContent + (b.getAttribute('aria-pressed') === 'true' ? ' (on)' : '')) })));
      await page.locator('[data-mo-sheet-add]').click();
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${OUT}/fab-added-1280.png` });
      say('M – Z after adding from the sheet', await order(page, '.mo-grp:has(> .mo-ghead .mo-name[title^="M – Z"]) .mo-link'), 'sheet hidden', await page.locator('#moSheet').isHidden());

      // The server agrees with the screen, with no reload in between.
      say('server tree now', await serverTree(page));
      say('screen columns now', await order(page, '.mo-cols > .mo-col'));
    }
    if (w === 390) {
      // Touch: press and HOLD a row (the hold bar fills), then slide the
      // finger — a plain swipe still scrolls. Driven through CDP touch events.
      const tctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, hasTouch: true, isMobile: true, userAgent: UA });
      const tp = await tctx.newPage();
      tp.on('pageerror', (e) => errors.push(`touch: ${e}`));
      await login(tp); await open(tp);
      const cdp = await tctx.newCDPSession(tp);
      const touch = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y }] });
      const sel = (n) => `.mo-link:has(.mo-name[title^="${n}  "])`;
      const before = await order(tp, '.mo-grp:has(> .mo-ghead .mo-name[title^="Popular"]) .mo-link');
      const [ax, ay] = await center(tp, `${sel('Anua')} .mo-name`);
      const [, cy] = await center(tp, sel('Beauty of Joseon'));
      // a quick swipe first: no hold, so nothing may move
      await touch('touchStart', ax, ay); await touch('touchMove', ax, ay + 30); await touch('touchEnd');
      const afterSwipe = await order(tp, '.mo-grp:has(> .mo-ghead .mo-name[title^="Popular"]) .mo-link');
      const [bx, by] = await center(tp, `${sel('Anua')} .mo-name`);
      await touch('touchStart', bx, by);
      await tp.waitForTimeout(480); // the harness waits; the page runs no timer
      for (let k = 1; k <= 12; k++) await touch('touchMove', bx, by + ((cy - by) * k) / 12 + 2);
      await tp.screenshot({ path: `${OUT}/touch-drag-midway-390.png` });
      await touch('touchEnd');
      await tp.waitForTimeout(500);
      say('390 touch: Popular before', before, 'after a swipe (must not move)', afterSwipe, 'after hold + drag Anua down', await order(tp, '.mo-grp:has(> .mo-ghead .mo-name[title^="Popular"]) .mo-link'));
      await tctx.close();

      await page.evaluate(() => document.querySelector('.mo-board').scrollLeft = 200);
      say('390 after scrolling the board sideways', { scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth), innerWidth: 390 });
      await page.screenshot({ path: `${OUT}/board-390-scrolled.png` });
    }
    await ctx.close();
  }
  say('page errors', errors);
  fs.writeFileSync(`${OUT}/numbers.txt`, lines.join('\n') + '\n');
  await browser.close();
})();
