/*
 * LANE M4 — the V4 two-tone phone menu, photographed and measured in Chromium.
 *
 *   KBB_BASE=http://127.0.0.1:10790 node tools/m4-shots.cjs <phase> [widths]
 *
 * Output: docs/m4-shots/<phase>/. At each width (320, 360, 390, 430, 768):
 * the menu open, a section OPENING (a mid-animation frame: the sub-menu
 * duration is slowed for the camera only) and the section open; Arabic at 390.
 * report.json holds the measured sizes beside every picture. The measuring is
 * the camera's; the shop's own script measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10790';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const PHASE = process.argv[2] || 'after';
const WIDTHS = (process.argv[3] || '320,360,390,430,768').split(',').map(Number);
const OUT = path.join(__dirname, '..', 'docs', 'm4-shots', PHASE);
fs.mkdirSync(OUT, { recursive: true });

const HEIGHT = { 320: 568, 360: 780, 390: 844, 430: 932, 768: 1024 };
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

const measure = (page) => page.evaluate(() => {
  const r1 = (n) => Math.round(n * 10) / 10;
  const m = document.getElementById('mmenu');
  const box = (e) => (e ? e.getBoundingClientRect() : null);
  const cs = (e, p) => (e ? getComputedStyle(e)[p] : null);
  const rows = [...m.querySelectorAll('.mm-body > .mm-it, .mm-body > .mm-node > .mm-par')].slice(0, 4).map((e) => r1(box(e).height));
  const chips = [...m.querySelectorAll('.mm-chip')];
  const chipr = m.querySelector('.mm-chipr');
  const si = [...m.querySelectorAll('.mm-node.on .mm-si')];
  const kid = m.querySelector('.mm-node.on > .mm-kid');
  const hot = [...m.querySelectorAll('.mm-it[style*="background:"]')][0];
  const body = m.querySelector('.mm-body');
  const srch = m.querySelector('.mm-srch input');
  const chipHit = chips[0] ? (() => { const a = getComputedStyle(chips[0], '::after'); return r1(box(chips[0]).height - parseFloat(a.top) - parseFloat(a.bottom)); })() : null;
  return {
    viewport: [innerWidth, innerHeight],
    classes: m.className,
    style: m.getAttribute('style'),
    panelW: r1(box(m).width),
    bandH: chipr ? r1(box(m.querySelector('.mm-chips')).bottom - box(m).top) : null,
    searchH: srch ? r1(box(srch).height) : null, searchFont: cs(srch, 'fontSize'),
    chips: chips.map((c) => c.textContent.trim()),
    chipH: chips[0] ? r1(box(chips[0]).height) : null, chipTapH: chipHit, chipFont: cs(chips[0], 'fontSize'),
    chipRowScroll: chipr ? [chipr.scrollWidth, chipr.clientWidth] : null,
    rowH: rows, rowFont: cs(m.querySelector('.mm-it'), 'fontSize'),
    countColour: cs(m.querySelector('.mm-ct'), 'color'), headingFont: cs(m.querySelector('.mm-grp'), 'fontSize'),
    saleRow: hot ? { bg: cs(hot, 'backgroundColor'), image: cs(hot, 'backgroundImage'), colour: cs(hot, 'color') } : null,
    subH: si.slice(0, 2).map((e) => r1(box(e).height)), subFont: cs(si[0], 'fontSize'),
    kidH: kid ? r1(box(kid).height) : null, kidRows: cs(kid, 'gridTemplateRows'),
    docScrollWidth: document.documentElement.scrollWidth, docClientWidth: document.documentElement.clientWidth,
    panelScrollWidth: body ? body.scrollWidth : null, panelClientWidth: body ? body.clientWidth : null,
  };
});

async function shoot(browser, width, lang, report) {
  const ctx = await browser.newContext({ viewport: { width, height: HEIGHT[width] || 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message.split('\n')[0]));
  const tag = `${lang}-${width}`;
  await page.goto(BASE + (lang === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  await page.click('#burger');
  await page.waitForTimeout(700);
  await page.screenshot({ path: `${OUT}/${tag}-1-open.png` });
  const open = await measure(page);

  // A section opening, slowed for the camera only, frame taken partway.
  const par = page.locator('#mmenu .mm-body > .mm-node > .mm-par').first();
  let mid = null; let sub = null;
  if (await par.count()) {
    await page.addStyleTag({ content: '.mmenu.mm-v4{--m-sd:2400ms !important}' });
    await par.click();
    await page.waitForTimeout(700);
    await page.screenshot({ path: `${OUT}/${tag}-2-opening.png` });
    mid = await measure(page);
    await page.waitForTimeout(2300);
    await page.screenshot({ path: `${OUT}/${tag}-3-section-open.png` });
    sub = await measure(page);
  }
  report[tag] = { open, mid, sub, errors };
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};
  for (const w of WIDTHS) await shoot(browser, w, 'en', report);
  if (WIDTHS.includes(390) && !process.env.KBB_NO_AR) await shoot(browser, 390, 'ar', report);
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  const brief = Object.fromEntries(Object.entries(report).map(([k, v]) => [k, {
    panelW: v.open.panelW, bandH: v.open.bandH, searchH: v.open.searchH, chipH: v.open.chipH, chipTapH: v.open.chipTapH, chipFont: v.open.chipFont,
    chipRow: v.open.chipRowScroll, rowH: v.open.rowH, rowFont: v.open.rowFont, subH: v.sub && v.sub.subH, subFont: v.sub && v.sub.subFont,
    midKidH: v.mid && v.mid.kidH, openKidH: v.sub && v.sub.kidH, sale: v.open.saleRow, count: v.open.countColour,
    scroll: [v.open.docScrollWidth, v.open.docClientWidth, v.sub && v.sub.panelScrollWidth, v.sub && v.sub.panelClientWidth], errors: v.errors,
  }]));
  console.log(JSON.stringify(brief));
  await browser.close();
})();
