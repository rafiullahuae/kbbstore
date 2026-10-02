/*
 * Lane QA — the product page as ordered, switchable sections on a phone, the
 * desktop price row, Tabby & Tamara, and the "Mobile sections" admin tab,
 * photographed and measured in Chromium at 390 and 1280.
 *
 *   sh tools/qa-preview.sh 9800                     # boots tools/qa-seed.php
 *   QA_BASE=http://127.0.0.1:9800 node tools/qa-shots.cjs before|after|admin
 *
 * Writes docs/qa-shots/*.png and docs/qa-shots/MEASUREMENTS-<mode>.json.
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS, NEVER BY A SCRIPT THE SHOP SERVES.
 *   CLAUDE.md rule 4 forbids JavaScript that measures layout in the product;
 *   measuring the product from outside is how the claim gets checked.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.QA_BASE || 'http://127.0.0.1:9800';
const OUT = path.join(__dirname, '..', 'docs', 'qa-shots');
const CHROME = process.env.QA_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const MODE = process.argv[2] || 'after';
const M = {};

fs.mkdirSync(OUT, { recursive: true });

const PAGES = [
  ['toner', '/product/pdp-heartleaf-toner/'],
  ['set', '/product/pdp-glow-ritual-set/'],
  ['variable', '/product/pdp-variable-ampoule/'],
  ['soldout', '/product/pdp-sold-out-serum/'],
];

/* The sections, by the selector the stylesheet orders them with. A section
   that renders nothing, or is switched off, measures as null. */
const SECTIONS = {
  gallery: '.pdp-page .gallery',
  title: '.pm-title',
  short: '.pm-short',
  price: '.pm-price',
  paylater: '.pm-paylater',
  bundles: '.pm-bundles',
  ready: '.pm-ready',
  cart: '.pm-cart',
  delivery: '.pdp-page .kbb-cart-form > .pts-del',
  auth: '.pdp-page .kbb-cart-form > .pts-stack',
  trust: '.pm-trust',
  paychips: '.pm-paychips',
  buytogether: '.pdp-page > .kbb-fbt',
  details: '.pm-details',
  reviews: '.pdp-page > .sr',
  related: '.pdp-page > .ymal',
};

async function login(ctx) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

async function post(admin, body) {
  return admin.evaluate(async (b) => {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const r = await fetch('/admin-api/product-page', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' },
      body: JSON.stringify(b) });
    return r.json();
  }, body);
}

/** Section order by on-screen top, the gaps between them, and the numbers the report quotes. */
async function measure(page) {
  return page.evaluate((SECTIONS) => {
    const out = { viewport: window.innerWidth, scrollWidth: document.documentElement.scrollWidth, sections: {} };
    const seen = [];
    for (const [k, sel] of Object.entries(SECTIONS)) {
      const el = document.querySelector(sel);
      if (!el) { out.sections[k] = null; continue; }
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      if (cs.display === 'none' || (r.height === 0 && cs.display !== 'contents')) { out.sections[k] = null; continue; }
      const top = r.top + window.scrollY, h = r.height;
      out.sections[k] = { top: Math.round(top * 10) / 10, height: Math.round(h * 10) / 10 };
      seen.push([k, top, top + h]);
    }
    seen.sort((a, b) => a[1] - b[1]);
    out.order = seen.map((s) => s[0]);
    out.gaps = [];
    for (let i = 1; i < seen.length; i++) out.gaps.push(`${seen[i - 1][0]}→${seen[i][0]}: ${Math.round((seen[i][1] - seen[i - 1][2]) * 100) / 100}px`);
    const q = (s) => document.querySelector(s);
    const pr = q('.bb-pricerow'), price = q('#bbPrice'), rate = q('.bb-pricerow .cap-area'), title = q('#bbTitle'), share = q('.pdp-share-btn');
    const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { x: Math.round(r.left), y: Math.round(r.top + window.scrollY), w: Math.round(r.width), h: Math.round(r.height) }; };
    out.title = box(title);
    out.share = box(share);
    out.price = box(price);
    out.pricerow = box(pr);
    out.rating = rate && getComputedStyle(rate).display !== 'none' ? box(rate) : null;
    out.ratingCount = !!document.querySelector('.bb-pricerow .sr-cap-count');
    out.detailsEyebrowShown = (() => { const e = q('.pm-details .eyebrow'); return !!e && getComputedStyle(e).display !== 'none'; })();
    out.trustShown = (() => { const e = q('.pdp .trust'); return !!e && getComputedStyle(e).display !== 'none'; })();
    out.paylater = box(q('.pdp-paylater'));
    out.titleFont = title ? getComputedStyle(title).fontSize : null;
    out.priceFont = q('#bbPrice .now') ? getComputedStyle(q('#bbPrice .now')).fontSize : null;
    return out;
  }, SECTIONS);
}

async function shoot(ctx, name, url, width) {
  const page = await ctx.newPage();
  await page.setViewportSize({ width, height: width < 600 ? 844 : 900 });
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(OUT, `${MODE}-${name}-${width}.png`), fullPage: true });
  M[`${name}-${width}`] = await measure(page);
  await page.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ deviceScaleFactor: 1 });

  if (MODE === 'after') {
    // Start from what ships, whatever tools/qa-admin-shots.cjs last saved.
    const admin = await login(ctx);
    await admin.evaluate(async () => {
      const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
      const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': m ? decodeURIComponent(m[1]) : '' };
      const d = await (await fetch('/admin-api/product-page', { credentials: 'same-origin', headers: h })).json();
      const opts = {};
      d.msections.options.forEach((t) => t.fields.forEach((f) => { opts[f.key] = f.default; }));
      await fetch('/admin-api/product-page', { method: 'POST', credentials: 'same-origin', headers: h, body: JSON.stringify({ msections: { list: d.msections.defaults, options: opts } }) });
    });
    await admin.close();
  }

  if (MODE === 'before' || MODE === 'after') {
    for (const [name, url] of PAGES) {
      for (const w of [390, 1280]) await shoot(ctx, name, url, w);
    }
  }
  if (MODE === 'arabic') {
    // Run after `php artisan tinker --execute="require 'tools/pdp-arabic-on.php';"`
    // against the preview database: a mirrored page, at both widths.
    await shoot(ctx, 'toner', '/ar/product/pdp-heartleaf-toner/', 390);
    await shoot(ctx, 'toner', '/ar/product/pdp-heartleaf-toner/', 1280);
    await shoot(ctx, 'set', '/ar/product/pdp-glow-ritual-set/', 390);
  }

  fs.writeFileSync(path.join(OUT, `MEASUREMENTS-${MODE}.json`), JSON.stringify(M, null, 2));
  await browser.close();
  console.log(JSON.stringify(M, null, 1).slice(0, 4000));
})();
