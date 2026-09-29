/*
 * Lane PP evidence. Chromium at 390 and 1280, before and after, with the
 * numbers under every shot.
 *
 * WHAT IS MEASURED, and why each one:
 *   scrollWidth        — the shop must never scroll sideways at 390.
 *   addToCartY         — item 1 is about how far the list pushes the button
 *                        down, so the button's y is the number that moves.
 *   listHeight         — the set contents block, top to bottom.
 *   rowHeight          — one row of it.
 *   descGap            — item 3: the measured distance from the bottom of the
 *                        short description to the top of whatever follows it.
 *   pageHeight         — the whole document.
 *
 * This measures a RENDERED page from outside it. Nothing here ships; the page
 * itself contains no layout-measuring script and this file is not loaded by it.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.PP_BASE || 'http://127.0.0.1:8977';
const OUT = process.env.PP_OUT || '/home/user/lane-pp/docs/lane-pp-shots';

const MEASURE = () => {
  const num = (v) => (v === null || v === undefined ? null : Math.round(v));
  const rect = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { top: num(r.top + window.scrollY), bottom: num(r.bottom + window.scrollY), h: num(r.height), w: num(r.width) };
  };
  const desc = rect('.bb-desc');
  // What actually follows the short description in the flow, whichever it is.
  const descEl = document.querySelector('.bb-desc');
  let nextSel = null, nextRect = null;
  if (descEl) {
    let n = descEl.nextElementSibling;
    // The <form> wraps the option block; the visible neighbour is its first child.
    while (n && n.getBoundingClientRect().height === 0) n = n.nextElementSibling;
    if (n) {
      let inner = n;
      if (n.tagName === 'FORM') {
        const kids = [...n.children].filter((c) => c.getBoundingClientRect().height > 0);
        if (kids.length) inner = kids[0];
      }
      const r = inner.getBoundingClientRect();
      nextSel = inner.tagName.toLowerCase() + (inner.className ? '.' + String(inner.className).trim().split(/\s+/).join('.') : '');
      nextRect = { top: num(r.top + window.scrollY), h: num(r.height) };
    }
  }
  const rows = [...document.querySelectorAll('.ksl-r')];
  const cart = document.querySelector('.cart button[type=submit], .cart .addcart, #addToCart, .bb-add, button.add');
  const addBtn = cart || [...document.querySelectorAll('.cart button, .cart .btn')].find((b) => /add/i.test(b.textContent || ''));
  const addR = addBtn ? addBtn.getBoundingClientRect() : null;
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    pageHeight: document.documentElement.scrollHeight,
    addToCartY: addR ? num(addR.top + window.scrollY) : null,
    addToCartText: addBtn ? (addBtn.textContent || '').trim().slice(0, 40) : null,
    listRect: rect('.ksl'),
    listRows: rows.length,
    rowHeight: rows[0] ? num(rows[0].getBoundingClientRect().height) : null,
    photoBox: (() => { const p = document.querySelector('.ksl-ph'); return p ? num(p.getBoundingClientRect().width) : null; })(),
    memberPrices: document.querySelectorAll('.ksl-pr').length,
    memberQtys: document.querySelectorAll('.ksl-q').length,
    footText: (document.querySelector('.ksl-foot')?.textContent || '').replace(/\s+/g, ' ').trim() || null,
    descRect: desc,
    descNext: nextSel,
    descGap: desc && nextRect ? num(nextRect.top - desc.bottom) : null,
    galleryRect: rect('.gallery, .pg-main, .pdp > :first-child'),
  };
};

async function shoot(page, url, name, w, h) {
  await page.setViewportSize({ width: w, height: h });
  await page.goto(url, { waitUntil: 'networkidle' });
  await page.waitForTimeout(300);
  const m = await page.evaluate(MEASURE);
  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });
  console.log(JSON.stringify({ shot: `${name}-${w}`, url, ...m }));
  return m;
}

const PAGES = (process.env.PP_PAGES || 'set3:lanepp-glow-starter-set,set12:lanepp-full-routine-set,plain:lanepp-plain-moisturiser')
  .split(',').map((s) => s.split(':'));
const PREFIX = process.env.PP_PREFIX || 'before';
const QS = process.env.PP_QS || '';

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const all = [];
  for (const [label, slug] of PAGES) {
    for (const w of [390, 1280]) {
      all.push(await shoot(page, `${BASE}/product/${slug}/${QS}`, `${PREFIX}-${label}`, w, 1400));
    }
  }
  await browser.close();
  fs.writeFileSync(`${OUT}/${PREFIX}-measurements.json`, JSON.stringify(all, null, 2));
})();
