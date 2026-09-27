/*
 * Lane CP screenshots and measurements.
 *
 *   node tools/cp-shots.cjs <out-dir> <width> <height> [squeeze]
 *
 * Shoots, at one real viewport, in this order:
 *
 *   1. the storefront cart panel, open, with four lines in it;
 *   2. Appearance -> Cart panel -> Desktop;
 *   3. Appearance -> Cart panel -> Mobile.
 *
 * With `squeeze`, it first POSTs a squeezed MOBILE set through the admin
 * endpoint and leaves every desktop value alone — which is the claim the whole
 * lane rests on, so it is the thing the pictures have to show.
 *
 * Every number printed is read with getComputedStyle IN THE BROWSER, which is a
 * measurement of the finished page and not of the source. CLAUDE.md forbids the
 * SHOP from measuring its own layout in JavaScript; a camera is allowed to.
 */
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8957';

const SQUEEZE = {
  // Mobile only. Every desktop key is left where it ships.
  list_pad_m: 5, row_pad_m: 3, name_size_m: 10, name_lines_m: 1,
  price_size_m: 85, stepper_size_m: 16, thumb_size_m: 28,
  rm_size_m: 10, rm_tap_m: 30, x_size_m: 30, x_glyph_m: 10, tab_h_m: 32,
  btn_pad_m: 4, btn_h_m: 32, btn_size_m: 80, btn_gap_m: 3, btn_radius_m: 8,
  panel_width_m: 88,
};

const px = (v) => (v === null || v === undefined ? null : Math.round(parseFloat(v) * 100) / 100);

(async () => {
  const [out, w, h, squeeze] = process.argv.slice(2);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const tag = squeeze ? 'after' : 'before';
  const report = { viewport: +w, state: tag };

  /* ---------------------------------------------------------------- admin */
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);

  if (squeeze) {
    const res = await page.evaluate(async (body) => {
      const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)');
      const r = await fetch('/admin-api/cart-panel', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                   'X-XSRF-TOKEN': m ? decodeURIComponent(m.pop()) : '' },
        body: JSON.stringify({ settings: body }),
      });
      return { status: r.status, body: await r.text() };
    }, SQUEEZE);
    report.squeezeSave = res;
    await page.waitForTimeout(400);
  }

  for (const tab of ['desktop', 'mobile']) {
    await page.evaluate(() => window.go('cartpanel'));
    await page.waitForTimeout(1400);
    await page.click(`[data-cpp-tab="${tab}"]`);
    await page.waitForTimeout(700);

    report['admin_' + tab] = await page.evaluate((t) => {
      const cs = (sel, p) => { const e = document.querySelector(sel); return e ? getComputedStyle(e)[p] : null; };
      return {
        heading: (document.querySelector('#ptitle') || {}).textContent,
        tabs: [...document.querySelectorAll('[data-cpp-tab]')].map((b) => b.textContent),
        openTab: (document.querySelector('[data-cpp-tab][aria-selected="true"]') || {}).textContent,
        controls: document.querySelectorAll('[data-cpp-key]').length,
        warnings: [...document.querySelectorAll('.cpp-warn')].map((p) => p.textContent.slice(0, 60)),
        previewColumns: cs('.cpp-wrap', 'gridTemplateColumns'),
        previewSticky: cs('[data-cpp-preview]', 'position'),
        previewIsSide: !!document.querySelector('.cpp-wrap.cpp-side'),
        ruler: (document.querySelector('.cpv-ruler') || {}).textContent,
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
        contentScrollWidth: (document.querySelector('#content') || {}).scrollWidth,
      };
    }, tab);

    await page.screenshot({ path: `${out}/admin-${tab}-${w}-${tag}.png`, fullPage: true });
  }

  /* ----------------------------------------------------------- storefront */
  await page.goto(BASE + '/shop', { waitUntil: 'networkidle' });
  /* FROM THE SHOP PAGE'S OWN ADD BUTTONS, not from /api/products — that endpoint
     allowlists what it returns and `id` is deliberately not on the list, which is
     the right answer for an unauthenticated endpoint and the reason the first
     draft of this script put four nulls in the basket. */
  const ids = await page.evaluate(() =>
    [...document.querySelectorAll('[data-kbb-add]')]
      .map((b) => Number(b.getAttribute('data-kbb-add')))
      .filter((n) => Number.isFinite(n) && n > 0)
      .slice(0, 4));
  report.addedProductIds = ids;

  report.addResults = await page.evaluate(async (list) => {
    const m = document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)');
    const token = m ? decodeURIComponent(m.pop()) : '';
    const out = [];
    for (const id of list) {
      const r = await fetch('/api/cart/add', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
        body: JSON.stringify({ product_id: id, quantity: 2 }),
      });
      out.push({ id, status: r.status, body: (await r.text()).slice(0, 160) });
    }
    return out;
  }, ids);

  await page.goto(BASE + '/shop', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);

  // Open the drawer the way a shopper does, then fall back to the class the
  // script adds — a screenshot of a shut panel is a screenshot of nothing.
  await page.evaluate(() => {
    const b = document.querySelector('[data-kbb-cart], [data-go-cart], .cartbtn, #cartBtn');
    if (b) b.click();
  });
  await page.waitForTimeout(500);
  await page.evaluate(() => {
    const d = document.querySelector('#cart');
    if (d && !d.classList.contains('on')) d.classList.add('on');
    const ov = document.querySelector('#ov');
    if (ov) ov.classList.add('on');
  });
  await page.waitForTimeout(700);

  report.panel = await page.evaluate(() => {
    const n = (v) => (v == null ? null : Math.round(parseFloat(v) * 100) / 100);
    const box = (sel) => {
      const e = document.querySelector(sel);
      if (!e) return null;
      const r = e.getBoundingClientRect();
      return { w: Math.round(r.width * 100) / 100, h: Math.round(r.height * 100) / 100 };
    };
    const cs = (sel, p) => { const e = document.querySelector(sel); return e ? getComputedStyle(e)[p] : null; };

    return {
      panelWidth: box('#cart') && box('#cart').w,
      rows: document.querySelectorAll('#cart .kc-item').length,
      rowHeight: box('#cart .kc-item') && box('#cart .kc-item').h,
      rowPadTop: n(cs('#cart .kc-item', 'paddingTop')),
      listPadInline: n(cs('#cart .dbody', 'paddingLeft')),
      thumb: box('#cart .kc-th'),
      nameSize: n(cs('#cart .kc-nm', 'fontSize')),
      nameClamp: cs('#cart .kc-nm', 'webkitLineClamp'),
      priceSize: n(cs('#cart .kc-pr', 'fontSize')),
      stepperBox: box('#cart .kc-qty button'),
      stepperNumber: box('#cart .kc-qty span'),
      removeBox: box('#cart .kc-rm'),
      removeGlyph: n(cs('#cart .kc-rm', 'fontSize')),
      closeBox: box('#cart .kc-x'),
      closeGlyph: n(cs('#cart .kc-x', 'fontSize')),
      tabHeight: box('#cart .kc-tab') && box('#cart .kc-tab').h,
      buttonBox: box('#cart .kc-btns a'),
      buttonLabel: n(cs('#cart .kc-btns a', 'fontSize')),
      buttonRadius: cs('#cart .kc-btns a', 'borderTopLeftRadius'),
      buttonsColumns: cs('#cart .kc-btns', 'gridTemplateColumns'),
      buttonsGap: n(cs('#cart .kc-btns', 'columnGap')),
      panelClass: (document.querySelector('#cart') || {}).className,
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    };
  });

  await page.screenshot({ path: `${out}/panel-${w}-${tag}.png` });

  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
