/*
 * Lane PT evidence. Chromium at 390 and 1280, and the measured numbers under
 * every shot.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below — `npx playwright install` is forbidden
 * here.
 *
 * ── WHAT IS PHOTOGRAPHED ───────────────────────────────────────────────────
 *
 *   admin        Catalog → Product tabs, with three global tabs, one of them
 *                switched off, interleaved with the three built-ins
 *                one global tab open for editing, Arabic boxes and all
 *                one product's whole picture: inherited, its own, and a hide
 *
 *   storefront   a product with three built-ins + a global tab + its own
 *                a product that HIDES a global tab
 *                the same page on /ar
 *                a product nobody has touched, which is the proof
 *
 * tools/pt-preview.sh mounts this lane's route file and appends the one
 * @include to a COPY of the console shell, because both of the files the
 * integrator has to touch are files this lane may not edit. Its header has the
 * argument.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PT_BASE || 'http://127.0.0.1:8977';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.PT_OUT || `${APP}/docs/lane-pt-shots`;

const FULL = 'lanept-heartleaf-toner';
const DEVICE = 'lanept-led-mask';
const UNTOUCHED = 'lanept-ceramide-moisturiser';

async function shoot(page, name, w, h, extra) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(500);

  const m = await page.evaluate(() => {
    const strip = document.querySelector('.dtabbar');
    const css = strip ? getComputedStyle(strip) : null;

    return {
      viewport: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      /* THE NUMBER THAT MATTERS AT 390. scrollWidth greater than the viewport
         is a page with a horizontal scrollbar, which is the defect every width
         measurement in this repository is really about. */
      overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,

      /* ── the storefront tab strip ─────────────────────────────────────── */
      stripShown: strip ? css.display !== 'none' : null,
      stripOverflowX: strip ? css.overflowX : null,
      /* Scroll or wrap? Measured rather than asserted: a strip whose
         scrollWidth exceeds its clientWidth is one the shopper drags. */
      stripScrollWidth: strip ? strip.scrollWidth : null,
      stripClientWidth: strip ? strip.clientWidth : null,
      stripScrolls: strip ? strip.scrollWidth > strip.clientWidth : null,
      tabTitles: [...document.querySelectorAll('.dtab')].map((n) => n.textContent.trim()),
      accordionTitles: [...document.querySelectorAll('.macc-h')]
        .map((n) => n.textContent.replace(/[+−]\s*$/, '').trim()),
      accordionShown: document.querySelector('.macc')
        ? getComputedStyle(document.querySelector('.macc')).display !== 'none' : null,
      openPanels: document.querySelectorAll('.dtabpanel.on').length,
      openAccordions: document.querySelectorAll('.macc-i.open').length,
      dir: document.documentElement.getAttribute('dir'),
      lang: document.documentElement.getAttribute('lang'),

      /* ── the admin screen ─────────────────────────────────────────────── */
      adminRows: [...document.querySelectorAll('.kpt-row')].map((n) => {
        const nm = n.querySelector('.kpt-nm');
        const pills = [...n.querySelectorAll('.kpt-pill')].map((p) => p.textContent.trim());
        return (nm ? nm.childNodes[0].textContent.trim() : '?') + ' [' + pills.join(', ') + ']';
      }),
      adminArabicBoxes: document.querySelectorAll('.kbbar').length,
      adminRteBars: document.querySelectorAll('.kpt-rte-bar').length,
      adminOpenEditors: document.querySelectorAll('.kpt-edit').length,
      adminCrumb: document.querySelector('#crumb')?.textContent ?? null,
      adminTitle: document.querySelector('#ptitle')?.textContent ?? null,
      adminNavRow: !!document.querySelector('#nav [data-go="product-tabs"]'),
    };
  });

  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
  console.log(JSON.stringify({ shot: `${name}-${w}`, ...m, ...(extra || {}) }, null, 0));
}

async function signIn(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  page.on('dialog', (d) => d.accept());
  page.on('pageerror', (e) => console.log(JSON.stringify({ pageerror: String(e) })));

  /* ───────────────────────────────────────────── 1. the storefront ─── */

  await page.goto(`${BASE}/product/${FULL}/`, { waitUntil: 'networkidle' });
  for (const w of [390, 1280]) await shoot(page, '05-product-everything', w, 2200);

  await page.goto(`${BASE}/product/${DEVICE}/`, { waitUntil: 'networkidle' });
  for (const w of [390, 1280]) await shoot(page, '06-product-global-hidden', w, 2000);

  await page.goto(`${BASE}/ar/product/${FULL}/`, { waitUntil: 'networkidle' });
  for (const w of [390, 1280]) await shoot(page, '07-product-arabic', w, 2200);

  /* THE CONTROL CASE, and the whole point of it: a product nobody has touched
     draws exactly the tabs it drew before this package. */
  await page.goto(`${BASE}/product/${UNTOUCHED}/`, { waitUntil: 'networkidle' });
  for (const w of [390, 1280]) await shoot(page, '08-product-untouched', w, 1800);

  /* ───────────────────────────────────────────────── 2. the admin ─── */

  await signIn(page);

  await page.evaluate(() => window.go('product-tabs'));
  await page.waitForTimeout(1600);
  for (const w of [390, 1280]) await shoot(page, '01-admin-global-tabs', w, 1700);

  // One global tab open: the title, its Arabic box, the rich pane and ITS
  // Arabic box, and the on/off.
  await page.setViewportSize({ width: 1280, height: 1900 });
  await page.click('[data-kpt-open="global:1"]');
  await page.waitForTimeout(900);
  for (const w of [390, 1280]) await shoot(page, '02-admin-global-editor', w, 2100);

  // Close it again before the product half, so the shots are not cumulative.
  await page.setViewportSize({ width: 1280, height: 1900 });
  await page.click('[data-kpt-open="global:1"]');
  await page.waitForTimeout(600);

  /* One product's whole picture. */
  await page.fill('[data-kpt-q]', 'Heartleaf');
  await page.click('[data-kpt-find]');
  await page.waitForTimeout(900);
  await page.click('[data-kpt-pick]');
  await page.waitForTimeout(1100);
  for (const w of [390, 1280]) await shoot(page, '03-admin-one-product', w, 2000);

  /* And the one with a global tab hidden on it. */
  await page.setViewportSize({ width: 1280, height: 2000 });
  await page.fill('[data-kpt-q]', 'LED');
  await page.click('[data-kpt-find]');
  await page.waitForTimeout(900);
  await page.click('[data-kpt-pick]');
  await page.waitForTimeout(1100);
  for (const w of [390, 1280]) await shoot(page, '04-admin-hidden-here', w, 2000);

  await browser.close();
})();
