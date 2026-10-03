/*
 * Lane RH — "Show the total and buy-together discount": the master switch
 * over the Buy these together total row and its discount, OFF by default.
 *
 *   RE_TIERS=1 sh tools/re-preview.sh 9620     # tiers 5/10/15 % stored, switch never saved
 *   RH_BASE=http://127.0.0.1:<port> node tools/rh-shots.cjs
 *
 * Writes docs/rh-shots/*.png and docs/rh-shots/MEASUREMENTS.json, in this
 * order, against the one preview:
 *
 *   1. OFF (the shipped state): the section at 390 and 1280 — no row; the
 *      button pressed, and the cart it lands in — no buy-together tag or row;
 *   2. the admin card OFF (locked), then the master switch clicked (unlocked);
 *   3. ON, saved: the section at 390 and 1280 with the row;
 *   4. ON with "Show on phones" off: the section at 390 (no row) and 1280 (row).
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, NEVER BY A SCRIPT THE SHOP SERVES
 *   (CLAUDE.md rule 4).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RH_BASE || 'http://127.0.0.1:9620';
const OUT = path.join(__dirname, '..', 'docs', 'rh-shots');
const CHROME = process.env.RH_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const SUN = '/product/relief-sun-rice-probiotics-spf50/';
const M = {};

fs.mkdirSync(OUT, { recursive: true });

function device(width) {
  return width < 1024
    ? { viewport: { width, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }
    : { viewport: { width, height: 900 }, deviceScaleFactor: 1 };
}

async function open(browser, width, url = SUN) {
  const ctx = await browser.newContext(device(width));
  const page = await ctx.newPage();
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  await page.addStyleTag({ content: '.kbb-fbt.bt::before{animation:none!important}' });
  return { ctx, page };
}

/** The section's numbers: is the row drawn, is it shown, what it says. */
async function measure(page) {
  return page.evaluate(() => {
    const sec = document.querySelector('[data-bt]');
    const row = document.querySelector('.bt-sumrow');
    const foot = document.querySelector('.bt-foot');
    const btn = document.querySelector('.bt-buy');
    const r = (el) => (el ? +el.getBoundingClientRect().height.toFixed(2) : null);
    return {
      viewport: innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      sectionHeight: r(sec),
      footHeight: r(foot),
      rowInHtml: !!row,
      rowClass: row ? row.className : null,
      rowDisplay: row ? getComputedStyle(row).display : null,
      rowHeight: r(row),
      total: row ? (row.querySelector('.bt-total') || {}).textContent?.replace(/\s+/g, ' ').trim() : null,
      saving: row && row.querySelector('.bt-save') && !row.querySelector('.bt-save').hidden ? row.querySelector('.bt-save').textContent.replace(/\s+/g, ' ').trim() : null,
      button: btn ? btn.textContent.replace(/\s+/g, ' ').trim() : null,
      buttonHeight: r(btn),
      tiers: sec ? sec.dataset.tiers : null,
    };
  });
}

async function section(page, file) {
  const sec = await page.$('[data-bt]');
  await sec.scrollIntoViewIfNeeded();
  await page.waitForTimeout(2600); // the one-time peek runs on scroll; let it settle
  await sec.screenshot({ path: path.join(OUT, file) });
}

async function login(browser, width) {
  // Tall, so the whole discount card fits under the console's sticky header
  // and above its sticky Save bar -- an element shot of a card taller than
  // the window is drawn with both bars over it.
  const ctx = await browser.newContext({ viewport: { width, height: 3200 }, deviceScaleFactor: width < 1024 ? 2 : 1 });
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

async function openTab(admin) {
  await admin.goto(`${BASE}/admin/#product-page`, { waitUntil: 'networkidle' }).catch(() => null);
  await admin.waitForTimeout(1500);
  await admin.evaluate(() => { if (typeof window.go === 'function') window.go('productpage'); });
  await admin.waitForTimeout(1500);
  const tab = await admin.$('[data-btptab]');
  if (tab) { await tab.click(); await admin.waitForTimeout(800); }
}

async function adminCard(admin) {
  return admin.evaluate(() => {
    const card = document.getElementById('btpTiers');
    if (!card) return null;
    const lock = card.querySelector('[data-btp-lock]');
    const master = card.querySelector('.ectog[data-btp-opt="discount_on"]');
    return {
      masterChecked: master ? master.getAttribute('aria-checked') : null,
      note: (card.querySelector('.btp-lock-note') || {}).textContent || null,
      lockAriaDisabled: lock ? lock.getAttribute('aria-disabled') : null,
      lockOpacity: lock ? getComputedStyle(lock).opacity : null,
      lockPointerEvents: lock ? getComputedStyle(lock).pointerEvents : null,
      sliders: [...card.querySelectorAll('input[type=range]')].map((i) => ({ key: i.dataset.btpOpt, value: i.value, disabled: i.disabled })),
      switches: [...card.querySelectorAll('.ectog[data-btp-opt]')].map((t) => ({ key: t.dataset.btpOpt, on: t.getAttribute('aria-checked'), ariaDisabled: t.getAttribute('aria-disabled'), tabindex: t.getAttribute('tabindex') })),
      previewOpacity: getComputedStyle(card.querySelector('.btp-prev')).opacity,
      cardHeight: +card.getBoundingClientRect().height.toFixed(2),
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  try {
    // 1. OFF, as shipped: no row, and the button adds at normal prices.
    for (const w of [390, 1280]) {
      const { ctx, page } = await open(browser, w);
      await section(page, `off-section-${w}.png`);
      M[`off-section-${w}`] = await measure(page);
      await page.click('[data-bt-buy]');
      await page.waitForTimeout(2200);
      await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
      await page.screenshot({ path: path.join(OUT, `off-cart-${w}.png`), fullPage: true });
      M[`off-cart-${w}`] = await page.evaluate(() => ({
        lines: document.querySelectorAll('.kbb-cartpage .items .ci').length,
        boughtTogetherTags: document.querySelectorAll('.cbt').length,
        bundleRow: document.body.textContent.includes('Buy-together discount'),
        summary: (document.querySelector('.kbb-cartpage .sum, .kbb-cartpage .summary, .kbb-cartpage aside') || {}).textContent?.replace(/\s+/g, ' ').trim().slice(0, 240) || null,
        scrollWidth: document.documentElement.scrollWidth,
      }));
      await ctx.close();
    }

    // 2. The admin card: locked while off, unlocked by the master switch.
    for (const w of [390, 1280]) {
      const { ctx, page: admin } = await login(browser, w);
      await openTab(admin);
      const card = await admin.$('#btpTiers');
      await card.scrollIntoViewIfNeeded();
      await card.screenshot({ path: path.join(OUT, `admin-off-locked-${w}.png`) });
      M[`admin-off-${w}`] = await adminCard(admin);
      // A click on a locked slider's switch does nothing.
      await admin.evaluate(() => { const t = document.querySelector('.ectog[data-btp-opt="coupons"]'); if (t) t.click(); });
      M[`admin-off-${w}`].couponsAfterClickOnLocked = await admin.evaluate(() => document.querySelector('.ectog[data-btp-opt="coupons"]').getAttribute('aria-checked'));
      await admin.click('.ectog[data-btp-opt="discount_on"]');
      await admin.waitForTimeout(300);
      const card2 = await admin.$('#btpTiers');
      await card2.screenshot({ path: path.join(OUT, `admin-on-unlocked-${w}.png`) });
      M[`admin-on-${w}`] = await adminCard(admin);
      if (w === 1280) {
        // Save it the way the console does.
        await admin.click('#ppSave');
        await admin.waitForTimeout(1500);
        M['admin-saved'] = await admin.evaluate(() => (document.getElementById('ppDirty') || {}).textContent || null);
      }
      await ctx.close();
    }

    // 3. ON: the row on both devices.
    for (const w of [390, 1280]) {
      const { ctx, page } = await open(browser, w);
      await section(page, `on-section-${w}.png`);
      M[`on-section-${w}`] = await measure(page);
      await ctx.close();
    }

    // 4. ON, row hidden on phones only.
    {
      const { ctx, page: admin } = await login(browser, 1280);
      M['save-phone-off'] = (await saveTogether(admin, { options: { row_phone: false } })).ok;
      await ctx.close();
    }
    for (const w of [390, 1280]) {
      const { ctx, page } = await open(browser, w);
      await section(page, `on-phone-hidden-${w}.png`);
      M[`on-phone-hidden-${w}`] = await measure(page);
      await ctx.close();
    }
    {
      const { ctx, page: admin } = await login(browser, 1280);
      await saveTogether(admin, { options: { row_phone: true } });
      await ctx.close();
    }
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS.json'), JSON.stringify(M, null, 2));
  console.log(JSON.stringify(M, null, 2));
})();
