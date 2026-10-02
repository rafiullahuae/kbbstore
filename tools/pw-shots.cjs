/*
 * Lane PW — the delivery box, "Authenticity Guaranteed" and the share bar,
 * photographed and measured at 390 and 1280 in Chromium.
 *
 *   sh tools/pw-preview.sh 9600          # boots the fixture (tools/pw-seed.php)
 *   PW_BASE=http://127.0.0.1:9600 node tools/pw-shots.cjs
 *
 * Writes docs/pw-shots/*.png and docs/pw-shots/MEASUREMENTS.json.
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, NEVER BY A SCRIPT THE SHOP SERVES.
 *   CLAUDE.md rule 4 forbids JavaScript that measures layout in the product;
 *   measuring the product from outside is how the claim gets checked.
 *
 * "BEFORE" is the same page with the three switches off, set through the real
 * endpoint (POST /admin-api/product-page {trust:{…}}). ProductTrustShareTest
 * pins that a page with all three off carries no pts markup and no extra
 * stylesheet, i.e. is the page as it was.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PW_BASE || 'http://127.0.0.1:9600';
const OUT = path.join(__dirname, '..', 'docs', 'pw-shots');
const CHROME = process.env.PW_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const SLUG = 'pw-heartleaf-toner';
const M = {};

fs.mkdirSync(OUT, { recursive: true });

async function login(ctx) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

async function post(admin, trust) {
  return admin.evaluate(async (body) => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const r = await fetch('/admin-api/product-page', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' },
      body: JSON.stringify({ trust: body }) });
    return r.json();
  }, trust);
}

/** Every number the report quotes, read off the live page. */
async function measure(page) {
  return page.evaluate(() => {
    const r = (el) => el ? el.getBoundingClientRect() : null;
    const q = (s) => document.querySelector(s);
    const fs = (el) => el ? getComputedStyle(el).fontSize : null;
    const prev = (el) => { let p = el && el.previousElementSibling; while (p && getComputedStyle(p).display === 'none') p = p.previousElementSibling; return p; };
    const del = q('.pts-del'), buy = q('.buyrow'), auth = q('.pts-auth'), share = q('.pts-share'), btn = q('.pts-auth-btn');
    const out = {
      viewport: window.innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
    };
    if (del) {
      const p = prev(del);
      out.delivery = {
        width: Math.round(r(del).width), height: Math.round(r(del).height),
        gapAbove: Math.round(r(del).top - r(p).bottom), gapAboveFrom: p.className,
        gapBelowToBuyRow: Math.round(r(buy).top - r(del).bottom),
        textSize: fs(q('.pts-del-text')), logoWidth: Math.round(r(q('.pts-del-logo')).width),
        background: getComputedStyle(del).backgroundColor, radius: getComputedStyle(del).borderRadius,
      };
    }
    if (auth) {
      out.authenticity = {
        gapBelowButtonRow: Math.round(r(auth).top - r(buy).bottom),
        lineHeight: Math.round(r(btn).height), labelSize: fs(q('.pts-auth-label')), labelWeight: getComputedStyle(q('.pts-auth-label')).fontWeight,
        panelHeight: Math.round(r(q('.pts-auth-panel')).height),
        expanded: btn.getAttribute('aria-expanded'), panelVisibility: getComputedStyle(q('.pts-auth-panel')).visibility,
        textSize: fs(q('.pts-auth-copy p')),
      };
    }
    if (share) {
      out.share = {
        gapBelowAuthenticity: auth ? Math.round(r(share).top - r(auth).bottom) : null,
        height: Math.round(r(share).height),
        buttons: [...share.querySelectorAll('.pts-sb')].filter((b) => getComputedStyle(b).display !== 'none' && !b.hidden).length,
        buttonSize: Math.round(r(share.querySelector('.pts-sb')).width),
        labelSize: fs(q('.pts-share-label')),
        moreVisible: !!(q('.pts-more') && !q('.pts-more').hidden),
      };
    }
    return out;
  });
}

/* A full-page shot clipped to the buy column. An element shot of a column
   taller than the viewport scrolls, and the sticky header then lands on top of
   the picture; a full-page clip draws the column where it really is. */
async function shotBuybox(page, name) {
  const box = await page.evaluate(() => {
    const r = document.querySelector('.buybox').getBoundingClientRect();
    return { x: r.left + window.scrollX, y: r.top + window.scrollY, width: r.width, height: r.height };
  });
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true, clip: box });
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const actx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const admin = await login(actx);

  for (const w of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 900 }, deviceScaleFactor: w === 390 ? 2 : 1, hasTouch: w === 390, isMobile: w === 390 });
    await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => console.log(JSON.stringify({ w, pageError: String(e) })));

    /* BEFORE: the three switches off. */
    await post(admin, { del_on: false, auth_on: false, share_on: false });
    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
    await shotBuybox(page, `before-buybox-${w}`);
    M[`before-${w}`] = await measure(page);

    /* AFTER: as shipped. */
    await post(admin, { del_on: true, auth_on: true, share_on: true });
    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
    await page.screenshot({ path: path.join(OUT, `after-page-${w}.png`), fullPage: false });
    await shotBuybox(page, `after-buybox-${w}`);
    M[`after-${w}`] = await measure(page);

    await page.locator('.pts-del').screenshot({ path: path.join(OUT, `delivery-box-${w}.png`) });

    /* The slide: closed, mid-slide, open, and closed again from the ×. */
    const stack = page.locator('.pts-stack');
    await stack.screenshot({ path: path.join(OUT, `auth-closed-${w}.png`) });
    await page.click('.pts-auth-btn');
    await page.waitForTimeout(120);
    const mid = await page.evaluate(() => Math.round(document.querySelector('.pts-auth-panel').getBoundingClientRect().height));
    await stack.screenshot({ path: path.join(OUT, `auth-midslide-${w}.png`), animations: 'allow' });
    await page.waitForTimeout(600);
    await stack.screenshot({ path: path.join(OUT, `auth-open-${w}.png`) });
    M[`open-${w}`] = await measure(page);
    M[`open-${w}`].midSlidePanelHeight = mid;
    await page.click('.pts-auth-x');
    await page.waitForTimeout(600);
    M[`closed-again-${w}`] = await page.evaluate(() => ({
      expanded: document.querySelector('.pts-auth-btn').getAttribute('aria-expanded'),
      focusOnTrigger: document.activeElement && document.activeElement.id === 'ptsAuthBtn',
      panelHeight: Math.round(document.querySelector('.pts-auth-panel').getBoundingClientRect().height),
    }));
    // Escape closes too.
    await page.click('.pts-auth-btn');
    await page.waitForTimeout(500);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
    M[`escape-${w}`] = await page.evaluate(() => document.querySelector('.pts-auth-btn').getAttribute('aria-expanded'));

    /* The share bar, and Copy link. */
    await page.locator('.pts-share').screenshot({ path: path.join(OUT, `share-bar-${w}.png`) });
    if (w === 1280) {
      await page.hover('.pts-sb[data-net="whatsapp"]');
      await page.waitForTimeout(250);
      await page.locator('.pts-share').screenshot({ path: path.join(OUT, `share-bar-hover-${w}.png`) });
    }
    await page.click('.pts-sb[data-net="copy"]');
    await page.waitForTimeout(260);
    await page.locator('.pts-share').screenshot({ path: path.join(OUT, `share-copied-${w}.png`) });
    M[`copy-${w}`] = await page.evaluate(async () => ({
      status: document.querySelector('.pts-copied').textContent,
      clipboard: await navigator.clipboard.readText().catch(() => null),
    }));
    M[`hrefs-${w}`] = await page.evaluate(() => [...document.querySelectorAll('.pts-sb[href]')].map((a) => [a.dataset.net, a.getAttribute('href')]));

    /* Mono, rounded squares — the style choice. */
    await post(admin, { share_style: 'mono', share_shape: 'rounded' });
    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
    await page.locator('.pts-share').screenshot({ path: path.join(OUT, `share-bar-mono-rounded-${w}.png`) });
    await post(admin, { share_style: 'brand', share_shape: 'circle' });

    await ctx.close();
  }

  /* The admin tabs, at both widths. */
  for (const w of [1280, 390]) {
    await admin.setViewportSize({ width: w, height: w === 390 ? 844 : 1000 });
    await admin.goto(`${BASE}/admin?go=productpage`, { waitUntil: 'networkidle' });
    await admin.waitForSelector('[data-ptstab="ts_delivery"]');
    for (const tab of ['ts_delivery', 'ts_auth', 'ts_share', 'ts_space']) {
      await admin.click(`[data-ptstab="${tab}"]`);
      await admin.waitForTimeout(500);
      await admin.screenshot({ path: path.join(OUT, `admin-${tab}-${w}.png`), fullPage: w === 390 });
    }
    if (w === 1280) {
      /* Live preview: drag the delivery box's "space below · laptop" to 40 and
         read the gap inside the laptop frame — before anything is saved. */
      await admin.waitForTimeout(1500);
      const before = await admin.evaluate(() => {
        const d = document.querySelector('[data-ppframe="desktop"]').contentDocument;
        const del = d.querySelector('.pts-del'), buy = d.querySelector('.buyrow');
        return Math.round(buy.getBoundingClientRect().top - del.getBoundingClientRect().bottom);
      });
      await admin.evaluate(() => {
        const el = document.querySelector('[data-pts-k="del_below_d"]');
        el.value = '40'; el.dispatchEvent(new Event('input', { bubbles: true }));
      });
      await admin.click('[data-ptstab="ts_delivery"]');
      await admin.waitForTimeout(300);
      await admin.evaluate(() => {
        const c = document.querySelector('[data-pts-k="del_bg"]');
        c.value = '#fde2e4'; c.dispatchEvent(new Event('input', { bubbles: true }));
      });
      await admin.waitForTimeout(300);
      const after = await admin.evaluate(() => {
        const d = document.querySelector('[data-ppframe="desktop"]').contentDocument;
        const del = d.querySelector('.pts-del'), buy = d.querySelector('.buyrow');
        return { gap: Math.round(buy.getBoundingClientRect().top - del.getBoundingClientRect().bottom), bg: getComputedStyle(del).backgroundColor };
      });
      M['admin-live-preview'] = { gapBefore: before, gapAfterSliderTo40: after.gap, bgAfter: after.bg, dirty: await admin.textContent('#ppDirty') };
      await admin.screenshot({ path: path.join(OUT, `admin-live-preview-${w}.png`) });
      M[`admin-scrollWidth-${w}`] = await admin.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    } else {
      M[`admin-scrollWidth-${w}`] = await admin.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    }
  }

  fs.writeFileSync(path.join(OUT, 'MEASUREMENTS.json'), JSON.stringify(M, null, 2));
  console.log(JSON.stringify(M, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
