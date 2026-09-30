/*
 * Lane PDP2 round 5 — the preview panel, photographed and measured.
 *
 *   "where's the preview on the product controls page? i need a proper
 *    preview of mobile and desktop both."
 *
 * 1. Appearance → Product page with the panel visible, at 390 and at 1280.
 * 2. THE PROOF THAT A CONTROL REACHES THE PICTURE: four controls moved one at
 *    a time, with the computed style read out of BOTH frames before and after.
 *
 * ▲ EVERY RECTANGLE AND EVERY COMPUTED SIZE IS READ BY THIS HARNESS AND NEVER
 *   BY A SCRIPT THE CONSOLE SERVES. CLAUDE.md rule 4 forbids JavaScript that
 *   measures layout in the product; measuring from outside is how the claim is
 *   checked. Nothing here reaches a package.
 *
 * Usage:  PDP5_BASE=http://127.0.0.1:9311 node tools/pdp5-shots.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.PDP5_BASE || 'http://127.0.0.1:9311';
const OUT = process.env.PDP5_OUT || path.join(__dirname, '..', 'docs', 'lane-pdp5-shots');
const EXE = process.env.PDP5_CHROME || '/opt/pw-browsers/chromium';

fs.mkdirSync(OUT, { recursive: true });

/* What the two frames are drawing right now, plus the console's own width. */
const READ = () => {
  const one = (kind) => {
    const fr = document.querySelector(`[data-ppframe="${kind}"]`);
    if (!fr || !fr.contentDocument) return null;
    const d = fr.contentDocument;
    const cs = (sel, prop) => {
      const el = d.querySelector(sel);
      return el ? getComputedStyle(el)[prop] : null;
    };
    return {
      /* ANTI-FALSE-GREEN: an EMPTY frame would answer null to all of these.
         The product name's TEXT is read as well as its size, so "the preview
         renders" cannot be satisfied by a blank white rectangle. */
      title: (d.querySelector('.pdp .bb-title') || {}).textContent || null,
      hasGallery: !!d.querySelector('.pdp .gmain, .pdp .gallery'),
      hasPrice: !!d.querySelector('.pdp .bb-price'),
      hasTabs: !!d.querySelector('.details .dtabbar'),
      switcher: !!d.querySelector('.pv-switch'),
      styleBlock: !!d.getElementById('kbb-pdp-layout'),
      titleSize: cs('.pdp .bb-title', 'fontSize'),
      titleWeight: cs('.pdp .bb-title', 'fontWeight'),
      priceSize: cs('.pdp .bb-price .now', 'fontSize'),
      secPadTop: cs('.sec', 'paddingTop'),
      headingSize: cs('.sec h2', 'fontSize'),
      tabSize: cs('.details .dtab', 'fontSize'),
      descSize: cs('.pdp .bb-desc', 'fontSize'),
      inline: fr.contentDocument.documentElement.getAttribute('style'),
    };
  };
  return {
    scrollWidth: document.documentElement.scrollWidth,
    viewport: document.documentElement.clientWidth,
    panes: [...document.querySelectorAll('.ppv-pane')].map((p) => p.dataset.ppv),
    frames: [...document.querySelectorAll('[data-ppframe]')].map((f) => f.dataset.ppframe),
    desktop: one('desktop'),
    mobile: one('mobile'),
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

/** Move one slider the way a finger does: set the value, fire `input`. */
async function slide(page, key, value) {
  await page.evaluate(([k, v]) => {
    const el = document.querySelector(`input[type=range][data-pl="${k}"]`);
    if (!el) throw new Error('no control ' + k);
    el.value = String(v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }, [key, value]);
  await page.waitForTimeout(350);
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};

  for (const width of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 950 }, deviceScaleFactor: 1 });
    const page = await ctx.newPage();
    await signIn(page);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.go('productpage'));
    await page.waitForTimeout(2600);

    report[`sections-${width}`] = await page.evaluate(READ);
    await page.screenshot({ path: `${OUT}/screen-sections-${width}.png`, fullPage: true });

    /* At 390 the panel sits UNDER the controls -- `.ppwrap` drops to one
       column below 1180 -- and #content scrolls inside the shell rather than
       the document, so `fullPage` captures the viewport and nothing more.
       Bring the panel into view and photograph it where the owner sees it. */
    const side = await page.$('.ppside');
    if (side) {
      await side.scrollIntoViewIfNeeded();
      await page.waitForTimeout(500);
      await page.screenshot({ path: `${OUT}/panel-${width}.png` });
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForTimeout(200);
    }

    /* ── THE FOUR CONTROLS, ONE AT A TIME, BEFORE AND AFTER ──────────────── */
    await page.click('[data-pptab="ty_buy"]');
    await page.waitForTimeout(600);
    report[`ty_buy-before-${width}`] = await page.evaluate(READ);
    await page.screenshot({ path: `${OUT}/ty_buy-before-${width}.png`, fullPage: true });

    await slide(page, 'title_m', 280);            // product name · phone → 28px
    report[`title_m-280-${width}`] = await page.evaluate(READ);
    await page.screenshot({ path: `${OUT}/title_m-280-${width}.png`, fullPage: true });

    await slide(page, 'title_d', 440);            // product name · laptop → 44px
    report[`title_d-440-${width}`] = await page.evaluate(READ);
    await page.screenshot({ path: `${OUT}/title_d-440-${width}.png`, fullPage: true });

    await page.click('[data-pptab="sp_page"]');
    await page.waitForTimeout(500);
    report[`sp_page-before-${width}`] = await page.evaluate(READ);
    await page.screenshot({ path: `${OUT}/sp_page-before-${width}.png`, fullPage: true });

    await slide(page, 'sec_pad', 90);             // space between sections → 90px
    report[`sec_pad-90-${width}`] = await page.evaluate(READ);
    await page.screenshot({ path: `${OUT}/sec_pad-90-${width}.png`, fullPage: true });

    await page.click('[data-pptab="ty_sec"]');
    await page.waitForTimeout(500);
    await slide(page, 'tab_s', 220);              // detail tab labels → 22px
    report[`tab_s-220-${width}`] = await page.evaluate(READ);
    await page.screenshot({ path: `${OUT}/tab_s-220-${width}.png`, fullPage: true });

    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync(`${OUT}/preview.json`, JSON.stringify(report, null, 2));
  console.log('wrote ' + OUT + '/preview.json');
})();
