/*
 * Lane CD screenshots and measurements: the checkout's desktop totals card
 * ("totals above Place order") and the floating labels, before / after / off.
 *
 *   node tools/cd-shots.cjs <out-dir> <tag> [port] [ar]
 *
 * Every number is read in the browser after layout (a camera may measure; the
 * shop may not).
 */
const { chromium } = require('playwright');
const fs = require('fs');

const [out, tag, port = '8771', ar] = process.argv.slice(2);
const BASE = 'http://127.0.0.1:' + port;
const P = ar ? '/ar' : '';
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';
const CALM = `*,*::before,*::after{animation-play-state:paused!important;transition-duration:0s!important;caret-color:transparent!important}`;
const VIEWPORTS = ar ? [{ w: 390, h: 844 }] : [{ w: 390, h: 844 }, { w: 1280, h: 900 }];

const measure = () => {
  const r1 = (n) => Math.round(n * 10) / 10;
  const vis = (e) => !!e && e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
  const aside = document.getElementById('kbbSummary');
  const inAside = (s) => aside ? [...aside.querySelectorAll(s)].filter(vis) : [];
  const place = [...document.querySelectorAll('.place')].find(vis);
  const totRow = [...document.querySelectorAll('.sumrow.tot')].find(vis);
  const rowTot = [...document.querySelectorAll('#kbbSumRow .js-total, #kbbSumRow .js-total-fee')].find(vis);
  const card = [...document.querySelectorAll('.cotot')].find(vis);
  const items = aside ? aside.querySelector('.co-items') : null;
  return {
    asideHeight: aside ? r1(aside.getBoundingClientRect().height) : null,
    asideItemsVisible: vis(items),
    asideSumrowsVisible: inAside('.sumrow').map((e) => e.innerText.replace(/\s+/g, ' ').trim()),
    totalsCard: card ? { h: r1(card.getBoundingClientRect().height), bg: getComputedStyle(card).backgroundColor,
      gapToPlace: place ? r1(place.getBoundingClientRect().top - card.getBoundingClientRect().bottom) : null } : null,
    placeVisibleIn: place ? (place.closest('#kbbSummary') ? 'aside' : 'mobile box') : null,
    orderTotal: totRow ? totRow.innerText.replace(/\s+/g, ' ').trim() : null,
    rowTotal: rowTot ? rowTot.innerText.trim() : null,
    totalsInDom: document.querySelectorAll('.sumrow.tot').length,
    scrollWidth: document.documentElement.scrollWidth, clientWidth: document.documentElement.clientWidth,
    pageHeight: document.documentElement.scrollHeight,
  };
};

const fieldInfo = () => {
  const out = {};
  for (const id of ['billing_first_name', 'billing_phone', 'billing_email', 'billing_address_1', 'billing_state',
    'billing_city', 'billing_country', 'customer_note', 'kbb_coupon_code']) {
    const e = document.getElementById(id);
    if (!e) continue;
    const cs = getComputedStyle(e);
    const lab = document.querySelector('label[for="' + id + '"]');
    const lcs = lab ? getComputedStyle(lab) : null;
    out[id] = { h: Math.round(e.getBoundingClientRect().height), font: cs.fontSize, ph: e.getAttribute('placeholder'),
      label: lab ? lab.innerText.trim() : null, labelFont: lcs ? lcs.fontSize : null,
      labelInside: lab ? (() => { const a = lab.getBoundingClientRect(), b = e.getBoundingClientRect();
        return a.top >= b.top - 1 && a.bottom <= b.bottom + 1; })() : null };
  }
  return out;
};

// The summary is sticky, so an element shot of it after scrolling catches it
// under the header: scroll home and clip its box instead.
const shotAside = async (page, path) => {
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(100);
  const b = await page.locator('#kbbSummary').boundingBox();
  await page.screenshot({ path, clip: { x: b.x - 12, y: Math.max(0, b.y - 12), width: b.width + 24, height: b.height + 24 } });
};

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const ids = JSON.parse(await (await fetch(BASE + '/cd-ids.json')).text()).slice(0, 3);
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const report = {};

  for (const vp of VIEWPORTS) {
    for (const who of ar ? ['guest'] : ['guest', 'member']) {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, deviceScaleFactor: 2, userAgent: UA,
        hasTouch: vp.w < 900, isMobile: vp.w < 900 });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

      await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
      if (who === 'member') {
        report.login = await page.evaluate(async () => (await fetch('/my-account/login', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'text/html' },
          body: 'email=aisha%40preview.test&password=preview-secret-1' })).status);
        await page.goto(BASE + P + '/', { waitUntil: 'domcontentloaded' });
      }
      for (const id of ids) {
        await page.evaluate(async (pid) => (await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.KBB.csrf, Accept: 'application/json' },
          body: JSON.stringify({ product_id: pid, quantity: 1 }) })).status, id);
      }
      const key = `${tag}-${ar ? 'ar-' : ''}${who}-${vp.w}`;

      await page.goto(BASE + P + '/checkout/', { waitUntil: 'networkidle' });
      await page.addStyleTag({ content: CALM });
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${out}/${key}-1-plain.png`, fullPage: true });
      report[key + '-plain'] = await page.evaluate(measure);
      report[key + '-fields'] = await page.evaluate(fieldInfo);

      if (vp.w >= 900) {
        await page.click('#kbbSumRow').catch(() => {});
        await page.waitForTimeout(200);
        await shotAside(page, `${out}/${key}-2-summary-open.png`);
        report[key + '-open'] = await page.evaluate(measure);
        await page.click('#kbbSumRow').catch(() => {});
      }

      if (!ar) {
        // Coupon, then Cash on delivery, then the emirate: the three live paths.
        await page.fill('#kbb_coupon_code', 'CDTEN');
        await page.click('#kbb_apply_coupon');
        await page.waitForTimeout(1500);
        report[key + '-coupon'] = await page.evaluate(measure);
        const cod = page.locator('label[for="payment_method_cod"]');
        if (await cod.count()) { await cod.first().click(); await page.waitForTimeout(300); }
        report[key + '-coupon-cod'] = await page.evaluate(measure);
        await page.screenshot({ path: `${out}/${key}-3-coupon-cod.png`, fullPage: true });
        if (vp.w >= 900) await shotAside(page, `${out}/${key}-3-coupon-cod-summary.png`);
        else await page.locator('.kbb-mobile-order').screenshot({ path: `${out}/${key}-3-coupon-cod-box.png` });
        const typed = await page.evaluate(() => { const e = document.getElementById('billing_state'); return !!e && e.type === 'text'; });
        if (typed) {
          await page.fill('#billing_state', who === 'guest' ? 'Dubai' : 'Sharjah');
          await page.locator('#billing_city').focus();
          await page.waitForTimeout(1500);
          report[key + '-emirate'] = await page.evaluate(measure);
        }
        // A quantity change replaces the order block wholesale.
        const plus = page.locator('#kbbSummary .co-items .co-q[data-d="1"]').first();
        if (vp.w >= 900 && await plus.count()) {
          if (!(await plus.isVisible())) await page.click('#kbbSumRow').catch(() => {});
          await plus.click(); await page.waitForTimeout(1500);
          report[key + '-qty'] = await page.evaluate(measure);
        }
      }

      if (who === 'guest') {
        await page.goto(BASE + P + '/cart/', { waitUntil: 'networkidle' });
        await page.addStyleTag({ content: CALM });
        const cc = page.locator('.kbb-cartpage .coupon').first();
        if (await cc.count()) {
          await cc.screenshot({ path: `${out}/${key}-c1-cart-coupon.png` });
          await page.locator('#kbbCartCoupon').focus();
          await page.keyboard.type('CDTEN');
          await cc.screenshot({ path: `${out}/${key}-c2-cart-coupon-filled.png` });
          report[key + '-cart'] = await page.evaluate(() => ({ h: Math.round(document.getElementById('kbbCartCoupon').getBoundingClientRect().height),
            font: getComputedStyle(document.getElementById('kbbCartCoupon')).fontSize, touch: matchMedia('(hover:none),(pointer:coarse)').matches, scrollWidth: document.documentElement.scrollWidth }));
        }
        // Field states, on a fresh load so nothing above is filled in.
        await page.goto(BASE + P + '/checkout/', { waitUntil: 'networkidle' });
        await page.addStyleTag({ content: CALM });
        const contact = page.locator('.sec').first();
        await contact.evaluate((e) => e.scrollIntoView({ block: 'start' }));
        await contact.screenshot({ path: `${out}/${key}-4-fields-empty.png` });
        await page.locator('#billing_first_name').focus();
        await page.waitForTimeout(150);
        await contact.screenshot({ path: `${out}/${key}-5-fields-focused.png` });
        report[key + '-focused'] = await page.evaluate(fieldInfo);
        await page.fill('#billing_first_name', 'Aisha Khan');
        await page.fill('#billing_phone', '0501234567');
        await page.locator('#billing_email').focus();
        await page.keyboard.type('aisha@');
        await page.locator('#billing_phone').focus();
        await page.waitForTimeout(150);
        await contact.screenshot({ path: `${out}/${key}-6-fields-filled-error.png` });
        report[key + '-filled'] = await page.evaluate(fieldInfo);
        const ship = page.locator('.sec').nth(1);
        await ship.screenshot({ path: `${out}/${key}-7-address.png` });
        await page.locator('.coupon').first().screenshot({ path: `${out}/${key}-8-coupon.png` });
        const note = page.locator('#customer_note_field');
        if (await note.count()) await note.screenshot({ path: `${out}/${key}-9-notes.png` });
      }
      report[key + '-errors'] = errors;
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(`${out}/${tag}${ar ? '-ar' : ''}-report.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 1).slice(0, 6000));
})();
