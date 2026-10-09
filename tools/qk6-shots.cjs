/*
 * Lane QK6: the checkout, photographed and measured.
 *
 *   sh tools/qk6-preview.sh 11370
 *   node tools/qk6-shots.cjs http://127.0.0.1:11370
 *
 * Writes docs/lane-qk6-shots/*.png and report.json. At 390 and 1280: the
 * coupon line before and after a REAL click (no navigation, one POST to the
 * checkout's own coupon endpoint, the total down, the promo box applied), a
 * double tap (still one request), Enter on the focused pill, the refusal when
 * the coupon expired after the page was drawn, the back links gone, the
 * Shipping Details order, the delivery labels paid / free / switched live by
 * a quantity step, a non-UAE country (the line hidden), Arabic, a real
 * fill-and-place with cash on delivery, and the admin tabs.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.argv[2] || 'http://127.0.0.1:11370';
const APP = path.join(__dirname, '..');
const OUT = path.join(APP, 'docs', 'lane-qk6-shots');
const DIR = path.join(APP, 'storage/framework/testing/qk6-preview');
const DB = path.join(DIR, 'preview.sqlite');
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
const CTX = {
  390: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE },
  1280: { viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1, userAgent: MAC },
};
const ids = JSON.parse(fs.readFileSync(path.join(DIR, 'webroot/qk6-ids.json')));
fs.mkdirSync(OUT, { recursive: true });

/* Straight to the database, bypassing the model: the page has ALREADY drawn
   the line from its snapshot, and the coupon expires under it. */
const sql = (q) => execFileSync('php', ['-r', `$p=new PDO('sqlite:${DB}');$p->exec(${JSON.stringify(q)});`]);

const box = (page, sel) => page.evaluate((s) => {
  const el = document.querySelector(s);
  if (!el) return null;
  const r = el.getBoundingClientRect();
  return { x: Math.round(r.x * 10) / 10, y: Math.round(r.y * 10) / 10, w: Math.round(r.width * 10) / 10, h: Math.round(r.height * 10) / 10 };
}, sel);
const hit = (page, sel) => page.evaluate((s) => {
  const el = document.querySelector(s);
  const r = el.getBoundingClientRect();
  const at = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
  return at === el || el.contains(at);
}, sel);
const text = (page, sel) => page.evaluate((s) => document.querySelector(s)?.textContent.replace(/\s+/g, ' ').trim() ?? null, sel);
async function waitDone(page, tag) {
  try { await page.waitForSelector('#kbbCline.is-done', { timeout: 8000 }); } catch (e) {
    console.log('NOT APPLIED', tag, page.url(), await text(page, '#kbbCouponMsg'), await text(page, '.co-cl-err'));
    await page.screenshot({ path: path.join(OUT, `debug-${tag}.png`) });
    throw e;
  }
}
const visible = (page, sel) => page.evaluate((s) => { const el = document.querySelector(s); return !!el && !el.hidden && getComputedStyle(el).visibility !== 'hidden' && el.getClientRects().length > 0; }, sel);

async function fresh(browser, size, products, locale = '', over = {}) {
  const ctx = await browser.newContext({ ...CTX[size], ...over });
  const page = await ctx.newPage();
  const log = { errors: [], navigations: 0, couponPosts: [], requests: [] };
  page.on('console', (m) => { if (m.type() === 'error') log.errors.push(m.text()); });
  page.on('pageerror', (e) => log.errors.push(String(e)));
  page.on('response', (res) => { if (res.status() >= 400) log.errors.push(res.status() + ' ' + res.url()); });
  page.on('framenavigated', (f) => { if (f === page.mainFrame()) log.navigations++; });
  page.on('request', (rq) => {
    log.requests.push(rq.method() + ' ' + new URL(rq.url()).pathname);
    if (rq.method() === 'POST' && /\/checkout\/coupon$/.test(new URL(rq.url()).pathname)) log.couponPosts.push(rq.postDataJSON());
  });
  // SQLite under four PHP workers answers "database is locked" now and then;
  // a basket that did not take is retried rather than photographed empty.
  for (let attempt = 0; attempt < 4; attempt++) {
    await page.goto(BASE + '/shop/', { waitUntil: 'domcontentloaded' });
    await page.evaluate(async (list) => {
      for (const id of list) {
        await fetch('/api/cart/add', { method: 'POST', headers: { 'X-CSRF-TOKEN': window.KBB.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ product_id: id, quantity: 1 }) });
      }
    }, products);
    await page.goto(BASE + locale + '/checkout/', { waitUntil: 'networkidle' });
    const n = await page.evaluate(() => document.querySelectorAll('#kbbSummary .co-q[data-d="1"]').length);
    if (n === products.length) break;
    await ctx.clearCookies();
  }
  await page.waitForTimeout(300);
  log.errors = log.errors.filter((e) => !/ERR_TUNNEL|fonts\.g/.test(e));
  log.navigations = 0; log.requests = []; log.couponPosts = [];
  return { ctx, page, log };
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};

  for (const size of [390, 1280]) {
    const r = report[size] = {};
    // /checkout/coupon is throttled at 20 a minute per client, and one size
    // makes seven; a clean minute before each keeps the run inside it.
    await new Promise((res) => setTimeout(res, 61000));

    /* ── 1. before / after the tap ── */
    let { ctx, page, log } = await fresh(browser, size, [ids['qk6-serum']]);
    r.loadErrors = [...log.errors];
    r.scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
    r.line = await box(page, '#kbbCline');
    r.pill = await box(page, '.co-cl-code');
    r.summaryRow = await box(page, '#kbbSumRow');
    r.lineAboveSummary = r.line.y + r.line.h <= r.summaryRow.y;
    r.lineText = await text(page, '.co-cl-ask');
    r.lineFont = await page.evaluate(() => getComputedStyle(document.querySelector('.co-cline')).fontSize);
    r.pillHitFirstTry = await hit(page, '.co-cl-code');
    r.totalBefore = await text(page, '#kbbSumRow .cosr-tot .js-total');
    r.backlinkPresent = await page.evaluate(() => !!document.querySelector('.backlink, .co-tocart'));
    r.logoHref = await page.evaluate(() => document.querySelector('.co-head a')?.getAttribute('href'));
    r.titlebarFirstChild = await page.evaluate(() => document.querySelector('.co-titlebar-main').firstElementChild.className);
    await page.screenshot({ path: path.join(OUT, `before-${size}.png`) });

    await page.click('.co-cl-code');
    await waitDone(page, 'first-' + size);
    await page.waitForTimeout(300);
    r.after = {
      navigations: log.navigations,
      couponPosts: log.couponPosts.map((b) => ({ code: b.code, remove: b.remove })),
      lineDone: await text(page, '.co-cl-done'),
      lineBox: await box(page, '#kbbCline'),
      totalAfter: await text(page, '#kbbSumRow .cosr-tot .js-total'),
      promoBox: await page.inputValue('#kbb_coupon_code'),
      promoLine: await text(page, '#kbbCouponMsg'),
      errors: log.errors,
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
    };
    r.after.lineHeightUnchanged = r.after.lineBox.h === r.line.h;
    await page.screenshot({ path: path.join(OUT, `after-${size}.png`) });
    // The order block, opened, to show the discount line.
    await page.click('#kbbSumRow');
    await page.waitForTimeout(400);
    r.after.discountRow = await page.evaluate(() => [...document.querySelectorAll('#kbbSummary .sumrow')].map((e) => e.textContent.replace(/\s+/g, ' ').trim()).filter((t) => t));
    await page.screenshot({ path: path.join(OUT, `after-${size}-summary-open.png`) });
    // Reload: the line is drawn applied by the server.
    await page.reload({ waitUntil: 'networkidle' });
    r.after.reloadedDone = await page.evaluate(() => document.getElementById('kbbCline').classList.contains('is-done'));
    // Remove from the promo box: the line invites again.
    await page.click('[data-kbb-coupon-remove]');
    await page.waitForTimeout(1200);
    r.after.afterRemoveDone = await page.evaluate(() => document.getElementById('kbbCline').classList.contains('is-done'));
    await ctx.close();

    /* ── 2. a double tap is one request; Enter on the focused pill applies ── */
    ({ ctx, page, log } = await fresh(browser, size, [ids['qk6-serum']]));
    await page.dblclick('.co-cl-code');
    await waitDone(page, 'x-' + size);
    await page.waitForTimeout(500);
    r.doubleTapPosts = log.couponPosts.length;
    await page.click('[data-kbb-coupon-remove]');
    await page.waitForTimeout(1200);
    log.couponPosts = [];
    await page.focus('.co-cl-code');
    await page.keyboard.press('Enter');
    await waitDone(page, 'x-' + size);
    r.keyboardEnterApplied = true;
    r.keyboardPosts = log.couponPosts.length;
    r.focusAfterApply = await page.evaluate(() => document.activeElement?.className);
    await ctx.close();

    /* ── 3. the coupon expires after the page was drawn: the reason ── */
    // The throttle is shared by every throttled route a visitor uses (the
    // basket's add included), so a clean minute before the one that matters.
    await new Promise((res) => setTimeout(res, 61000));
    ({ ctx, page, log } = await fresh(browser, size, [ids['qk6-serum']]));
    sql("update coupons set expires_at = '2020-01-01 00:00:00' where code = 'GLOW'");
    await page.click('.co-cl-code');
    await page.waitForFunction(() => !document.querySelector('.co-cl-err').hidden, null, { timeout: 8000 });
    r.failure = {
      lineError: await text(page, '.co-cl-err'),
      boxError: await text(page, '#kbbCouponMsg'),
      navigations: log.navigations,
      stillInvites: await page.evaluate(() => !document.getElementById('kbbCline').classList.contains('is-done')),
      pillBusyCleared: await page.evaluate(() => !document.querySelector('.co-cl-code').hasAttribute('aria-busy')),
    };
    await page.screenshot({ path: path.join(OUT, `fail-expired-${size}.png`) });
    sql("update coupons set expires_at = null where code = 'GLOW'");
    await ctx.close();

    /* ── 4. delivery: paid, then a quantity step crosses AED 199 live ── */
    ({ ctx, page, log } = await fresh(browser, size, [ids['qk6-serum']]));
    r.delivery = { paidLabel: await text(page, '#kbbDeliverySlot label'), note: await text(page, '#kbbDeliveryNote'), noteVisible: await visible(page, '#kbbDeliveryNote') };
    r.delivery.heading = await box(page, '#kbbDeliverySlot');
    await page.evaluate(() => document.getElementById('kbbDeliverySlot').closest('.sec').scrollIntoView({ block: 'center', behavior: 'instant' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(OUT, `delivery-paid-${size}.png`) });
    await page.click('#kbbSumRow');
    await page.waitForTimeout(400);
    await page.click('#kbbSummary .co-q[data-d="1"]');
    await page.waitForFunction(() => /Free/.test(document.querySelector('#kbbDeliverySlot label')?.textContent || ''), null, { timeout: 8000 });
    r.delivery.freeLabelAfterQtyStep = await text(page, '#kbbDeliverySlot label');
    r.delivery.navigationsDuringStep = log.navigations;
    await page.evaluate(() => document.getElementById('kbbDeliverySlot').closest('.sec').scrollIntoView({ block: 'center', behavior: 'instant' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(OUT, `delivery-free-live-${size}.png`) });

    /* ── 5. another country: the line beside Delivery goes ── */
    await page.selectOption('#billing_country', 'OM');
    await page.waitForFunction(() => document.getElementById('kbbDeliveryNote').hidden, null, { timeout: 8000 });
    await page.waitForTimeout(300);
    r.delivery.oman = { label: await text(page, '#kbbDeliverySlot label'), noteVisible: await visible(page, '#kbbDeliveryNote') };
    await page.evaluate(() => document.getElementById('kbbDeliverySlot').closest('.sec').scrollIntoView({ block: 'center', behavior: 'instant' }));
    await page.screenshot({ path: path.join(OUT, `delivery-oman-${size}.png`) });
    await page.selectOption('#billing_country', 'AE');
    await page.waitForFunction(() => !document.getElementById('kbbDeliveryNote').hidden, null, { timeout: 8000 });
    r.delivery.backToUae = { label: await text(page, '#kbbDeliverySlot label'), note: await text(page, '#kbbDeliveryNote') };
    r.delivery.errors = log.errors;
    await ctx.close();

    /* ── 6. Shipping Details, a validation miss, then a real order ── */
    ({ ctx, page, log } = await fresh(browser, size, [ids['qk6-serum']]));
    r.form = await page.evaluate(() => {
      const secs = [...document.querySelectorAll('#customer_details > .sec')];
      return secs.slice(0, 2).map((s) => ({ h: s.querySelector('h2').textContent.replace(/\s+/g, ' ').trim(), fields: [...s.querySelectorAll('input:not([type=hidden]),select,textarea')].map((i) => i.id).filter(Boolean) }));
    });
    await page.evaluate(() => document.querySelector('#customer_details .sec:nth-child(2)').scrollIntoView({ block: 'start', behavior: 'instant' }));
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(OUT, `shipping-details-${size}.png`) });
    await page.fill('#billing_email', 'qk6@example.test');
    await page.fill('#billing_first_name', 'Mariam Saleh');
    await page.fill('#billing_address_1', 'Marina Gate 2, Apt 1804');
    await page.fill('#billing_address_2', 'Dubai Marina');
    if (await page.$('select#billing_state')) await page.selectOption('#billing_state', { index: 1 }); else await page.fill('#billing_state', 'Dubai');
    await page.check('input[name=payment_method][value=cod]').catch(() => {});
    await page.locator('button.place[data-place]:visible').first().click();
    await page.waitForTimeout(600);
    r.validation = { focused: await page.evaluate(() => document.activeElement?.id), navigations: log.navigations };
    await page.fill('#billing_phone', '0501234567');
    await Promise.all([
      page.waitForURL(/order-received|thank|success/, { timeout: 20000 }).catch(() => null),
      page.locator('button.place[data-place]:visible').first().click(),
    ]);
    await page.waitForTimeout(800);
    r.placed = { url: new URL(page.url()).pathname, errors: log.errors };
    await page.screenshot({ path: path.join(OUT, `placed-${size}.png`) });
    await ctx.close();

    /* ── 7. Arabic ── */
    await new Promise((res) => setTimeout(res, 61000));
    ({ ctx, page, log } = await fresh(browser, size, [ids['qk6-serum']], '/ar'));
    r.ar = {
      line: await text(page, '.co-cl-ask'),
      shipping: await page.evaluate(() => document.querySelectorAll('#customer_details > .sec h2')[1].textContent.replace(/\s+/g, ' ').trim()),
      delivery: await text(page, '#kbbDeliverySlot label'),
      note: await text(page, '#kbbDeliveryNote'),
      scrollWidth: await page.evaluate(() => document.documentElement.scrollWidth),
      errors: log.errors,
    };
    await page.screenshot({ path: path.join(OUT, `ar-before-${size}.png`) });
    await page.click('.co-cl-code');
    await waitDone(page, 'x-' + size);
    await page.waitForTimeout(300);
    r.ar.done = await text(page, '.co-cl-done');
    r.ar.navigations = log.navigations;
    await page.screenshot({ path: path.join(OUT, `ar-after-${size}.png`) });
    await page.evaluate(() => document.getElementById('kbbDeliverySlot').closest('.sec').scrollIntoView({ block: 'center', behavior: 'instant' }));
    await page.screenshot({ path: path.join(OUT, `ar-delivery-${size}.png`) });
    // Fill and place in Arabic too.
    await page.fill('#billing_email', 'qk6ar@example.test');
    await page.fill('#billing_first_name', 'مريم صالح');
    await page.fill('#billing_phone', '0501234567');
    await page.fill('#billing_address_1', 'Marina Gate 2, Apt 1804');
    await page.fill('#billing_address_2', 'Dubai Marina');
    if (await page.$('select#billing_state')) await page.selectOption('#billing_state', { index: 1 }); else await page.fill('#billing_state', 'Dubai');
    await page.check('input[name=payment_method][value=cod]').catch(() => {});
    await Promise.all([
      page.waitForURL(/order-received|thank|success/, { timeout: 20000 }).catch(() => null),
      page.locator('button.place[data-place]:visible').first().click(),
    ]);
    await page.waitForTimeout(800);
    r.ar.placed = new URL(page.url()).pathname;
    await ctx.close();

    /* ── 8. 320: the Delivery line wraps rather than squeezing ── */
    if (size === 390) {
      const { ctx: c, page: p } = await fresh(browser, 390, [ids['qk6-serum']], '', { viewport: { width: 320, height: 700 } });
      await p.evaluate(() => document.getElementById('kbbDeliverySlot').closest('.sec').scrollIntoView({ block: 'center', behavior: 'instant' }));
      report[320] = { scrollWidth: await p.evaluate(() => document.documentElement.scrollWidth), line: await box(p, '#kbbCline'), note: await box(p, '#kbbDeliveryNote'), heading: await box(p, '#kbbDeliveryNote') };
      await p.screenshot({ path: path.join(OUT, 'delivery-320.png') });
      await p.evaluate(() => window.scrollTo(0, 0));
      await p.screenshot({ path: path.join(OUT, 'top-320.png') });
      await c.close();
    }

    /* ── 9. the admin tabs ── */
    const c3 = await browser.newContext(CTX[size]);
    const p3 = await c3.newPage();
    await p3.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await p3.fill('input[name=email]', 'owner@example.com');
    await p3.fill('input[name=password]', 'preview-password');
    await Promise.all([p3.waitForNavigation({ waitUntil: 'networkidle' }), p3.click('button[type=submit]')]);
    await p3.waitForTimeout(800);
    await p3.evaluate(() => window.go('checkoutpage'));
    for (const tab of ['cline', 'delivery', 'tocart']) {
      await p3.waitForSelector(`[data-chp-tab="${tab}"]`);
      await p3.click(`[data-chp-tab="${tab}"]`);
      await p3.waitForTimeout(400);
      await p3.screenshot({ path: path.join(OUT, `admin-${tab}-${size}.png`), fullPage: size === 390 ? false : true });
    }
    await p3.click('[data-chp-tab="cline"]');
    await p3.waitForTimeout(300);
    r.adminCouponOptions = await p3.evaluate(() => [...document.querySelectorAll('#chp-cline_coupon option')].map((o) => (o.selected ? '* ' : '  ') + o.textContent));
    await c3.close();
  }

  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1) + '\n');
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
