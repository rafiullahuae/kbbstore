/*
 * Lane PU — the evidence for Store → Orders → (an order).
 *
 *   BASE=http://127.0.0.1:8961 TAG=before node tools/pu-shots.cjs
 *   BASE=http://127.0.0.1:8971 TAG=after  node tools/pu-shots.cjs
 *
 * BEFORE points at a preview built from the commit before this lane
 * (tools/pu-preview.sh PORT b7c08b3); AFTER at the working tree. Both are
 * seeded by tools/pu-seed.php, so #33415 is the owner's own report: Cash on
 * delivery, Processing, imported the way the WooCommerce import writes it.
 *
 * Measurements are printed as JSON lines as well as drawn, for rule 4.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8971';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'storage/pu-logs/shots';
const TAG = process.env.TAG || 'after';
const AFTER = TAG === 'after';

const log = (o) => console.log(JSON.stringify(o));

async function signIn(page, who) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', who + '@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
}

async function openOrder(page, number) {
  await page.evaluate(() => window.go('orders'));
  await page.waitForSelector('[data-olview]', { timeout: 15000 });
  await page.waitForTimeout(300);
  await page.evaluate((n) => {
    const b = [...document.querySelectorAll('[data-olview]')].find((x) => x.closest('tr').innerText.includes('#' + n) || x.closest('tr').innerText.includes(n));
    b.click();
  }, number);
  await page.waitForSelector('#odGeneral', { timeout: 15000 });
  await page.waitForTimeout(700);
}

const measure = (page) => page.evaluate(() => {
  const p = document.getElementById('odPayPanel');
  const h = p && p.querySelector('.odpay-h');
  const r = p && p.getBoundingClientRect();
  const totals = document.querySelector('#odItems .odpayrow > div:last-child');
  const t = totals && totals.getBoundingClientRect();
  return {
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    panel: p ? { state: p.dataset.state, w: Math.round(r.width), h: Math.round(r.height), x: Math.round(r.left), top: Math.round(r.top),
      headline: h.innerText, headlinePx: getComputedStyle(h).fontSize, bg: getComputedStyle(p).backgroundColor, border: getComputedStyle(p).borderTopColor } : null,
    totals: t ? { x: Math.round(t.left), top: Math.round(t.top), w: Math.round(t.width) } : null,
    captureButton: !!document.getElementById('odCaptureGo'),
    notCapturedText: /Not captured/.test(document.querySelector('#odItems').innerText),
  };
});

async function shotEl(page, sel, name) {
  const el = await page.$(sel);
  if (!el) { log({ missing: sel, name }); return; }
  await el.scrollIntoViewIfNeeded();
  await el.screenshot({ path: `${OUT}/${name}.png` });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 1000 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await signIn(page, 'owner');
    const w = width;

    /* -------- the payment panel: COD to collect / paid / not paid -------- */
    for (const [n, label] of [['33415', 'cod-due'], ['33422', 'paid-card-refund'], ['33420', 'not-paid'], ['33388', 'cod-collected'], ['33425', 'tabby-capture']]) {
      if (!AFTER && !['33415', '33422', '33420'].includes(n)) continue;
      await openOrder(page, n);
      log({ tag: TAG, width: w, order: n, ...(await measure(page)) });
      await shotEl(page, '#odItems', `${TAG}-${w}-${label}-items`);
      if (n === '33415') {
        await page.screenshot({ path: `${OUT}/${TAG}-${w}-33415-top.png` });
        await shotEl(page, '#odGeneral', `${TAG}-${w}-33415-general`);
      }
    }

    /* -------- Billing / Shipping → Edit -------- */
    await openOrder(page, '33415');
    if (!AFTER) {
      const hashBefore = await page.evaluate(() => location.hash);
      await page.evaluate(() => [...document.querySelectorAll('#odGeneral .odcollabel a')][0].click());
      await page.waitForTimeout(600);
      log({ tag: TAG, width: w, billingEditClick: { hashBefore, hashAfter: await page.evaluate(() => location.hash),
        modalOpen: await page.evaluate(() => document.getElementById('modalBg').classList.contains('on')),
        inputsInCard: await page.evaluate(() => document.querySelectorAll('#odGeneral input').length) } });
      await page.evaluate(() => document.querySelector('[data-odedit="shipping"]').click());
      await page.waitForTimeout(400);
      await shotEl(page, '#odGeneral', `${TAG}-${w}-shipping-edit-json`);
      /* Order history → went to the whole Customers list. */
      await page.evaluate(() => document.getElementById('odCustHist').click());
      await page.waitForTimeout(1500);
      log({ tag: TAG, width: w, orderHistoryLandedOn: await page.evaluate(() => (document.querySelector('#content .page-head h2, #content h2') || {}).innerText) });
      await page.screenshot({ path: `${OUT}/${TAG}-${w}-order-history-click.png` });
    } else {
      const type = w === 1280 ? 'billing' : 'shipping';
      await page.click(`[data-odedit="${type}"]`);
      await page.waitForSelector('#modal #odAdGo');
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${OUT}/${TAG}-${w}-${type}-edit-modal.png` });
      log({ tag: TAG, width: w, addressModal: await page.evaluate(() => {
        const m = document.getElementById('modal').getBoundingClientRect();
        return { w: Math.round(m.width), h: Math.round(m.height), fields: document.querySelectorAll('#modal [data-odad]').length,
          scrollWidth: document.documentElement.scrollWidth };
      }) });
      // A bad postcode is refused by the server, field by field.
      await page.fill('#odAd_postcode', 'AB<12>');
      await page.click('#odAdGo');
      await page.waitForTimeout(700);
      log({ tag: TAG, width: w, postcodeError: await page.evaluate(() => document.querySelector('#modal [data-oderr="address.postcode"]').textContent) });
      await page.fill('#odAd_postcode', '');
      await page.fill('#odAd_city', 'Sharjah');
      await page.fill('#odAd_state', 'Sharjah');
      await page.fill('#odAd_company', 'Bliss Trading LLC');
      await page.click('#odAdGo');
      await page.waitForSelector('#odGeneral', { timeout: 15000 });
      await page.waitForTimeout(900);
      await shotEl(page, '#odGeneral', `${TAG}-${w}-${type}-edit-saved`);
      log({ tag: TAG, width: w, savedAddress: await page.evaluate((t) => document.querySelectorAll('#odGeneral .odaddr')[t === 'billing' ? 0 : 1].innerText, type),
        note: await page.evaluate(() => (document.querySelector('#odNotes .pad > div') || {}).innerText) });
      await shotEl(page, '#odNotes', `${TAG}-${w}-${type}-edit-note`);

      /* -------- Order history popup -------- */
      await page.click('#odCustHist');
      await page.waitForSelector('#modal .odh-row', { timeout: 15000 });
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${OUT}/${TAG}-${w}-history-popup.png` });
      log({ tag: TAG, width: w, history: await page.evaluate(() => ({
        rows: document.querySelectorAll('#modal .odh-row').length,
        kpis: [...document.querySelectorAll('#modal .odh-kpis b')].map((b) => b.innerText),
        card: [...document.querySelectorAll('#odHist .odfld div[style*="font-size:17px"]')].map((b) => b.innerText),
        pager: (document.querySelector('#modal .odh-pager span') || {}).innerText,
        first: (document.querySelector('#modal .odh-row') || {}).innerText,
        scrollWidth: document.documentElement.scrollWidth,
        stillOnOrder: !!document.getElementById('odGeneral'),
      })) });
      await page.keyboard.press('Escape');
      await page.waitForTimeout(300);
      log({ tag: TAG, width: w, historyClosedByEscape: await page.evaluate(() => !document.getElementById('modalBg').classList.contains('on')) });

      /* -------- Mark as paid -------- */
      const target = w === 1280 ? '33420' : '33430';
      await openOrder(page, target);
      if (w === 1280) {
        // Escape first: the status must revert.
        await page.selectOption('#odStatusSel', 'completed');
        await page.click('#odUpdate');
        await page.waitForSelector('#modal #odMpGo');
        await page.keyboard.press('Escape');
        await page.waitForTimeout(300);
        log({ tag: TAG, width: w, escapeReverted: await page.evaluate(() => document.getElementById('odStatusSel').value),
          modalClosed: await page.evaluate(() => !document.getElementById('modalBg').classList.contains('on')) });
      }
      await page.selectOption('#odStatusSel', 'processing');
      await page.click('#odUpdate');
      await page.waitForSelector('#modal #odMpGo');
      await page.fill('#odMpRef', w === 1280 ? 'ZN-7781-2209-AE' : 'TMR-55120-993');
      await page.waitForTimeout(250);
      await page.screenshot({ path: `${OUT}/${TAG}-${w}-mark-paid-modal.png` });
      log({ tag: TAG, width: w, markPaidModal: await page.evaluate(() => ({
        title: document.querySelector('#modal .modal-h b').innerText,
        method: document.getElementById('odMpMethod').value,
        date: document.getElementById('odMpDate').value,
        options: [...document.querySelectorAll('#odMpMethod option')].map((o) => o.textContent),
        w: Math.round(document.getElementById('modal').getBoundingClientRect().width),
      })) });
      await page.click('#odMpGo');
      await page.waitForTimeout(1800);
      const after = await page.evaluate(() => ({
        modalOpen: document.getElementById('modalBg').classList.contains('on'),
        err: (document.getElementById('odMpErr') || {}).textContent,
        status: (document.getElementById('odStatusSel') || {}).value,
      }));
      log({ tag: TAG, width: w, markPaidResult: after, ...(await measure(page)) });
      if (after.modalOpen) { await page.screenshot({ path: `${OUT}/${TAG}-${w}-mark-paid-refused.png` }); await page.keyboard.press('Escape'); }
      await shotEl(page, '#odItems', `${TAG}-${w}-mark-paid-green`);
      await shotEl(page, '#odNotes', `${TAG}-${w}-mark-paid-note`);

      /* -------- COD: Record cash received -------- */
      if (w === 1280) {
        await openOrder(page, '33410');
        await page.click('#odRecordCash');
        await page.waitForSelector('#modal #odMpGo');
        await page.screenshot({ path: `${OUT}/${TAG}-${w}-record-cash-modal.png` });
        await page.click('#odMpGo');
        await page.waitForTimeout(1500);
        log({ tag: TAG, width: w, recordCash: await measure(page) });
        await shotEl(page, '#odItems', `${TAG}-${w}-record-cash-green`);
      }
    }
    log({ tag: TAG, width: w, pageErrors: errors });
    await ctx.close();
  }

  /* -------- support: sees Edit and history, not Change / Mark as paid -------- */
  if (AFTER) {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    const page = await ctx.newPage();
    await signIn(page, 'support');
    await openOrder(page, '33415');
    log({ tag: TAG, support: await page.evaluate(() => ({
      edit: document.querySelectorAll('[data-odedit]').length,
      change: !!document.getElementById('odCustChange'),
      recordCash: !!document.getElementById('odRecordCash'),
      history: !!document.getElementById('odCustHist'),
    })) });
    const r = await page.evaluate(async () => {
      const x = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
      const base = location.pathname.replace(/\/admin.*$/, '');
      const res = await fetch(base + '/admin-api/orders/1/mark-paid', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x },
        body: JSON.stringify({ payment_method: 'cod', paid_at: '2026-10-01T10:00' }) });
      return res.status;
    });
    log({ tag: TAG, supportMarkPaidStatus: r });
    await ctx.close();
  }

  await browser.close();
})();
