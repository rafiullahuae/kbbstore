/*
 * Lane RB — "Buy these together", photographed and measured in Chromium at
 * 390 and 1280.
 *
 *   sh tools/rb-preview.sh 9400                     # boots tools/rb-seed.php
 *   RB_BASE=http://127.0.0.1:9400 node tools/rb-shots.cjs
 *
 * Writes docs/rb-shots/*.png, docs/rb-shots/overview.png and
 * docs/rb-shots/MEASUREMENTS.json.
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, NEVER BY A SCRIPT THE SHOP SERVES.
 *   CLAUDE.md rule 4 forbids JavaScript that measures layout in the product;
 *   measuring the product from outside is how the claim gets checked.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.RB_BASE || 'http://127.0.0.1:9400';
const OUT = path.join(__dirname, '..', 'docs', 'rb-shots');
const CHROME = process.env.RB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const SUN = '/product/relief-sun-rice-probiotics-spf50/';
const M = {};

fs.mkdirSync(OUT, { recursive: true });

async function login(ctx) {
  const page = await ctx.newPage();
  await page.setViewportSize({ width: 1280, height: 900 });
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

async function save(admin, together) {
  return admin.evaluate(async (t) => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const r = await fetch('/admin-api/product-page', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' },
      body: JSON.stringify({ together: t }) });
    return r.json();
  }, together);
}

/** The section's geometry, read from outside. */
async function measure(page) {
  return page.evaluate(() => {
    const r = (el) => { if (!el) return null; const b = el.getBoundingClientRect(); return { x: +b.left.toFixed(2), y: +(b.top + scrollY).toFixed(2), w: +b.width.toFixed(2), h: +b.height.toFixed(2) }; };
    const sec = document.querySelector('[data-bt]');
    const cards = [...document.querySelectorAll('.bt-card')];
    const ims = cards.map((c) => c.querySelector('.im'));
    const pluses = [...document.querySelectorAll('.bt-plus')];
    const ticks = [...document.querySelectorAll('.bt-tick')];
    const btn = document.querySelector('.bt-buy');
    const out = {
      viewport: innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      section: r(sec),
      cards: cards.map(r),
      cardHeights: [...new Set(cards.map((c) => +c.getBoundingClientRect().height.toFixed(2)))],
      pictures: ims.map(r),
      ticks: ticks.map(r),
      tickColours: ticks.map((t) => getComputedStyle(t).backgroundColor),
      pluses: pluses.map(r),
      button: r(btn),
      buttonText: btn ? btn.textContent.trim() : null,
      buttonBorder: btn ? getComputedStyle(btn).borderColor + ' ' + getComputedStyle(btn).borderWidth : null,
      buttonRadius: btn ? getComputedStyle(btn).borderRadius : null,
      total: document.querySelector('.bt-total') ? document.querySelector('.bt-total').textContent.replace(/\s+/g, ' ').trim() : null,
      names: cards.map((c) => c.querySelector('.nm').textContent.trim()),
      nameFont: cards[0] ? getComputedStyle(cards[0].querySelector('.nm')).fontSize : null,
      railScroll: (() => { const x = document.querySelector('.bt-rail'); return x ? { scrollWidth: x.scrollWidth, clientWidth: x.clientWidth } : null; })(),
    };
    // Each "+" centred on the gap between two cards, at the pictures' middle.
    out.plusCentres = pluses.map((p, i) => {
      const pb = p.getBoundingClientRect(), a = cards[i].getBoundingClientRect(), b = cards[i + 1].getBoundingClientRect(), im = ims[i + 1].getBoundingClientRect();
      return { plusX: +((pb.left + pb.right) / 2).toFixed(2), gapX: +((a.right + b.left) / 2).toFixed(2), plusY: +((pb.top + pb.bottom) / 2).toFixed(2), pictureY: +((im.top + im.bottom) / 2).toFixed(2) };
    });
    return out;
  });
}

async function sectionShot(page, file) {
  const sec = await page.$('[data-bt]');
  await sec.scrollIntoViewIfNeeded();
  await page.waitForTimeout(250);
  await sec.screenshot({ path: path.join(OUT, file) });
}

async function productPage(ctx, width, url = SUN) {
  const page = await ctx.newPage();
  await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  // The colour wash drifts; hold it still so two shots of one state match.
  await page.addStyleTag({ content: '.kbb-fbt.bt::before{animation:none!important}' });
  return page;
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ deviceScaleFactor: 2 });
  const admin = await login(ctx);

  // As it ships on his shop: on, four products, best sellers.
  await save(admin, { options: { on: true, count: 4, rule: 'best', hide_oos: true, same_brand: false, show_total: true, title: '', title_ar: '' }, phone: true, laptop: true });

  for (const w of [390, 1280]) {
    // 1. the section as it ships
    let page = await productPage(ctx, w);
    await sectionShot(page, `01-default-${w}.png`);
    M[`default-${w}`] = await measure(page);
    await page.screenshot({ path: path.join(OUT, `01-default-page-${w}.png`), fullPage: false, clip: undefined });

    // 2. one tick cleared: "Buy 3 items together"
    await page.click('.bt-card:nth-child(3) .bt-cb');
    await page.waitForTimeout(250);
    await sectionShot(page, `02-unchecked-${w}.png`);
    M[`unchecked-${w}`] = await measure(page);

    // 3. the button: every ticked product, one request, the cart panel open
    await page.click('[data-bt-buy]');
    await page.waitForTimeout(2200);
    await page.screenshot({ path: path.join(OUT, `03-added-${w}.png`) });
    M[`added-${w}`] = await page.evaluate(() => ({
      badge: (document.getElementById('cartCt') || {}).textContent || null,
      tabBadge: (document.getElementById('tabCartCt') || {}).textContent || null,
      panelLines: [...document.querySelectorAll('#cart .kc-item .kc-nm, #cart .kc-item .kc-name, #cart .kc-item a')].map((e) => e.textContent.trim()).filter(Boolean).slice(0, 8),
      buttonText: document.querySelector('.bt-buy') ? document.querySelector('.bt-buy').textContent.trim() : null,
      buttonDisabled: document.querySelector('.bt-buy') ? document.querySelector('.bt-buy').disabled : null,
    }));
    await page.close();

    // 4. the cart page's Recommended rail, at the same width, for the card size
    page = await ctx.newPage();
    await page.setViewportSize({ width: w, height: w < 600 ? 844 : 900 });
    await page.goto(BASE + '/cart/', { waitUntil: 'networkidle' });
    await page.addStyleTag({ content: '.cpg-rec::before{animation:none!important}' });
    const rec = await page.$('.cpg-rec');
    if (rec) {
      await rec.scrollIntoViewIfNeeded();
      await rec.screenshot({ path: path.join(OUT, `04-cart-rail-${w}.png`) });
    }
    M[`cart-rail-${w}`] = await page.evaluate(() => {
      const c = [...document.querySelectorAll('.cpg-card')].slice(0, 4).map((e) => { const b = e.getBoundingClientRect(); return { w: +b.width.toFixed(2), h: +b.height.toFixed(2) }; });
      const im = document.querySelector('.cpg-card .im');
      const nm = document.querySelector('.cpg-card .nm');
      return { viewport: innerWidth, scrollWidth: document.documentElement.scrollWidth, cards: c,
        picture: im ? +im.getBoundingClientRect().width.toFixed(2) : null, nameFont: nm ? getComputedStyle(nm).fontSize : null };
    });
    // empty the bag for the next width
    await page.evaluate(async () => {
      for (let i = 0; i < 12; i++) {
        const b = document.querySelector('[data-kcprm]');
        if (!b) break;
        b.click();
        await new Promise((r) => setTimeout(r, 700));
      }
    });
    await page.close();
  }

  // 5. a sunscreen at five products: moisturiser, toner, cleansing oil, mask
  await save(admin, { options: { count: 5 } });
  for (const w of [390, 1280]) {
    const page = await productPage(ctx, w);
    await sectionShot(page, `05-sunscreen-five-${w}.png`);
    M[`sunscreen-five-${w}`] = await measure(page);
    await page.close();
  }
  await save(admin, { options: { count: 4 } });

  // 6. the admin tab
  for (const w of [1280, 390]) {
    await admin.setViewportSize({ width: w, height: w < 600 ? 844 : 900 });
    await admin.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
    await admin.evaluate(() => window.go('productpage'));
    await admin.waitForTimeout(1500);
    const tab = await admin.$('[data-btptab]');
    if (tab) {
      await tab.click();
      await admin.waitForTimeout(600);
      await admin.screenshot({ path: path.join(OUT, `06-admin-top-${w}.png`) });
      // Open one category's custom pairs, so the editor is in the picture.
      await admin.fill('[data-btp-find]', 'Sun');
      await admin.waitForTimeout(200);
      const b = await admin.$('[data-btp-mode]');
      if (b) { await b.click(); await admin.waitForTimeout(200); }
      await admin.screenshot({ path: path.join(OUT, `06-admin-${w}.png`), fullPage: true });
      M[`admin-${w}`] = await admin.evaluate(() => ({ tabs: [...document.querySelectorAll('#ppStrip .ectab')].map((t) => t.textContent.trim()), scrollWidth: document.documentElement.scrollWidth, viewport: innerWidth }));
    }
  }

  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS.json'), JSON.stringify(M, null, 2));

  // overview.png — the set on one sheet
  const sheet = await ctx.newPage();
  await sheet.setViewportSize({ width: 1600, height: 1000 });
  const img = (f) => `file://${path.join(OUT, f)}`;
  const tile = (f, cap, wpx) => fs.existsSync(path.join(OUT, f)) ? `<figure><img src="${img(f)}" style="width:${wpx}px"><figcaption>${cap}</figcaption></figure>` : '';
  await sheet.setContent(`<html><body style="font:14px system-ui;margin:24px;background:#f6f7f9">
    <h1 style="font-size:22px;margin:0 0 6px">Lane RB — Buy these together</h1>
    <p style="margin:0 0 18px;color:#555">Product page section (phone 390 / laptop 1280), the cart rail it copies, and the admin tab.</p>
    <style>figure{display:inline-block;vertical-align:top;margin:0 18px 18px 0;background:#fff;padding:10px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.08)}figcaption{font-size:12px;color:#444;margin-top:6px}img{display:block}</style>
    <div>${tile('01-default-390.png', '390 · as it ships — four ticked, “Buy 4 items together”', 360)}${tile('02-unchecked-390.png', '390 · one cleared → “Buy 3 items together”', 360)}${tile('04-cart-rail-390.png', '390 · the cart page rail it copies', 360)}${tile('03-added-390.png', '390 · pressed: the cart panel, three added', 230)}</div>
    <div>${tile('01-default-1280.png', '1280 · as it ships', 760)}${tile('05-sunscreen-five-390.png', '390 · five: moisturiser, toner, cleansing oil, mask', 360)}</div>
    <div>${tile('02-unchecked-1280.png', '1280 · one cleared', 760)}${tile('04-cart-rail-1280.png', '1280 · the cart rail', 560)}</div>
    <div>${tile('05-sunscreen-five-1280.png', '1280 · a sunscreen at five', 760)}${tile('03-added-1280.png', '1280 · pressed: the cart panel', 560)}</div>
    <div>${tile('06-admin-top-1280.png', 'Appearance → Product page → Buy these together', 900)}${tile('06-admin-1280.png', '… its Category pairs, Sunscreens customised', 900)}${tile('06-admin-390.png', 'the tab at 390', 300)}</div>
  </body></html>`, { waitUntil: 'load' });
  await sheet.screenshot({ path: path.join(OUT, 'overview.png'), fullPage: true });

  await browser.close();
  console.log(JSON.stringify(M, null, 1).slice(0, 6000));
})();
