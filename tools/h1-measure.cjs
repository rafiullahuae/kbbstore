/*
 * Lane H1 — desktop header width sweep.
 *
 *   node tools/h1-measure.cjs <base> <label> <widths,comma> [pages,comma]
 *
 * Per (page, width) it reports the numbers CLAUDE.md rule 2 asks for:
 *
 *   - documentElement.clientWidth vs scrollWidth — the overflow claim, plus
 *     the name of every element whose content is wider than the viewport.
 *   - the header container's width and the first page container's width — the
 *     owner's "header need to be matched the width" claim. On desktop the two
 *     must be the same number.
 *   - the number of ROWS the nav occupies — the "without line breaking" claim.
 *
 *     ROWS ARE CLUSTERED, NOT COUNTED AS DISTINCT offsetTop VALUES, and the
 *     first draft of this got it wrong in a way that read as a real defect.
 *     `.mbar .wrap` is align-items:center and its items are NOT the same
 *     height — the two with a "▾" caret are a fraction taller — so the items
 *     on ONE row sit at two or three different offsetTops, a pixel or two
 *     apart. Counting distinct values reported "2 rows" and "4 rows" for bars
 *     measured 45.5px tall, which is one row of a 45.5px bar. Cluster the
 *     tops with a tolerance of half the tallest item instead: two items are on
 *     the same row when their boxes overlap vertically at all, which is what a
 *     row means.
 *
 *   - the computed font-size, side padding and gap of .navlink — the
 *     "reduce the sizes ... same layout" claim.
 *
 * Read-only: it renders URLs and writes nothing but stdout.
 */
const { chromium } = require('playwright');

const DEFAULT_PAGES = ['/', '/shop/', '/best-sellers/', '/new-in/', '/super-sale/'];

(async () => {
  const base = process.argv[2];
  const label = process.argv[3] || 'run';
  const widths = (process.argv[4] || '1280').split(',').map(Number);
  const pages = (process.argv[5] || '').trim()
    ? process.argv[5].split(',')
    : DEFAULT_PAGES;

  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const rows = [];

  for (const path of pages) {
    for (const w of widths) {
      const page = await browser.newPage({ viewport: { width: w, height: 900 } });
      await page.goto(base + path, { waitUntil: 'networkidle' });
      // nav-fit.js debounces resize at 80ms and re-runs on document.fonts.ready.
      await page.waitForTimeout(500);

      const m = await page.evaluate(() => {
        const el = document.documentElement;
        const px = (v) => Math.round(parseFloat(v) * 100) / 100;

        /* Rows, by vertical overlap. See the note at the top of this file. */
        const rowsOf = (kids) => {
          const boxes = kids
            .filter(k => k.getClientRects().length)
            .map(k => k.getBoundingClientRect());
          if (!boxes.length) return 0;
          const tol = Math.max(...boxes.map(b => b.height)) / 2;
          const tops = boxes.map(b => b.top).sort((a, b) => a - b);
          let n = 1;
          let anchor = tops[0];
          for (const t of tops) {
            if (t - anchor > tol) { n++; anchor = t; }
          }
          return n;
        };

        const header = document.querySelector('header');
        const hwrap = header && header.querySelector(':scope > .wrap');
        const pageWrap = document.querySelector('main .wrap') || document.querySelector('main');
        const mbar = document.querySelector('.mbar');
        const nav = document.querySelector('.mbar .wrap');
        const items = nav ? [...nav.querySelectorAll(':scope > .navitem')] : [];
        const link = document.querySelector('.navlink');
        const cs = link ? getComputedStyle(link) : null;
        const navCs = nav ? getComputedStyle(nav) : null;
        const hin = header && header.querySelector('.hin');
        const hinKids = hin ? [...hin.children].filter(c => getComputedStyle(c).display !== 'none') : [];

        const over = [];
        document.querySelectorAll('*').forEach(n => {
          if (n.scrollWidth > n.clientWidth + 1 && n.scrollWidth > el.clientWidth) {
            over.push(n.tagName + '.' + String(n.className).slice(0, 24) + ' ' + n.scrollWidth + '/' + n.clientWidth);
          }
        });

        return {
          clientWidth: el.clientWidth,
          scrollWidth: el.scrollWidth,
          overflow: el.scrollWidth - el.clientWidth,
          headerWrap: hwrap ? px(hwrap.getBoundingClientRect().width) : null,
          headerWrapMax: hwrap ? getComputedStyle(hwrap).maxWidth : null,
          pageWrap: pageWrap ? px(pageWrap.getBoundingClientRect().width) : null,
          navDisplay: mbar ? getComputedStyle(mbar).display : null,
          navItems: items.length,
          navRows: rowsOf(items),
          navBarH: nav ? px(nav.getBoundingClientRect().height) : null,
          navScale: mbar ? (getComputedStyle(mbar).getPropertyValue('--nav-scale') || '').trim() : null,
          navFlexWrap: navCs ? navCs.flexWrap : null,
          navGap: navCs ? navCs.columnGap : null,
          linkFont: cs ? px(cs.fontSize) : null,
          linkPadX: cs ? px(cs.paddingLeft) : null,
          linkGap: cs ? px(cs.gap) : null,
          linkLineH: cs ? cs.lineHeight : null,
          hinRows: hin ? rowsOf(hinKids) : null,
          hinH: hin ? px(hin.getBoundingClientRect().height) : null,
          hinfo: (() => { const n = document.querySelector('.hinfo'); return n ? getComputedStyle(n).display : 'absent'; })(),
          logoFont: (() => { const n = document.querySelector('header .logo'); return n ? px(getComputedStyle(n).fontSize) : null; })(),
          iconSize: (() => { const n = document.querySelector('header .ib svg'); return n ? px(getComputedStyle(n).width) : null; })(),
          searchW: (() => { const n = document.querySelector('header .sbox'); return n ? px(n.getBoundingClientRect().width) : null; })(),
          burger: (() => { const n = document.querySelector('.kbbmi'); return n ? getComputedStyle(n).display : 'absent'; })(),
          over: over.slice(0, 6),
        };
      });

      rows.push({ page: path, width: w, ...m });
      await page.close();
    }
  }

  console.log(JSON.stringify({ label, rows }, null, 1));
  await browser.close();
})();
