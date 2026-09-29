/**
 * LANE GS — screenshots and the numbers behind the claims.
 *
 * Shoots the two instances the owner named at 390 and 1280, in English and in
 * Arabic, and reports for each:
 *
 *   scrollWidth   document.documentElement.scrollWidth against clientWidth —
 *                 the whole of the claim "no horizontal page scroll", and
 *                 nothing else. `worst` names the widest element that sticks
 *                 out, so a failure says WHAT overflowed.
 *   cols          the RESOLVED grid-template-columns of each instance's grid,
 *                 read off getComputedStyle. Not inferred from a breakpoint
 *                 this file guessed at. Rule 4 forbids the shop measuring its
 *                 own layout; this is a harness outside the shop.
 *   display       grid or flex — which says whether the carousel is on at that
 *                 width, measured rather than assumed from the class.
 *   tiles         how many cards are VISIBLE (offsetParent !== null), which is
 *                 the surplus-hiding rule doing its job: 10 on desktop and 6 on
 *                 the phone for the BEST SELLERS row.
 *   scrollLeft    the carousel's resting scroll offset. On /ar this is 0 in a
 *                 right-to-left box too, because the browser measures from the
 *                 inline start — and a positive maximum scrollLeft on one and a
 *                 negative on the other is what "advances the other way" IS.
 */
import { chromium } from '/home/user/kbbstore/node_modules/playwright/index.mjs';
import fs from 'node:fs';

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8971';
const OUT = process.env.KBB_SHOTS || 'docs/lane-gs-shots';
const rows = [];

const probe = () => {
  const d = document.documentElement;
  const out = {
    scrollWidth: d.scrollWidth,
    clientWidth: d.clientWidth,
    worst: null,
    sections: [],
  };

  if (d.scrollWidth > d.clientWidth) {
    let worstW = 0;
    document.querySelectorAll('*').forEach((el) => {
      const r = el.getBoundingClientRect();
      if (r.right > d.clientWidth + 1 && r.width > worstW) {
        worstW = r.width;
        out.worst = el.tagName.toLowerCase() + '.' + (el.className || '').toString().slice(0, 60);
      }
    });
  }

  document.querySelectorAll('.kbb-gsec').forEach((sec) => {
    const grid = sec.querySelector('.gs-grid');
    if (!grid) return;
    const cs = getComputedStyle(grid);
    const cells = Array.from(grid.children);
    const heading = sec.querySelector('.gs-head h2');
    out.sections.push({
      heading: heading ? heading.textContent.trim() : '(no heading)',
      display: cs.display,
      cols: cs.gridTemplateColumns,
      cellWidth: Math.round(cells[0] ? cells[0].getBoundingClientRect().width : 0),
      tilesInDom: cells.length,
      tilesVisible: cells.filter((c) => c.offsetParent !== null).length,
      scrollLeft: grid.scrollLeft,
      maxScrollLeft: grid.scrollWidth - grid.clientWidth,
      /*
       * WHICH WAY THE CAROUSEL ADVANCES, measured rather than asserted.
       *
       * A spec-compliant browser gives an RTL scroller a scrollLeft range of
       * -max…0 and an LTR one 0…+max, so "advances the other way" IS the sign
       * of the reachable offset. Setting +50 and reading back says which:
       * clamped to 0 in RTL, accepted in LTR. Put back immediately so the
       * screenshot below is of a row at rest.
       */
      advances: (() => {
        /*
         * A FULL CARD, not 50px: `scroll-snap-type:x mandatory` pulls any
         * offset back to the nearest snap point, so a nudge smaller than one
         * card reads as "not scrollable" on a row that scrolls perfectly well.
         * Measured — 50 reported that on every row.
         */
        const was = grid.scrollLeft;
        grid.scrollLeft = 400;
        const plus = grid.scrollLeft;
        grid.scrollLeft = -400;
        const minus = grid.scrollLeft;
        grid.scrollLeft = was;
        return plus > 0 ? 'positive (left → right)' : (minus < 0 ? 'negative (right → left)' : 'not scrollable');
      })(),
      dir: getComputedStyle(sec).direction,
      viewAll: (() => {
        const a = sec.querySelector('.gs-all');
        return a ? { text: a.textContent.trim(), href: a.getAttribute('href') } : null;
      })(),
      headingPx: heading ? getComputedStyle(heading).fontSize : null,
    });
  });

  return out;
};

const browser = await chromium.launch({ executablePath: process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });

/*
 * ── THE SELF-TEST, AND IT IS NOT CEREMONY ─────────────────────────────────
 *
 * The first run of this harness reported `display:block` and `cols:none` on
 * every product grid on the page — the shop's own four rails included — and no
 * overflow anywhere, because `php -S` without `-t` had 404'd both stylesheets
 * and there was no layout left to break. A sweep that cannot see the
 * stylesheet cannot fail, and is not evidence. So: the built bundle is asked
 * for directly, and `.kbb-pgrid` is asked whether it is a grid, BEFORE any row
 * below is believed.
 */
{
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const sheets = [];
  page.on('response', (r) => {
    if (r.url().includes('/build/assets/') && r.url().endsWith('.css')) sheets.push([r.url(), r.status()]);
  });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });

  const ok = await page.evaluate(() => {
    const g = document.querySelector('.kbb-pgrid');
    return g ? getComputedStyle(g).display : '(no .kbb-pgrid on the page)';
  });

  const bad = sheets.filter(([, s]) => s !== 200);

  if (sheets.length === 0 || bad.length || ok !== 'grid') {
    console.error('SELF-TEST FAILED — the rig is not serving the shop\'s CSS.');
    console.error('  stylesheets:', sheets);
    console.error('  .kbb-pgrid display:', ok, '(expected "grid")');
    await browser.close();
    process.exit(1);
  }

  console.log(`self-test: ${sheets.length} built stylesheets at 200, .kbb-pgrid computes ${ok}`);
  await page.close();
}

for (const [path, tag] of [['/', 'en'], ['/ar/', 'ar']]) {
  for (const width of [390, 1280]) {
    const page = await browser.newPage({ viewport: { width, height: 1400 } });
    await page.goto(BASE + path, { waitUntil: 'networkidle' });

    const got = await page.evaluate(probe);
    rows.push({ path, tag, width, ...got });

    // The section band alone, which is what the owner is looking at.
    const band = await page.$('.kbb-gsec');
    if (band) {
      /*
         The shop's header is `position:sticky`, so scrollIntoView({block:'start'})
         parks the section exactly underneath it and the element shot comes back
         with its own heading covered. Scrolled to 140px above instead, which is
         taller than the header at either width.
      */
      await page.evaluate(() => {
        const el = document.querySelector('.kbb-gsec');
        if (el) window.scrollTo({ top: window.scrollY + el.getBoundingClientRect().top - 140 });
      });
      await page.waitForTimeout(250);
    }

    /*
       BOTH SECTIONS IN ONE PICTURE, clipped out of a full-page shot rather than
       shot at the viewport. A viewport shot has to be positioned by scrolling,
       and the first version of this parked on an empty band of the page and
       produced 8 KB of flat pink — a picture of nothing, which is worse than no
       picture because it looks like evidence.
    */
    const clip = await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('.kbb-gsec'));
      if (!els.length) return null;
      const tops = els.map((e) => e.getBoundingClientRect().top + window.scrollY);
      const bots = els.map((e) => e.getBoundingClientRect().bottom + window.scrollY);
      return { x: 0, y: Math.max(0, Math.min(...tops) - 12), width: document.documentElement.clientWidth,
               height: Math.max(...bots) - Math.min(...tops) + 24 };
    });

    await page.screenshot({ path: `${OUT}/${tag}-${width}-page.png`, fullPage: true, ...(clip ? { clip } : {}) });

    const secs = await page.$$('.kbb-gsec');
    for (let i = 0; i < secs.length; i++) {
      await secs[i].screenshot({ path: `${OUT}/${tag}-${width}-section-${i + 1}.png` });
    }

    await page.close();
  }
}

await browser.close();

fs.writeFileSync(`${OUT}/measurements.json`, JSON.stringify(rows, null, 2));

for (const r of rows) {
  console.log(`\n=== ${r.tag} ${r.width}px  scrollWidth ${r.scrollWidth} / clientWidth ${r.clientWidth}`
    + (r.worst ? `  OVERFLOW: ${r.worst}` : '  (no horizontal scroll)'));
  for (const s of r.sections) {
    console.log(`   "${s.heading}"  ${s.display}  cols=${s.cols}  cell=${s.cellWidth}px`
      + `  tiles ${s.tilesVisible}/${s.tilesInDom}  scrollLeft=${s.scrollLeft} max=${s.maxScrollLeft}`
      + `  dir=${s.dir} advances=${s.advances}  h2=${s.headingPx}`
      + (s.viewAll ? `  viewAll="${s.viewAll.text}" -> ${s.viewAll.href}` : '  viewAll=none'));
  }
}
