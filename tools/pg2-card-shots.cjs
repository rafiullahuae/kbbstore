/*
 * LANE PG2 — the showcase family's evidence.
 *
 * One pass per (skin, width). For each it takes:
 *
 *   a full-page screenshot            what the page looks like
 *   a clipped PANEL of the first row  the tile for the contact sheet
 *   the numbers that decide it        scrollWidth, the rendered column count,
 *                                     every tile's height, the photograph's
 *                                     box, the button's and the heart's boxes,
 *                                     and how many tiles drew a rating row
 *
 * `document.documentElement.scrollWidth` is the number that says the page fits;
 * the tile heights are the number that says a long name did not make its row
 * taller. Both are read in Chromium, not asserted.
 *
 * THIS IS A HARNESS AND NONE OF IT SHIPS. Rule 4 forbids the SHOP's JavaScript
 * from measuring layout — the shop sizes with calc(). Measuring it from outside
 * is the only way to check that the calc() is right.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:8931';
const EXE  = process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
/* DERIVED FROM THIS FILE'S OWN LOCATION, NEVER HARDCODED. tools/pg2-preview.sh
   carries the same note and the same reason: two harnesses in this repository
   became unrunnable because they named their lane's worktree, and that worktree
   is removed the day the branch merges. The screenshots are a deliverable, so
   the thing that produces them has to travel with the branch. */
const OUT = process.env.KBB_SHOTS || require('path').join(__dirname, '..', 'docs', 'pg2-card-shots');
const PLAN = JSON.parse(fs.readFileSync(process.env.KBB_PLAN, 'utf8'));

const probe = () => {
  const de = document.documentElement;
  const box = (el) => {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)];
  };
  const grids = [...document.querySelectorAll('.kbb-pgrid, #grid, .rel')];

  const rows = grids.map((g) => {
    const tracks = getComputedStyle(g).gridTemplateColumns.trim();
    const tiles = [...g.querySelectorAll(':scope > .kbb-tile')];
    const first = tiles[0];

    return {
      sel: g.id ? '#' + g.id : '.' + [...g.classList].join('.'),
      skin: g.getAttribute('data-skin'),
      cols: tracks === 'none' ? 0 : tracks.split(/\s+/).length,
      tiles: tiles.length,
      heights: tiles.map((t) => Math.round(t.getBoundingClientRect().height)),
      widths: tiles.map((t) => Math.round(t.getBoundingClientRect().width)),
      rated: tiles.filter((t) => t.querySelector('.kbb-card-rate')).length,
      struck: tiles.filter((t) => t.querySelector('.kbb-card-reg')).length,
      // The PHOTOGRAPH's frame. `.kbb-card-thumb` is `display:contents` under
      // the showcase family, so it has no box at all and measuring it would
      // report 0x0 on the very skins this file exists to check. The frame is
      // the photograph's own link, which every skin has.
      photo: tiles.map((t) => box(t.querySelector('.kbb-card-shot'))),
      button: box(first && first.querySelector('.kbb-card-cart')),
      heart: box(first && first.querySelector('.heart')),
      name: box(first && first.querySelector('.kbb-card-nm')),
      nameHeights: tiles.map((t) => {
        const n = t.querySelector('.kbb-card-nm');
        return n ? Math.round(n.getBoundingClientRect().height) : null;
      }),
      salePriceColour: (() => {
        const p = document.querySelector('.kbb-card-reg + .kbb-card-price');
        return p ? getComputedStyle(p).color : null;
      })(),
      plainPriceColour: (() => {
        const cell = [...document.querySelectorAll('.cp')].find((c) => !c.querySelector('.kbb-card-reg'));
        const p = cell && cell.querySelector('.kbb-card-price');
        return p ? getComputedStyle(p).color : null;
      })(),
      cartBg: (() => {
        const b = first && first.querySelector('.kbb-card-cart');
        return b ? getComputedStyle(b).backgroundColor : null;
      })(),
      cartRadius: (() => {
        const b = first && first.querySelector('.kbb-card-cart');
        return b ? getComputedStyle(b).borderRadius : null;
      })(),
      // The first row's foot, in page coordinates — what the panel is clipped to.
      firstRowBottom: (() => {
        if (!first) return null;
        const r = first.getBoundingClientRect();
        return Math.round(r.top + window.scrollY + r.height);
      })(),
      gridTop: Math.round(g.getBoundingClientRect().top + window.scrollY),
      gridBox: (() => {
        const r = g.getBoundingClientRect();
        return [Math.round(r.x), Math.round(r.width)];
      })(),
    };
  });

  return {
    scrollWidth: de.scrollWidth,
    clientWidth: de.clientWidth,
    dir: de.getAttribute('dir') || document.body.getAttribute('dir') || 'ltr',
    lang: de.getAttribute('lang'),
    grids: rows,
  };
};

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: EXE });
  const results = [];

  for (const shot of PLAN) {
    const ctx = await browser.newContext({
      viewport: { width: shot.w, height: shot.h || 1400 },
      deviceScaleFactor: 1,
    });
    const page = await ctx.newPage();
    await page.goto(BASE + shot.path, { waitUntil: 'networkidle' });
    const data = await page.evaluate(probe);

    await page.screenshot({ path: `${OUT}/${shot.name}.png`, fullPage: true });

    // The PANEL: the grid's first row, which is what a contact sheet compares.
    const g = (data.grids || []).find((r) => r.tiles > 0);
    if (g && shot.panel) {
      await page.screenshot({
        path: `${OUT}/${shot.panel}.png`,
        clip: {
          x: Math.max(0, g.gridBox[0] - 2),
          y: Math.max(0, g.gridTop - 2),
          width: Math.min(shot.w - Math.max(0, g.gridBox[0] - 2), g.gridBox[1] + 4),
          height: g.firstRowBottom - g.gridTop + 4,
        },
      });
    }

    results.push({ name: shot.name, path: shot.path, viewport: shot.w, skin: shot.skin, ...data });
    await ctx.close();
  }

  await browser.close();

  const file = `${OUT}/${process.env.KBB_MEASURE || 'measurements'}.json`;
  fs.writeFileSync(file, JSON.stringify(results, null, 2));

  for (const r of results) {
    const g = (r.grids || []).find((x) => x.tiles > 0) || {};
    const h = g.heights || [];
    const equal = h.length ? new Set(h.slice(0, g.cols || h.length)).size === 1 : null;
    const sq = (g.photo || []).filter(Boolean).every((b) => b[2] === b[3]);
    console.log(
      `${r.name.padEnd(30)} vw=${String(r.viewport).padStart(4)} sw=${r.scrollWidth}/${r.clientWidth}` +
      ` cols=${g.cols} rated=${g.rated}/${g.tiles} struck=${g.struck} rowEqual=${equal}` +
      ` h=[${h.slice(0, 5).join(',')}] square=${sq} btn=${(g.button || []).slice(2).join('x')}` +
      ` heart=${(g.heart || []).slice(2).join('x') || '-'} sale=${g.salePriceColour} plain=${g.plainPriceColour}`
    );
  }
})();
