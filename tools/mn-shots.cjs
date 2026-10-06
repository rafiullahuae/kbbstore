/*
 * LANE MN — the mobile menu, photographed and measured in Chromium.
 *
 *   KBB_BASE=http://127.0.0.1:10760 node tools/mn-shots.cjs <phase> [widths]
 *
 * <phase> names the output folder (docs/mn-shots/<phase>/): `before` is today's
 * bottom sheet, `after` the side panel, `bottom` the switch set back to Bottom.
 *
 * At each width (320, 360, 390, 430, 768 by default): menu closed, opening
 * (a mid-animation frame, with the slide slowed for the camera only), open,
 * and a sub-menu open; Arabic at 390; the glass over a busy page at 390. Next
 * to every picture it records the panel's box, the row heights, the font sizes
 * and the page's and panel's scrollWidth, in report.json. The measuring here is
 * the camera's, not the shop's: the shop's script measures nothing.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10760';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const PHASE = process.argv[2] || 'after';
const WIDTHS = (process.argv[3] || '320,360,390,430,768').split(',').map(Number);
const OUT = path.join(__dirname, '..', 'docs', 'mn-shots', PHASE);
fs.mkdirSync(OUT, { recursive: true });

const HEIGHT = { 320: 568, 360: 780, 390: 844, 430: 932, 768: 1024 };
const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';

const measure = (page) => page.evaluate(() => {
  const m = document.getElementById('mmenu');
  const r = m.getBoundingClientRect();
  const cs = getComputedStyle(m);
  const rows = [...m.querySelectorAll('.mm-body > .mm-it, .mm-body > .mm-node > .mm-par')].slice(0, 6)
    .map((e) => Math.round(e.getBoundingClientRect().height * 10) / 10);
  const it = m.querySelector('.mm-it');
  const si = [...m.querySelectorAll('.mm-node.on .mm-si')];
  const body = m.querySelector('.mm-body');
  return {
    viewport: [innerWidth, innerHeight],
    panel: { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width * 10) / 10, h: Math.round(r.height) },
    open: m.classList.contains('on'),
    classes: m.className,
    backdrop: cs.backdropFilter || cs.webkitBackdropFilter || 'none',
    background: cs.backgroundColor,
    rowHeights: rows,
    rowFont: it ? getComputedStyle(it).fontSize : null,
    subRows: si.slice(0, 4).map((e) => Math.round(e.getBoundingClientRect().height * 10) / 10),
    subFont: si[0] ? getComputedStyle(si[0]).fontSize : null,
    subColWidth: si[0] ? Math.round(si[0].getBoundingClientRect().width * 10) / 10 : null,
    subWraps: si.filter((e) => e.getBoundingClientRect().height > 40).map((e) => e.textContent.trim()).slice(0, 5),
    docScrollWidth: document.documentElement.scrollWidth,
    docClientWidth: document.documentElement.clientWidth,
    panelScrollWidth: body ? body.scrollWidth : null,
    panelClientWidth: body ? body.clientWidth : null,
    focus: document.activeElement ? (document.activeElement.id || document.activeElement.className || document.activeElement.tagName) : null,
    bodyOverflow: document.body.style.overflow,
  };
});

async function shoot(browser, width, lang, report) {
  const h = HEIGHT[width] || 844;
  const ctx = await browser.newContext({ viewport: { width, height: h }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, userAgent: IPHONE });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message.split('\n')[0]));
  const tag = `${lang}-${width}`;
  await page.goto(BASE + (lang === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${OUT}/${tag}-1-closed.png` });
  const closed = await measure(page);

  // Opening: the slide slowed 12x for the camera, frame taken partway.
  await page.addStyleTag({ content: '.mmenu.on{transition:transform 3000ms cubic-bezier(.32,.72,0,1),visibility 0s!important}' });
  await page.click('#burger');
  await page.waitForTimeout(450);
  await page.screenshot({ path: `${OUT}/${tag}-2-opening.png` });
  const opening = await measure(page);
  await page.waitForTimeout(3200);
  await page.screenshot({ path: `${OUT}/${tag}-3-open.png` });
  const open = await measure(page);

  // A sub-menu: the first parent row (Brands on the seeded menu).
  const par = page.locator('#mmenu .mm-body > .mm-node > .mm-par').first();
  let sub = null;
  if (await par.count()) {
    await par.click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: `${OUT}/${tag}-4-submenu.png` });
    sub = await measure(page);
  }
  report[tag] = { closed, opening, open, sub, errors };
  await ctx.close();
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const report = {};
  for (const w of WIDTHS) await shoot(browser, w, 'en', report);
  if (WIDTHS.includes(390)) await shoot(browser, 390, 'ar', report);
  fs.writeFileSync(`${OUT}/report.json`, JSON.stringify(report, null, 2));
  const brief = Object.fromEntries(Object.entries(report).map(([k, v]) => [k, {
    closedX: v.closed.panel.x, closedY: v.closed.panel.y, openPanel: v.open.panel, rowH: v.open.rowHeights.slice(0, 3), rowFont: v.open.rowFont,
    subFont: v.sub && v.sub.subFont, subRowH: v.sub && v.sub.subRows.slice(0, 2), subColW: v.sub && v.sub.subColWidth, wraps: v.sub && v.sub.subWraps,
    scroll: [v.open.docScrollWidth, v.open.docClientWidth, v.sub && v.sub.panelScrollWidth, v.sub && v.sub.panelClientWidth], focus: v.open.focus, errors: v.errors,
  }]));
  console.log(JSON.stringify(brief, null, 1));
  await browser.close();
})();
