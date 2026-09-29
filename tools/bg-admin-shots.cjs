/*
 * Appearance → Page background, photographed in the real console.    (Lane BG)
 *
 *   BG_BASE=http://127.0.0.1:8933 node tools/bg-admin-shots.cjs
 *
 * Run against a tree with docs/BG-ADMIN-APP-BLOCKS.md's three blocks applied,
 * so what is photographed is the console the integrator will produce: the
 * sidebar row the partial registers, the breadcrumb TITLES gives it, and the
 * screen its own include draws. The blocks are reverted straight afterwards --
 * nothing in this branch touches app.blade.php.
 *
 * It drives the screen rather than posing it: it opens the Preview tab, steps
 * through the four treatments and the five pages, switches the frame between
 * 390 and 1280, presses "Use this", and then photographs the three control tabs
 * -- so a control that is drawn and does nothing shows up here as a frame that
 * did not change.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BG_BASE || 'http://127.0.0.1:8933';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.BG_OUT || `${APP}/docs/bg-shots`;

const rows = [];

async function shoot(page, name, w) {
  await page.setViewportSize({ width: w, height: w === 390 ? 844 : 1000 });
  await page.waitForTimeout(700);
  const m = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
    crumb: document.querySelector('#crumb')?.textContent ?? null,
    title: document.querySelector('#ptitle')?.textContent ?? null,
    sidebarRows: document.querySelectorAll('#nav [data-go="pagewash"]').length,
    openTab: document.querySelector('.pwb-tab[aria-selected="true"]')?.textContent ?? null,
    treatmentCards: document.querySelectorAll('.pwb-t').length,
    swatches: document.querySelectorAll('.pwb-strip i').length,
    frameSrc: document.querySelector('[data-pwb-frame]')?.getAttribute('src') ?? null,
    frameScale: document.querySelector('[data-pwb-frame]')?.style.transform ?? null,
    controls: document.querySelectorAll('[data-pwb-key]').length,
    contrastRows: document.querySelectorAll('.pwb-t2 tbody tr').length,
  }));
  await page.screenshot({ path: `${OUT}/admin-${name}-${w}.png`, fullPage: true });
  rows.push({ shot: `admin-${name}-${w}`, ...m });
  console.log(JSON.stringify({ shot: `admin-${name}-${w}`, ...m }));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: CHROME });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
  const page = await ctx.newPage();

  page.on('console', (m) => { if (m.type() === 'error') console.log(JSON.stringify({ consoleError: m.text() })); });
  page.on('pageerror', (e) => console.log(JSON.stringify({ pageError: String(e) })));

  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  for (const w of [1280, 390]) {
    /* THE DEEP LINK, not window.go(): ?go=pagewash is the thing LATE_RENDERED
       arms, and a screen that is in TITLES but not in that set opens the
       dashboard under its own heading with no error anywhere. Driving it this
       way is the only way the photograph proves the arming landed. */
    await page.goto(`${BASE}/admin?go=pagewash`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1400);

    await shoot(page, 'preview-a-home', w);

    await page.click('[data-pwb-show="d"]');
    await page.waitForTimeout(900);
    await shoot(page, 'preview-d-home', w);

    // the page picker: the shop listing, where the wash is most of the page
    await page.click('[data-pwb-page="1"]');
    await page.waitForTimeout(1400);
    await shoot(page, 'preview-d-shop', w);

    await page.click('[data-pwb-show="c"]');
    await page.waitForTimeout(1400);
    await shoot(page, 'preview-c-shop', w);

    // the phone frame inside the console
    await page.click('[data-pwb-w="390"]');
    await page.waitForTimeout(1400);
    await shoot(page, 'preview-c-shop-phoneframe', w);
    await page.click('[data-pwb-w="1280"]');
    await page.waitForTimeout(600);

    // "Use this" moves the sliders and lands on the Colour tab
    await page.click('[data-pwb-show="b"]');
    await page.waitForTimeout(300);
    await page.click('[data-pwb-use="b"]');
    await page.waitForTimeout(700);
    await shoot(page, 'controls-colour', w);

    await page.click('[data-pwb-tab="motion"]');
    await page.waitForTimeout(400);
    await shoot(page, 'controls-motion', w);

    await page.click('[data-pwb-tab="reach"]');
    await page.waitForTimeout(400);
    await shoot(page, 'controls-reach', w);
  }

  fs.writeFileSync(`${OUT}/admin-measurements.json`, JSON.stringify(rows, null, 2));
  await browser.close();
  console.log(JSON.stringify({ done: rows.length }));
})();
