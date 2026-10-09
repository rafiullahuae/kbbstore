/**
 * Lane ORD, in a real browser: the admin Orders list (the whole row opens the
 * order; "Set status to…" waits for Proceed) and the owner app's orders list
 * (long-press selects; Set status… asks before it applies).
 *
 * Driven by tests/Feature/OrdersRowBulkBrowserTest.php, which supplies the
 * server and the fixtures. Prints one JSON object on stdout and nothing else.
 *
 *   KBB_ORD_BASE      base URL of a running preview          (required)
 *   KBB_ORD_EMAIL     admin login                            (required)
 *   KBB_ORD_PASSWORD  admin password                         (required)
 *   KBB_ORD_PIN       the owner app PIN for the same admin   (required)
 *   KBB_ORD_APP       the owner app's secret path segment    (required)
 *   KBB_ORD_CHROME    chromium executable                    (required)
 *   KBB_ORD_SHOTS     a directory to write screenshots into  (optional)
 */
import { chromium } from 'playwright';

const E = process.env;
const BASE = E.KBB_ORD_BASE, EMAIL = E.KBB_ORD_EMAIL, PASSWORD = E.KBB_ORD_PASSWORD;
const PIN = E.KBB_ORD_PIN, APP = E.KBB_ORD_APP, EXE = E.KBB_ORD_CHROME, SHOTS = E.KBB_ORD_SHOTS || '';

const out = { ok: false, admin: {}, app: {}, errors: [] };
const done = (extra) => { process.stdout.write(JSON.stringify(Object.assign(out, extra || {}))); process.exit(0); };
for (const [k, v] of Object.entries({ BASE, EMAIL, PASSWORD, PIN, APP, EXE })) if (!v) done({ error: k + ' is not set' });

const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: SHOTS + '/' + name + '.png' }); };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const watch = (page, tag) => {
  page.on('pageerror', (e) => out.errors.push(tag + ' pageerror: ' + e.message.split('\n')[0]));
  page.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) out.errors.push(tag + ' console: ' + m.text().slice(0, 200)); });
};

let browser;
let cur = null;
try {
  browser = await chromium.launch({ executablePath: EXE });

  /* ============================================================ admin ===== */
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  cur = page;
  watch(page, 'admin');
  const bulkCalls = [];
  const listCalls = [];
  page.on('request', (r) => {
    if (r.url().includes('/admin-api/orders-bulk-status')) bulkCalls.push(r.postData() || '');
    if (r.url().includes('/admin-api/orders-list')) listCalls.push(r.url());
  });

  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('button[type="submit"], input[type="submit"]')]);
  await page.waitForFunction(() => typeof window.go === 'function');
  await page.evaluate(() => window.go('orders'));
  await page.waitForSelector('tr[data-olrow]');

  const A = out.admin;
  const ids = await page.$$eval('tr[data-olrow]', (rs) => rs.map((r) => +r.dataset.olrow));
  A.rows = ids.length;
  A.href = await page.$eval('a[data-olopen]', (a) => ({ href: a.getAttribute('href'), id: +a.dataset.olopen }));
  A.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  A.rowHeight = await page.$eval('tr[data-olrow]', (r) => r.getBoundingClientRect().height);

  const onDetail = () => page.evaluate(() => !!document.getElementById('ordBack'));
  const backToList = async () => { await page.evaluate(() => window.go('orders')); await page.waitForSelector('tr[data-olrow]'); };

  // hover
  await page.hover('tr[data-olrow]:nth-child(2) td:nth-child(3)');
  A.hoverCursor = await page.$eval('tr[data-olrow]:nth-child(2)', (r) => getComputedStyle(r).cursor);
  A.hoverBg = await page.$eval('tr[data-olrow]:nth-child(2) td:nth-child(3)', (td) => getComputedStyle(td).backgroundColor);
  await shot(page, 'admin-1280-row-hover');

  // a plain click on the customer cell opens the order
  await page.click('tr[data-olrow]:nth-child(2) td:nth-child(3)');
  await page.waitForTimeout(600);
  A.cellClickOpens = await onDetail();
  await backToList();

  // the checkbox, the View button's neighbour controls and a drag-select do not navigate
  await page.click('tr[data-olrow]:nth-child(3) [data-olsel]');
  await page.waitForTimeout(250);
  A.checkboxStays = !(await onDetail()) && (await page.$('#olBulkStatus')) !== null;
  await page.click('#olClearSel');
  await page.waitForTimeout(150);

  const cell = await page.$('tr[data-olrow]:nth-child(4) td:nth-child(3) .pbrand');
  const box = await cell.boundingBox();
  await page.mouse.move(box.x + 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width - 2, box.y + box.height / 2, { steps: 8 });
  await page.mouse.up();
  await page.waitForTimeout(400);
  A.dragSelected = await page.evaluate(() => String(window.getSelection()).length);
  A.dragStays = !(await onDetail());
  await page.evaluate(() => window.getSelection().removeAllRanges());

  // a select inside the bar does not navigate either (tick one to show the bar)
  await page.click('tr[data-olrow]:nth-child(3) [data-olsel]');
  await page.click('#olBulkPrint');
  await page.keyboard.press('Escape');
  await page.waitForTimeout(150);
  A.selectStays = !(await onDetail());
  await page.click('#olClearSel');
  await page.waitForTimeout(150);

  // Ctrl-click and middle-click open a NEW TAB on the order's own address
  const tabOf = async (fn) => {
    const [p] = await Promise.all([ctx.waitForEvent('page', { timeout: 8000 }), fn()]);
    await p.waitForLoadState('domcontentloaded');
    await p.waitForFunction(() => !!document.getElementById('ordBack') || !!document.querySelector('tr[data-olrow]'), null, { timeout: 15000 }).catch(() => {});
    const r = { url: p.url(), detail: await p.evaluate(() => !!document.getElementById('ordBack')) };
    await p.close();
    return r;
  };
  A.ctrlClick = await tabOf(() => page.click('tr[data-olrow]:nth-child(2) td:nth-child(5)', { modifiers: ['Control'] }));
  A.ctrlClickStays = !(await onDetail());
  A.middleClick = await tabOf(() => page.click('tr[data-olrow]:nth-child(2) td:nth-child(3)', { button: 'middle' }));
  A.middleOnLink = await tabOf(() => page.click('tr[data-olrow]:nth-child(2) a[data-olopen]', { button: 'middle' }));
  A.middleStays = !(await onDetail());

  // keyboard: Enter on a focused row
  await page.focus('tr[data-olrow]:nth-child(2)');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(600);
  A.enterOpens = await onDetail();
  await backToList();

  // the link's own plain click stays in this tab and opens the order
  await page.click('tr[data-olrow]:nth-child(2) a[data-olopen]');
  await page.waitForTimeout(600);
  A.linkClickOpens = await onDetail();
  A.urlAfterLinkClick = page.url();
  await backToList();

  // View still opens the order
  await page.click('tr[data-olrow]:nth-child(2) [data-olview]');
  await page.waitForTimeout(600);
  A.viewOpens = await onDetail();
  await backToList();

  /* ---------------------------------------------- bulk status + Proceed */
  // tick 4 processing rows and the one completed row
  const done1 = await page.$$eval('tr[data-olrow]', (rs) => rs.filter((r) => r.querySelector('.pill') && r.querySelector('.pill').textContent.trim() === 'completed').map((r) => +r.dataset.olrow));
  const pick = ids.filter((id) => done1.indexOf(id) === -1).slice(0, 4).concat(done1.slice(0, 1));
  for (const id of pick) await page.click('tr[data-olrow="' + id + '"] [data-olsel]');
  A.picked = pick.length;
  const before = bulkCalls.length;
  await page.selectOption('#olBulkStatus', 'completed');
  await page.waitForTimeout(700);
  A.callsAfterSelect = bulkCalls.length - before;
  A.proceedText = await page.$eval('#olProceedText', (x) => x.textContent).catch(() => null);
  A.proceedVisible = (await page.$('#olProceed')) !== null;
  A.selectHolds = await page.$eval('#olBulkStatus', (s) => s.value);
  await shot(page, 'admin-1280-proceed-bar');

  await page.click('#olProceedNo');
  await page.waitForTimeout(300);
  A.cancelHides = (await page.$('#olProceed')) === null;
  A.callsAfterCancel = bulkCalls.length - before;

  await page.selectOption('#olBulkStatus', 'completed');
  await page.waitForSelector('#olProceed');
  const listBefore = listCalls.length;
  const t0 = Date.now();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin-api/orders-bulk-status')), page.click('#olProceed')]);
  await page.waitForSelector('#olResult');
  A.applyMs = Date.now() - t0;
  A.callsAfterProceed = bulkCalls.length - before;
  A.body = JSON.parse(bulkCalls[bulkCalls.length - 1] || '{}');
  A.result = await page.$eval('#olResult', (x) => x.textContent.trim());
  A.loadingShown = await page.evaluate(() => /Loading orders/.test(document.querySelector('#content').textContent));
  A.rowsAfter = await page.$$eval('tr[data-olrow]', (rs, p) => rs.filter((r) => p.indexOf(+r.dataset.olrow) !== -1).map((r) => r.querySelector('.pill') ? r.querySelector('.pill').textContent.trim() : r.textContent), pick);
  A.selectionCleared = await page.evaluate(() => !document.querySelector('#olBulkStatus') && !document.querySelector('.cbx.on[data-olsel]'));
  await page.waitForTimeout(600);
  A.listCallsAfterProceed = listCalls.length - listBefore;
  A.resultStillThere = (await page.$('#olResult')) !== null;
  await shot(page, 'admin-1280-result-line');

  // 390: no sideways page scroll with the Proceed bar up
  await page.setViewportSize({ width: 390, height: 844 });
  await page.click('tr[data-olrow]:nth-child(2) [data-olsel]');
  await page.click('tr[data-olrow]:nth-child(3) [data-olsel]');
  await page.selectOption('#olBulkStatus', 'shipped');
  await page.waitForSelector('#olProceed');
  A.scrollWidth390 = await page.evaluate(() => document.documentElement.scrollWidth);
  await shot(page, 'admin-390-proceed-bar');
  await ctx.close();

  /* ======================================================== owner app ===== */
  const m = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  const p = await m.newPage();
  cur = p;
  watch(p, 'app');
  const appBulk = [];
  p.on('request', (r) => { if (r.url().includes('/api/orders-bulk-status')) appBulk.push(r.postData() || ''); });
  const P = out.app;

  await p.goto(BASE + '/' + APP + '/', { waitUntil: 'domcontentloaded' });
  await p.waitForSelector('[data-enrol]', { timeout: 15000 });
  await p.fill('[data-enrol] [name=email]', EMAIL);
  await p.fill('[data-enrol] [name=pin]', PIN);
  await p.click('[data-enrol] button[type=submit]');
  await p.waitForSelector('.nav', { timeout: 15000 });
  await p.waitForTimeout(500);
  // anything the app offers on a first open (install, notifications) is closed
  for (let i = 0; i < 3; i++) { if (await p.$('.sheet.open')) { await p.keyboard.press('Escape'); await p.waitForTimeout(400); } }
  await p.evaluate(() => { location.hash = '#/orders'; });
  await p.waitForSelector('.list .row.ord', { timeout: 15000 });
  await p.waitForTimeout(400);
  await p.evaluate(() => document.querySelectorAll('.tn, .nudge').forEach((x) => x.remove()));
  P.rows = await p.$$eval('.list .row.ord', (rs) => rs.length);
  P.scrollWidth = await p.evaluate(() => document.documentElement.scrollWidth);
  P.selectBtn = (await p.$('[data-act="selmode"]')) !== null;

  const cdp = await m.newCDPSession(p);
  const centre = async (sel) => { const b = await (await p.$(sel)).boundingBox(); return { x: Math.round(b.x + b.width * 0.6), y: Math.round(b.y + b.height / 2) }; };
  const touch = (type, pt) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x: pt.x, y: pt.y }] });
  const selected = () => p.$$eval('.list .row.ord.on', (rs) => rs.map((r) => +r.getAttribute('data-o')));
  const inMode = () => p.evaluate(() => document.getElementById('oa-app').classList.contains('selecting'));

  // 1. a drag of 30px (a scroll) held well past the threshold selects nothing
  let pt = await centre('.list .row.ord:nth-child(2)');
  await touch('touchStart', pt);
  for (let i = 1; i <= 6; i++) { await touch('touchMove', { x: pt.x, y: pt.y - i * 5 }); await wait(16); }
  await wait(700);
  await touch('touchEnd', pt);
  await wait(300);
  P.scrollSelects = (await selected()).length;
  P.scrollMode = await inMode();
  P.hashAfterScroll = await p.evaluate(() => location.hash);

  // 2. held 300 ms: not yet; held 600 ms: selected, in selection mode; a 4px wobble does not cancel it
  pt = await centre('.list .row.ord:nth-child(2)');
  await touch('touchStart', pt);
  await wait(80);
  await touch('touchMove', { x: pt.x + 3, y: pt.y + 2 });
  await wait(220);
  P.at300 = (await selected()).length;
  await wait(300);
  P.at600 = (await selected()).length;
  P.modeAt600 = await inMode();
  await touch('touchEnd', pt);
  await wait(400);
  P.afterRelease = (await selected()).length;
  P.hashAfterLongPress = await p.evaluate(() => location.hash);
  P.boxes = await p.$$eval('.list .row.ord', (rs) => rs.filter((r) => getComputedStyle(r.querySelector('.sel'), '::before').content !== 'none').length);
  await shot(p, 'app-390-long-press-selection');

  // 3. a tap now toggles another row rather than opening it
  await p.tap('.list .row.ord:nth-child(3) .rm');
  await wait(300);
  P.afterTap = (await selected()).length;
  P.hashAfterTap = await p.evaluate(() => location.hash);
  P.barText = await p.$eval('.bulk', (b) => b.innerText.replace(/\s+/g, ' ').trim());
  await shot(p, 'app-390-action-bar');

  // 4. Set status… -> the sheet -> Shipped -> the confirmation; nothing sent until Proceed
  await p.tap('[data-act="bulk-more"]');
  await p.waitForSelector('.sheet.open .stlist');
  await wait(350);
  await shot(p, 'app-390-status-sheet');
  await p.tap('.sheet.open [data-st="shipped"]');
  await p.waitForSelector('.sheet.open [data-proceed]');
  await wait(350);
  P.confirmText = await p.$eval('.sheet.open [data-confirm]', (x) => x.textContent);
  P.callsBeforeProceed = appBulk.length;
  await shot(p, 'app-390-confirm');
  await Promise.all([p.waitForResponse((r) => r.url().includes('/api/orders-bulk-status')), p.tap('.sheet.open [data-proceed]')]);
  await wait(900);
  P.callsAfterProceed = appBulk.length;
  P.body = JSON.parse(appBulk[0] || '{}');
  P.modeAfter = await inMode();
  P.toast = await p.$eval('.toast', (t) => t.textContent).catch(() => null);
  await shot(p, 'app-390-after-proceed');

  // 5. the quick buttons ask too
  await p.tap('[data-act="selmode"]');
  await wait(250);
  P.selectButtonMode = await inMode();
  P.selectButtonCount = (await selected()).length;
  await p.tap('.list .row.ord:nth-child(1) .rm');
  await wait(200);
  await p.tap('.bulk [data-act="bulk"][data-v="onhold"]');
  await wait(400);
  P.quickAsks = (await p.$('.sheet.open [data-proceed]')) !== null;
  P.callsAfterQuick = appBulk.length;
  await p.keyboard.press('Escape');
  await wait(350);
  await p.tap('.selhdr [data-act="selnone"]');
  await wait(250);

  // 6. outside selection mode a tap still opens the order
  await p.tap('.list .row.ord:nth-child(2) .rm');
  await wait(700);
  P.tapOpens = await p.evaluate(() => /^#\/orders\/\d+$/.test(location.hash));

  out.ok = true;
  await m.close();
  done();
} catch (e) {
  let where = '';
  try { if (cur) { where = cur.url() + ' :: ' + (await cur.evaluate(() => document.body.innerText.slice(0, 300))); if (SHOTS) await cur.screenshot({ path: SHOTS + '/failure.png' }); } } catch (e2) { /* nothing more to say */ }
  done({ error: String(e && e.message || e).split('\n')[0], where });
} finally {
  if (browser) await browser.close().catch(() => {});
}
