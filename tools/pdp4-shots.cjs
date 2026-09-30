/*
 * Lane PDP2 round 4 — the pictures and the numbers.
 *
 *   1. Appearance → Product page, every tab, at 390 and 1280.
 *   2. The product page's detail tabs: nothing to click, then three to click,
 *      then the third one really clicked.
 *   3. THE GEOMETRY OF THE PRODUCT PAGE, read out of the browser, so "applying
 *      this package moves not one pixel" is a measurement rather than a claim.
 *
 * ▲ EVERY RECTANGLE IS READ BY THIS HARNESS AND NEVER BY A SCRIPT THE SHOP
 *   SERVES. CLAUDE.md rule 4 forbids JavaScript that measures layout in the
 *   product; measuring the product from outside is how the claim gets checked.
 *   Nothing here reaches a package.
 *
 * Usage:  PDP4_BASE=http://127.0.0.1:9311 node tools/pdp4-shots.cjs <what>
 *   what = admin | tabs | measure
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PDP4_BASE || 'http://127.0.0.1:9311';
const OUT = process.env.PDP4_OUT || path.join(__dirname, '..', 'docs', 'lane-pdp4-shots');
const EXE = process.env.PDP4_CHROME || '/opt/pw-browsers/chromium';
const TAG = process.env.PDP4_TAG || '';
const SLUG = process.env.PDP4_SLUG || 'ceramide-daily-moisturiser';

fs.mkdirSync(OUT, { recursive: true });

/* The numbers that decide whether this page moved. Every one of them is a
   box or a computed size on an element one of the thirty new custom properties
   reaches, plus the document's own scrollWidth. */
const GEOMETRY = () => {
  const px = (v) => Math.round(parseFloat(v) * 100) / 100;
  const box = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { x: px(r.x), y: px(r.y), w: px(r.width), h: px(r.height) };
  };
  const cs = (sel, props) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const s = getComputedStyle(el);
    const out = {};
    props.forEach((p) => { out[p] = s[p]; });
    return out;
  };

  return {
    scrollWidth: document.documentElement.scrollWidth,
    viewport: document.documentElement.clientWidth,
    styleBlock: !!document.getElementById('kbb-pdp-layout'),
    boxes: {
      head: box('.pdp .bb-head'),
      title: box('.pdp .bb-title'),
      price: box('.pdp .bb-price'),
      rate: box('.pdp .bb-rate'),
      desc: box('.pdp .bb-desc'),
      stockline: box('.pdp .stockline'),
      trust: box('.pdp .trust'),
      chips: box('.pdp .paychips'),
      tabbar: box('.details .dtabbar'),
      panel: box('.details .dpanel'),
      secDetails: box('.sec'),
    },
    type: {
      title: cs('.pdp .bb-title', ['fontSize', 'fontWeight', 'lineHeight']),
      price: cs('.pdp .bb-price .now', ['fontSize', 'fontWeight']),
      was: cs('.pdp .bb-price s', ['fontSize']),
      vat: cs('.pdp .bb-vat', ['fontSize']),
      rate: cs('.pdp .bb-rate', ['fontSize']),
      desc: cs('.pdp .bb-desc', ['fontSize', 'lineHeight', 'maxBlockSize', 'marginBlockStart', 'paddingBlockStart']),
      trustTi: cs('.pdp .trust .ti', ['fontSize']),
      trust: cs('.pdp .trust', ['rowGap', 'marginBlockStart', 'paddingBlockStart']),
      stockline: cs('.pdp .stockline', ['marginBlockStart', 'paddingBlockStart']),
      sec: cs('.sec', ['paddingTop', 'paddingBottom']),
      h2: cs('.sec h2', ['fontSize', 'fontWeight']),
      tabbar: cs('.details .dtabbar', ['columnGap', 'marginBlockEnd']),
      tab: cs('.details .dtab', ['fontSize']),
      body: cs('.details .dcontent', ['fontSize', 'lineHeight']),
      thumbs: cs('.pdp .gthumbs', ['columnGap']),
      buybox: cs('.pdp .buybox', ['paddingBlockStart']),
    },
    tabs: {
      buttons: [...document.querySelectorAll('.details .dtabbar .dtab')].map((b) => b.textContent.trim()),
      open: [...document.querySelectorAll('.details .dtabbar .dtab')].findIndex((b) => b.classList.contains('on')),
      panels: document.querySelectorAll('.details .dpanel .dtabpanel').length,
      visiblePanels: [...document.querySelectorAll('.details .dpanel .dtabpanel')]
        .filter((p) => getComputedStyle(p).display !== 'none').length,
      openBody: (() => {
        const p = [...document.querySelectorAll('.details .dpanel .dtabpanel')]
          .find((p) => getComputedStyle(p).display !== 'none');
        return p ? p.textContent.trim().slice(0, 70) : null;
      })(),
    },
  };
};

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
}

(async () => {
  const what = process.argv[2] || 'measure';
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();

    if (what === 'admin') {
      await signIn(page);
      await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
      await page.waitForTimeout(900);
      await page.evaluate(() => window.go('productpage'));
      await page.waitForTimeout(1400);

      const strip = await page.evaluate(() => ({
        tabs: [...document.querySelectorAll('[data-pptab]')].map((b) => b.textContent.trim()),
        scrollWidth: document.documentElement.scrollWidth,
        viewport: document.documentElement.clientWidth,
        crumb: (document.querySelector('#crumb') || {}).textContent,
      }));
      report['admin-' + width] = strip;
      await page.screenshot({ path: `${OUT}/admin-sections-${width}.png`, fullPage: true });

      for (const key of ['sp_page', 'sp_buy', 'ty_buy', 'ty_sec']) {
        await page.click(`[data-pptab="${key}"]`);
        await page.waitForTimeout(600);
        const card = await page.evaluate(() => ({
          heading: (document.querySelector('.mmhd b') || {}).textContent,
          note: (document.querySelector('.mmhd span') || {}).textContent,
          rows: [...document.querySelectorAll('.mmrow .mmlbl b')].map((b) => b.textContent.trim()),
          values: [...document.querySelectorAll('.mmrange i')].map((i) => i.textContent.trim()),
          selects: [...document.querySelectorAll('.mmrow select')].map((s) => s.value),
          scrollWidth: document.documentElement.scrollWidth,
        }));
        report[`admin-${key}-${width}`] = card;
        await page.screenshot({ path: `${OUT}/admin-${key}-${width}.png`, fullPage: true });
      }
    }

    if (what === 'tabs' || what === 'measure') {
      await page.goto(`${BASE}/product/${SLUG}`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(700);

      report[`page-${width}`] = await page.evaluate(GEOMETRY);
      await page.screenshot({ path: `${OUT}/${TAG}page-${width}.png`, fullPage: true });

      const details = await page.$('#details');
      if (details) {
        await details.scrollIntoViewIfNeeded();
        await page.waitForTimeout(250);
        await details.screenshot({ path: `${OUT}/${TAG}tabs-${width}.png` });
      }

      // …and one really clicked, which is the only way to show the row works.
      const third = await page.$('.details .dtabbar .dtab[data-i="2"]');
      if (third) {
        await third.click();
        await page.waitForTimeout(400);
        report[`page-${width}-clicked`] = await page.evaluate(() => {
          const open = [...document.querySelectorAll('.details .dtabbar .dtab')].findIndex((b) => b.classList.contains('on'));
          const panel = [...document.querySelectorAll('.details .dpanel .dtabpanel')]
            .find((p) => getComputedStyle(p).display !== 'none');
          return {
            open,
            visiblePanels: [...document.querySelectorAll('.details .dpanel .dtabpanel')]
              .filter((p) => getComputedStyle(p).display !== 'none').length,
            body: panel ? panel.textContent.trim().slice(0, 90) : null,
          };
        });
        const d2 = await page.$('#details');
        if (d2) await d2.screenshot({ path: `${OUT}/${TAG}tabs-open3-${width}.png` });
      }
    }

    await ctx.close();
  }

  await browser.close();
  const file = `${OUT}/${TAG}${what}.json`;
  fs.writeFileSync(file, JSON.stringify(report, null, 2));
  console.log('wrote ' + file);
})();
