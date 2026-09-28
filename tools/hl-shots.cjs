/*
 * Lane HL screenshots: Appearance -> Homepage content -> Live preview.
 *
 * Driven the way an owner drives it -- sign in, open the screen, press the tab,
 * click a section IN THE PICTURE -- so what the pictures show is the path a
 * person takes and not a state assembled by script.
 */
const { chromium } = require('playwright');
const BASE = process.env.HL_BASE || 'http://127.0.0.1:8981';
const OUT = process.env.HL_OUT || '/home/user/lane-hl/docs/lane-hl-shots';

const wait = (p, ms) => p.waitForTimeout(ms);

async function measure(page, label) {
  return await page.evaluate((l) => ({
    label: l,
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    tabs: [...document.querySelectorAll('.hpc-tab')].map(b => b.textContent.trim()),
    selected: (document.querySelector('.hpe-panel .hpc-h') || {}).textContent || null,
    controls: [...document.querySelectorAll('.hpe-panel .hpc-row-t b')].map(b => b.textContent.trim()),
    picks: document.querySelectorAll('.hpe-pick').length,
    frame: !!document.querySelector('#hpe-frame'),
    frameSelected: (() => {
      const f = document.querySelector('#hpe-frame');
      if (!f || !f.contentDocument) return null;
      const el = f.contentDocument.querySelector('.kbb-pvsel');
      return el ? el.className : null;
    })(),
    hooks: (() => {
      const f = document.querySelector('#hpe-frame');
      if (!f || !f.contentDocument) return null;
      return f.contentDocument.querySelectorAll('.kbb-pvsec').length;
    })(),
    note: (document.querySelector('#hpe-note') || {}).textContent || null,
  }), label);
}

(async () => {
  const width = +process.argv[2];
  const browser = await chromium.launch({ executablePath: process.env.HL_CHROME });
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const out = [];

  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);

  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await wait(page, 900);
  await page.evaluate(() => window.go('hpcontent'));
  await wait(page, 1500);

  // 1 · the screen as it opens, before the live tab is touched.
  out.push(await measure(page, 'hpcontent-hero-tab'));
  await page.screenshot({ path: `${OUT}/hl-1-screen-${width}.png`, fullPage: true });

  // 2 · the live preview, nothing selected.
  await page.evaluate(() => {
    [...document.querySelectorAll('.hpc-tab')].find(b => b.textContent.trim() === 'Live preview').click();
  });
  await wait(page, 4000);
  out.push(await measure(page, 'live-before-selection'));
  await page.screenshot({ path: `${OUT}/hl-2-preview-${width}.png`, fullPage: true });

  // 3 · a section selected BY CLICKING IT IN THE PICTURE.
  const clicked = await page.evaluate(() => {
    const f = document.querySelector('#hpe-frame');
    if (!f || !f.contentDocument) return 'no reach';
    const el = f.contentDocument.querySelector('.kbb-pvsec-categories') || f.contentDocument.querySelector('.kbb-pvsec');
    if (!el) return 'no section';
    el.dispatchEvent(new f.contentWindow.MouseEvent('click', { bubbles: true }));
    return el.className;
  });
  await wait(page, 2000);
  const m3 = await measure(page, 'section-selected-by-click');
  m3.clicked = clicked;
  out.push(m3);
  await page.screenshot({ path: `${OUT}/hl-3-selected-${width}.png`, fullPage: true });

  // 4 · its controls, in use: the Desktop switch is turned off and the picture
  //     redraws from it without the page reloading.
  const before = await page.evaluate(() => (document.querySelector('#hpe-frame').contentDocument.querySelector('.kbb-pvsel') || {}).className || null);
  await page.evaluate(() => {
    const b = document.querySelector('.hpe-panel [data-hpc-toggle]');
    if (b) b.click();
  });
  await wait(page, 3500);
  const m4 = await measure(page, 'control-used-picture-redrawn');
  m4.selectedClassBefore = before;
  m4.selectedClassAfter = await page.evaluate(() => (document.querySelector('#hpe-frame').contentDocument.querySelector('.kbb-pvsel') || {}).className || null);
  m4.dirty = await page.evaluate(() => (document.querySelector('.hpe-dirty') || {}).textContent || null);
  out.push(m4);
  await page.screenshot({ path: `${OUT}/hl-4-controls-${width}.png`, fullPage: true });

  // 5 * the panel and the row of names, scrolled into view. The console scrolls
  //     #content rather than the window, so a full-page shot stops at the fold.
  await page.evaluate(() => {
    const p = document.querySelector('.hpe-panel');
    if (p) p.scrollIntoView({ block: 'center' });
  });
  await wait(page, 700);
  out.push(await measure(page, 'panel-in-view'));
  await page.screenshot({ path: `${OUT}/hl-5-panel-${width}.png`, fullPage: true });

  await page.evaluate(() => {
    const l = document.querySelector('#hpe-list');
    if (l) l.scrollIntoView({ block: 'center' });
  });
  await wait(page, 700);
  await page.screenshot({ path: `${OUT}/hl-6-sections-${width}.png`, fullPage: true });

  // 7 * a section that HAS a grid: the grid-style picker is drawn from the
  //     registry the payload carries, and only for the four that have one.
  await page.evaluate(() => {
    const b = document.querySelector('[data-hpe-pick="bundles"]');
    if (b) b.click();
  });
  await wait(page, 900);
  await page.evaluate(() => {
    const p = document.querySelector('.hpe-panel');
    if (p) p.scrollIntoView({ block: 'center' });
  });
  await wait(page, 500);
  const m7 = await measure(page, 'grid-section-selected');
  m7.skinOptions = await page.evaluate(() => {
    const s = document.querySelector('.hpe-panel select');
    return s ? { count: s.options.length, selected: s.value, first: s.options[0].textContent } : null;
  });
  out.push(m7);
  await page.screenshot({ path: `${OUT}/hl-7-grid-section-${width}.png`, fullPage: true });

  // 8 * the hero: the words are LINKED to, not repeated.
  await page.evaluate(() => {
    const b = document.querySelector('[data-hpe-pick="hero"]');
    if (b) b.click();
  });
  await wait(page, 900);
  await page.evaluate(() => {
    const p = document.querySelector('.hpe-panel');
    if (p) p.scrollIntoView({ block: 'center' });
  });
  await wait(page, 500);
  const m8 = await measure(page, 'hero-selected');
  m8.words = await page.evaluate(() => {
    const b = document.querySelector('[data-hpe-words]');
    return b ? b.parentNode.parentNode.innerText.replace(/\n/g, ' | ') : null;
  });
  out.push(m8);
  await page.screenshot({ path: `${OUT}/hl-8-hero-words-${width}.png`, fullPage: true });

  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
