/**
 * Lane MG — the desktop mega panels, opened and measured, in Chromium.
 *
 *   sh tools/mg-preview.sh            # prints the URL
 *   NODE_PATH=./node_modules node tools/mg-shots.cjs http://127.0.0.1:10960 docs/lane-mg-shots [tag] [widths] [path]
 *
 * For every width and every panel (All Brands, Sunscreens, Skincare, Hair
 * Care): hover the
 * item, then measure the panel against the menu row's CONTENT box, the page's
 * scrollWidth against the window, the link font size, the column widths and
 * rows, and whether the panel stays open while the pointer travels diagonally
 * from the item to the panel's far column. Measuring is the harness's job,
 * in Playwright; none of it is served to a shopper.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.argv[2] || 'http://127.0.0.1:10960';
const OUT = process.argv[3] || 'docs/lane-mg-shots';
const TAG = process.argv[4] || 'after';
const WIDTHS = (process.argv[5] || '1024,1280,1440,1920').split(',').map(Number);
const PATH = process.argv[6] || '/';
const TARGETS = (process.env.KBB_MG_TARGETS || 'All Brands,Sunscreens,Skincare,Hair Care').split(',');
const AR = { 'All Brands': 4, 'Sunscreens': 9, 'Skincare': 10, 'Hair Care': 3 }; // item index (tools/mg-seed.php)

fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
  const rows = [];
  for (const width of WIDTHS) {
    const ctx = await b.newContext({ viewport: { width, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(BASE + PATH, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(300);
    for (const label of TARGETS) {
      const idx = AR[label];
      const item = p.locator('.mbar .wrap > .navitem').nth(idx);
      const link = item.locator('> .navlink');
      await p.mouse.move(5, 880);
      await p.waitForTimeout(250);
      await link.hover();
      await p.waitForTimeout(400);
      const m = await item.evaluate((el) => {
        const wrap = el.parentElement;
        const cs = getComputedStyle(wrap);
        const wb = wrap.getBoundingClientRect();
        const cStart = wb.left + parseFloat(cs.paddingLeft);
        const cEnd = wb.right - parseFloat(cs.paddingRight);
        const drop = el.querySelector(':scope > .drop');
        const d = drop.getBoundingClientRect();
        const l = el.querySelector(':scope > .navlink').getBoundingClientRect();
        // Columns as the reader sees them: links sharing a left edge. In the
        // fitted panel they are multi-column boxes, not elements.
        const linkRects = [...drop.querySelectorAll('.mcol a, .mcol-link')].map((x) => x.getBoundingClientRect());
        const lefts = [...new Set(linkRects.map((r) => Math.round(r.left)))].sort((x, y) => x - y);
        const colW = lefts.map((x) => +Math.max(...linkRects.filter((r) => Math.round(r.left) === x).map((r) => r.width)).toFixed(1));
        const perCol = lefts.map((x) => linkRects.filter((r) => Math.round(r.left) === x).length);
        const a = drop.querySelector('.mcol a, .mcol-link, :scope > a');
        const h = drop.querySelector('.colh');
        const notch = getComputedStyle(el.querySelector(':scope > .navlink'), '::before');
        return {
          cls: el.className,
          container: [+cStart.toFixed(1), +cEnd.toFixed(1)],
          panel: [+d.left.toFixed(1), +d.right.toFixed(1)],
          panelW: +d.width.toFixed(1),
          panelTop: +d.top.toFixed(1), linkBottom: +l.bottom.toFixed(1),
          link: [+l.left.toFixed(1), +l.right.toFixed(1)],
          inside: d.left >= cStart - 0.5 && d.right <= cEnd + 0.5,
          underParent: d.left <= l.left + l.width / 2 && d.right >= l.left + l.width / 2,
          visible: getComputedStyle(drop).visibility,
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
          font: a ? getComputedStyle(a).fontSize : null,
          heading: h ? getComputedStyle(h).fontSize : null,
          columns: lefts.length,
          colWidths: colW,
          linksPerColumn: perCol,
          notch: notch.content !== 'none' && notch.display !== 'none' ? notch.opacity : 'none',
        };
      });
      // Diagonal travel: from the item's centre to the far column's first link.
      const lb = await link.boundingBox();
      const far = await item.evaluate((el, rtl) => {
        const gs = [...el.querySelectorAll('.drop .mcol-group, .drop > a')];
        const g = gs.reduce((x, y) => {
          const a = x.getBoundingClientRect(); const c = y.getBoundingClientRect();
          return (rtl ? c.right > a.right : c.left < a.left) ? y : x;
        });
        const r = g.getBoundingClientRect();
        return { x: rtl ? r.right - 20 : r.left + 20, y: r.top + 12 };
      }, PATH.startsWith('/ar'));
      let held = true;
      const sx = lb.x + lb.width / 2; const sy = lb.y + lb.height / 2;
      for (let i = 1; i <= 24; i++) {
        await p.mouse.move(sx + (far.x - sx) * i / 24, sy + (far.y - sy) * i / 24);
        const v = await item.evaluate((el) => getComputedStyle(el.querySelector(':scope > .drop')).visibility);
        if (v !== 'visible') { held = false; break; }
      }
      await link.hover();
      await p.waitForTimeout(400);
      const box = await item.evaluate((el) => {
        const d = el.querySelector(':scope > .drop').getBoundingClientRect();
        return { bottom: d.bottom };
      });
      const slug = label.toLowerCase().replace(/[^a-z]+/g, '-');
      const file = `${OUT}/${TAG}-${width}-${slug}.png`;
      await p.screenshot({ path: file, clip: { x: 0, y: 0, width, height: Math.min(900, Math.ceil(box.bottom) + 24) } });
      rows.push({ width, label, ...m, diagonalHeld: held, file });
      console.log(JSON.stringify({ width, label, ...m, diagonalHeld: held }));
    }
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/${TAG}-measurements.json`, JSON.stringify(rows, null, 1));
  await b.close();
})();
