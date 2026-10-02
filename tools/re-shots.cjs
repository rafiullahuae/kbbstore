/*
 * Lane RE — "Buy these together": the four-card carousel and its peek, the
 * price line that never leaves its box, the struck total and the savings line,
 * and the bundle discount through the cart, the checkout and the order.
 *
 *   sh tools/re-preview.sh 9480                         # tiers off (shipped)
 *   RE_BASE=http://127.0.0.1:9480 node tools/re-shots.cjs before
 *   RE_TIERS=1 sh tools/re-preview.sh 9480              # tiers 5/10/15 %
 *   RE_BASE=http://127.0.0.1:9480 node tools/re-shots.cjs after
 *
 * Writes docs/re-shots/*.png and docs/re-shots/MEASUREMENTS-<mode>.json.
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, NEVER BY A SCRIPT THE SHOP SERVES.
 *   CLAUDE.md rule 4 forbids JavaScript that measures layout in the product;
 *   measuring the product from outside is how the claim gets checked.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RE_BASE || 'http://127.0.0.1:9480';
const OUT = path.join(__dirname, '..', 'docs', 're-shots');
const CHROME = process.env.RE_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const SUN = '/product/relief-sun-rice-probiotics-spf50/';
const MODE = process.argv[2] || 'after';
const M = {};

fs.mkdirSync(OUT, { recursive: true });

const WIDTHS = [320, 360, 390, 430, 768, 1280, 1440];

function phone(width) {
  return width < 1024
    ? { viewport: { width, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }
    : { viewport: { width, height: 900 }, deviceScaleFactor: 1 };
}

/** Every price and name box against its own card — the owner's complaint, measured. */
async function overflow(page) {
  return page.evaluate(() => {
    const cards = [...document.querySelectorAll('.bt-card')];
    const rows = cards.map((card, i) => {
      const c = card.getBoundingClientRect();
      const boxes = [card.querySelector('.nm'), card.querySelector('.pr'), ...card.querySelectorAll('.pr > *')].filter(Boolean);
      const worst = Math.max(...boxes.map((b) => b.getBoundingClientRect().right));
      const pr = card.querySelector('.pr');
      const nm = card.querySelector('.nm');
      return {
        i,
        cardRight: +c.right.toFixed(2),
        cardWidth: +c.width.toFixed(2),
        worstRight: +worst.toFixed(2),
        overflowPx: +(worst - (c.right - 1)).toFixed(2),
        priceFont: pr ? getComputedStyle(pr).fontSize : null,
        priceLines: pr ? Math.round(pr.getBoundingClientRect().height / parseFloat(getComputedStyle(pr).lineHeight || '12')) : null,
        priceScroll: pr ? [pr.scrollWidth, pr.clientWidth] : null,
        nameScroll: nm ? [nm.scrollWidth, nm.clientWidth] : null,
        price: pr ? pr.textContent.replace(/\s+/g, ' ').trim() : null,
      };
    });
    const rail = document.querySelector('.bt-rail');
    const r = rail ? rail.getBoundingClientRect() : null;
    const fullyVisible = r ? cards.filter((c) => { const b = c.getBoundingClientRect(); return b.left >= r.left - 0.5 && b.right <= r.right + 0.5; }).length : 0;
    const btn = document.querySelector('.bt-buy');
    return {
      viewport: innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      cards: rows,
      anyOverflow: rows.some((x) => x.overflowPx > 0 || (x.priceScroll && x.priceScroll[0] > x.priceScroll[1] + 0.5) || (x.nameScroll && x.nameScroll[0] > x.nameScroll[1] + 0.5)),
      cardsFullyVisible: fullyVisible,
      railScroll: rail ? [rail.scrollWidth, rail.clientWidth] : null,
      buttonShadow: btn ? getComputedStyle(btn).boxShadow : null,
      total: document.querySelector('.bt-total') ? document.querySelector('.bt-total').textContent.replace(/\s+/g, ' ').trim() : null,
      save: document.querySelector('.bt-save') && !document.querySelector('.bt-save').hidden ? document.querySelector('.bt-save').textContent.replace(/\s+/g, ' ').trim() : null,
    };
  });
}

async function open(browser, width, url = SUN, extra = {}) {
  const ctx = await browser.newContext({ ...phone(width), ...extra });
  const page = await ctx.newPage();
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  await page.addStyleTag({ content: '.kbb-fbt.bt::before{animation:none!important}' });
  return { ctx, page };
}

async function section(page, file) {
  const sec = await page.$('[data-bt]');
  await sec.scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
  await sec.screenshot({ path: path.join(OUT, file) });
}

async function login(browser) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return { ctx, page };
}

async function saveTogether(admin, together) {
  return admin.evaluate(async (t) => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const r = await fetch('/admin-api/product-page', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' },
      body: JSON.stringify({ together: t }) });
    return r.json();
  }, together);
}

async function before(browser) {
  for (const w of [360, 390]) {
    const { ctx, page } = await open(browser, w);
    await section(page, `before-cards-${w}.png`);
    M[`before-${w}`] = await overflow(page);
    await ctx.close();
  }
}

/** The cart, by the button itself. */
async function buyTogether(page) {
  await page.click('[data-bt-buy]');
  await page.waitForTimeout(2200);
}

async function after(browser) {
  // 1. cards at every width: nothing leaves its box.
  for (const w of WIDTHS) {
    const { ctx, page } = await open(browser, w);
    await (await page.$('[data-bt]')).scrollIntoViewIfNeeded();
    await page.waitForTimeout(3000); // the one-time peek runs on scroll; let it finish
    if ([320, 360, 390, 1280].includes(w)) await section(page, `after-cards-${w}.png`);
    M[`cards-${w}`] = await overflow(page);
    await ctx.close();
  }

  // 2. the peek, as a frame sequence at 390.
  {
    const { ctx, page } = await open(browser, 390);
    await page.evaluate(() => window.scrollTo(0, 0));
    const sec = await page.$('[data-bt]');
    const y = await sec.evaluate((s) => s.getBoundingClientRect().top + scrollY);
    await page.evaluate((top) => window.scrollTo(0, top - 120), y);
    const frames = [];
    const at = [60, 700, 1350, 2600];
    for (let i = 0; i < at.length; i++) {
      await page.waitForTimeout(at[i] - (i ? at[i - 1] : 0));
      await sec.screenshot({ path: path.join(OUT, `peek-frame-${i + 1}-390.png`) });
      frames.push(await page.evaluate(() => {
        const c = [...document.querySelectorAll('.bt-card')];
        return { at: performance.now() | 0, card1Margin: getComputedStyle(c[0]).marginInlineStart, card5Left: +c[4].getBoundingClientRect().left.toFixed(1), peeking: document.querySelector('[data-bt]').classList.contains('is-peek') };
      }));
    }
    M['peek-390'] = frames;
    await ctx.close();
  }

  // 2b. reduced motion: a static peek, no nudge.
  {
    const { ctx, page } = await open(browser, 390, SUN, { reducedMotion: 'reduce' });
    await page.waitForTimeout(1500);
    await section(page, `peek-reduced-motion-390.png`);
    M['reduced-motion-390'] = await page.evaluate(() => {
      const c = [...document.querySelectorAll('.bt-card')];
      const r = document.querySelector('.bt-rail').getBoundingClientRect();
      const five = c[4] ? c[4].getBoundingClientRect() : null;
      return { animationName: getComputedStyle(c[0]).animationName, fifthVisiblePx: five ? +(Math.min(five.right, r.right) - Math.max(five.left, r.left)).toFixed(2) : null, fifthWidth: five ? +five.width.toFixed(2) : null };
    });
    await ctx.close();
  }

  // 2c. Arabic: the row runs the other way.
  {
    const { ctx, page } = await open(browser, 390, '/ar' + SUN);
    await (await page.$('[data-bt]')).scrollIntoViewIfNeeded();
    await page.waitForTimeout(3000);
    await section(page, `after-cards-ar-390.png`);
    M['cards-ar-390'] = await overflow(page);
    await ctx.close();
  }

  if (process.env.RE_ONLY === 'cards') return;

  // 3. the total block at 3, 4 and 5 ticked (tiers 5 / 10 / 15 %).
  for (const w of [390, 1280]) {
    const { ctx, page } = await open(browser, w);
    await page.waitForTimeout(2600);
    for (const n of [5, 4, 3]) {
      if (n < 5) {
        await page.click(`.bt-card:nth-child(${n + 1}) .bt-cb`);
        await page.waitForTimeout(250);
      }
      const foot = await page.$('.bt-foot');
      await foot.scrollIntoViewIfNeeded();
      await foot.screenshot({ path: path.join(OUT, `total-${n}-ticked-${w}.png`) });
      M[`total-${n}-${w}`] = await page.evaluate(() => {
        const t = document.querySelector('.bt-total');
        const lab = t.querySelector('.bt-total-label');
        const val = t.querySelector('.bt-sum');
        const s = document.querySelector('.bt-save');
        return { text: t.textContent.replace(/\s+/g, ' ').trim(), save: s && !s.hidden ? s.textContent.replace(/\s+/g, ' ').trim() : null,
          labelRight: lab ? +lab.getBoundingClientRect().right.toFixed(2) : null, valueLeft: val ? +val.getBoundingClientRect().left.toFixed(2) : null,
          blockLeft: +t.getBoundingClientRect().left.toFixed(2), blockRight: +t.getBoundingClientRect().right.toFixed(2),
          saveFont: s ? getComputedStyle(s).fontSize : null, button: document.querySelector('.bt-buy').textContent.trim() };
      });
    }
    await ctx.close();
  }

  // 4. cart drawer, cart page, removal, checkout.
  for (const w of [390, 1280]) {
    const { ctx, page } = await open(browser, w);
    await page.waitForTimeout(2600);
    await buyTogether(page);
    await page.screenshot({ path: path.join(OUT, `drawer-group-${w}.png`) });
    M[`drawer-${w}`] = await page.evaluate(() => [...document.querySelectorAll('.kc-fragment .kc-item')].slice(0, 6).map((e) => e.textContent.replace(/\s+/g, ' ').trim()));

    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: '.cpg-rec::before{animation:none!important}' });
    await page.screenshot({ path: path.join(OUT, `cart-group-${w}.png`), fullPage: true });
    M[`cart-${w}`] = await page.evaluate(() => ({
      lines: [...document.querySelectorAll('.kbb-cartpage .items .ci')].map((e) => e.textContent.replace(/\s+/g, ' ').trim()),
      summary: [...document.querySelectorAll('.sum .srow, .sum .cpg-totband')].map((e) => e.textContent.replace(/\s+/g, ' ').trim()),
    }));

    // checkout with the group
    await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
    const sum = await page.$('.sumrow') ? await page.$$('.kbb-checkout .sumrow') : [];
    await page.screenshot({ path: path.join(OUT, `checkout-group-${w}.png`), fullPage: true });
    M[`checkout-${w}`] = await page.evaluate(() => [...document.querySelectorAll('.sumrow')].filter((e) => !e.hidden && e.offsetParent !== null).map((e) => e.textContent.replace(/\s+/g, ' ').trim()));

    // remove one member: the rest go back to normal price
    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: '.cpg-rec::before{animation:none!important}' });
    await page.click('.kbb-cartpage .items .ci:nth-child(2) [data-kcprm]');
    await page.waitForTimeout(1800);
    await page.screenshot({ path: path.join(OUT, `cart-after-remove-${w}.png`), fullPage: true });
    M[`cart-after-remove-${w}`] = await page.evaluate(() => ({
      lines: [...document.querySelectorAll('.kbb-cartpage .items .ci')].map((e) => e.textContent.replace(/\s+/g, ' ').trim()),
      summary: [...document.querySelectorAll('.sum .srow, .sum .cpg-totband')].map((e) => e.textContent.replace(/\s+/g, ' ').trim()),
    }));
    await ctx.close();
  }
}

async function coupons(browser, admin) {
  for (const [mode, on] of [['include', true], ['exclude', false]]) {
    await saveTogether(admin, { options: { coupons: on } });
    for (const w of [390, 1280]) {
      const { ctx, page } = await open(browser, w);
      await page.waitForTimeout(2600);
      await buyTogether(page);
      // one ordinary line too, so Exclude has something to discount
      const ids = await (await page.request.get(BASE + '/re-ids.json')).json();
      await page.evaluate(async (extra) => {
        const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        const csrf = (window.KBB && window.KBB.csrf) || '';
        await fetch('/api/cart/add', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' }, body: JSON.stringify({ product_id: extra }) });
      }, ids.extra);
      await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
      await page.evaluate(async () => {
        const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
        const csrf = (window.KBB && window.KBB.csrf) || '';
        await fetch('/api/cart/coupon', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' }, body: JSON.stringify({ code: 'GLOW10' }) });
      });
      await page.goto(BASE + '/checkout/', { waitUntil: 'networkidle' });
      await page.screenshot({ path: path.join(OUT, `checkout-coupon-${mode}-${w}.png`), fullPage: true });
      M[`checkout-coupon-${mode}-${w}`] = await page.evaluate(() => [...document.querySelectorAll('.sumrow')].filter((e) => !e.hidden && e.offsetParent !== null).map((e) => e.textContent.replace(/\s+/g, ' ').trim()));
      await ctx.close();
    }
  }
  await saveTogether(admin, { options: { coupons: true } });
}

async function adminTab(admin) {
  await admin.goto(`${BASE}/admin/#product-page`, { waitUntil: 'networkidle' }).catch(() => null);
  await admin.waitForTimeout(1500);
  await admin.evaluate(() => { if (typeof window.go === 'function') window.go('productpage'); });
  await admin.waitForTimeout(1500);
  const tab = await admin.$('[data-btptab]');
  if (tab) { await tab.click(); await admin.waitForTimeout(800); }
  const card = await admin.$('#btpTiers');
  if (card) {
    await card.scrollIntoViewIfNeeded();
    await card.screenshot({ path: path.join(OUT, 'admin-tiers-1280.png') });
    // move the 4-product slider and show the live preview follow it
    await admin.evaluate(() => { const r = document.querySelector('[data-btp-opt="tier_4"]'); if (r) { r.value = '20'; r.dispatchEvent(new Event('input', { bubbles: true })); } });
    await admin.waitForTimeout(300);
    await card.screenshot({ path: path.join(OUT, 'admin-tiers-live-1280.png') });
    M['admin-preview'] = await admin.evaluate(() => (document.getElementById('btpPreview') || {}).textContent || null);
  }
  await admin.screenshot({ path: path.join(OUT, 'admin-tab-1280.png'), fullPage: false });
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  try {
    if (MODE === 'before') {
      await before(browser);
    } else if (MODE === 'coupons') {
      const { page: admin } = await login(browser);
      await coupons(browser, admin);
    } else if (MODE === 'admin') {
      const { page: admin } = await login(browser);
      await adminTab(admin);
    } else {
      await after(browser);
    }
  } finally {
    await browser.close();
  }
  const file = path.join(OUT, `MEASUREMENTS-${MODE}${process.env.RE_ONLY ? "-" + process.env.RE_ONLY : ""}.json`);
  fs.writeFileSync(file, JSON.stringify(M, null, 2));
  console.log('wrote', file);
})();
