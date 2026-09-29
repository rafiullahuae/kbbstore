/*
 * LANE PG2 — the contact sheets.
 *
 * The owner asked to be given previews to choose from, side by side. So the
 * panels are laid out as one image per width, with TODAY'S card in the first
 * column: a treatment is only worth choosing against what the shop draws now,
 * and four unlabelled cards with nothing to compare them to is the sheet he has
 * twice sent back for being too alike.
 *
 * TWO SHEETS PER WIDTH, because one cannot answer both questions:
 *
 *   contact-sheet-<w>.png   the five treatments side by side, cards AT THEIR
 *                           RENDERED SIZE — the first two columns of each row,
 *                           which is as much as fits legibly beside four others
 *   row-sheet-<w>.png       the five FULL catalogue rows stacked, five columns
 *                           at 1280 and two at 390, so the row's own rhythm and
 *                           the equal card heights can be seen rather than
 *                           taken on trust
 *
 * Nothing here re-renders a card: it places PNGs taken from the real pages, so
 * a sheet cannot show a card the browser did not draw. The panels differ only
 * by `grid_skin` — same fixture, same page, same width, same server.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const DIR = process.env.KBB_SHOTS || '/home/user/lane-pg2/docs/pg2-shots';
const SUFFIX = process.env.KBB_SUFFIX || '';

const COLUMNS = [
  ['classic', 'TODAY · Classic card', 'what the shop draws now'],
  ['showcase', 'A · Showcase', 'faithful to the screenshot — a full-width uppercase button with the heart beside it'],
  ['showcase-compact', 'B · Showcase Compact', 'tighter and with no card outline at all: less padding, smaller type, a shorter button'],
  ['showcase-row', 'C · Showcase Row', 'the price and the button share the last line; the heart goes on the photograph'],
  ['showcase-airy', 'D · Showcase Airy', 'more air, no border, a soft shadow and an outlined button that fills on hover'],
];

const head = `<style>
  *{box-sizing:border-box}
  body{margin:0;background:#F4F1F2;font:14px/1.45 -apple-system,Segoe UI,Roboto,sans-serif;color:#2A2228}
  .wrap{padding:24px;display:inline-block}
  h1{font-size:20px;margin:0 0 4px}
  .sub{color:#6A5C64;font-size:13px;margin:0 0 22px;max-width:1100px}
  .cap{font-weight:700;font-size:13.5px;margin:0 0 3px}
  .why{color:#6A5C64;font-size:11.5px;margin:0 0 9px}
  .shot{background:#fff;border-radius:10px;padding:10px;box-shadow:0 2px 12px rgba(42,34,40,.10);overflow:hidden}
  .shot img{display:block;max-width:none}
  .strip{display:flex;gap:20px;align-items:flex-start}
  .stack .row{margin:0 0 22px}
  .stack .why{min-height:0}
</style>`;

/* Side by side: each column is a WINDOW onto the panel, so the cards inside it
   are at the size the browser drew them and not scaled to fit a column. */
const contact = (width, window_, captionWidth) => `<!doctype html><html><head><meta charset="utf-8">${head}</head>
<body><div class="wrap">
  <h1>Product card &mdash; ${width}px, side by side</h1>
  <p class="sub">The same twelve products, the same category page, the same width. The only difference
     between these columns is <b>Appearance &rarr; Product styles &rarr; Layout &rarr; Default card style</b>.
     Cards are at their rendered size; each column shows the first two of the row.</p>
  <div class="strip">
    ${COLUMNS.map(([key, label, why]) => `<div style="width:${captionWidth}px;flex:none">
      <p class="cap">${label}</p>
      <p class="why" style="min-height:46px">${why}</p>
      <div class="shot" style="width:${captionWidth}px"><div style="width:${window_}px;overflow:hidden"><img src="panel-${key}${SUFFIX}-${width}.png"></div></div>
    </div>`).join('')}
  </div>
</div></body></html>`;

/* Stacked: the whole row, so the column count, the gaps and the equal card
   heights are visible in one look. */
const rows = (width) => `<!doctype html><html><head><meta charset="utf-8">${head}</head>
<body><div class="wrap stack">
  <h1>Product card &mdash; ${width}px, the full catalogue row</h1>
  <p class="sub">${width === 390 ? 'Two columns on a phone' : 'Five columns on a desktop'}, which is what the
     owner asked for and what the grid derives from the row it really has. Every card in a row is the same
     height whatever its name says; a product with no reviews draws no rating line at all.</p>
  ${COLUMNS.map(([key, label, why]) => `<div class="row">
    <p class="cap">${label}</p>
    <p class="why">${why}</p>
    <div class="shot" style="display:inline-block"><img src="panel-${key}${SUFFIX}-${width}.png"></div>
  </div>`).join('')}
</div></body></html>`;

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });

  // The window is two cards wide at each width: (row - 4*gap)/5 * 2 + gap at
  // 1280, and the whole 2-column row at 390 (which is already two cards).
  for (const [width, window_, captionWidth] of [[390, 350, 350], [1280, 480, 480]]) {
    for (const [kind, html] of [['contact', contact(width, window_, captionWidth)], ['row', rows(width)]]) {
      const file = `${DIR}/${kind}-${width}.html`;
      fs.writeFileSync(file, html);
      const page = await browser.newPage({ viewport: { width: 400, height: 400 }, deviceScaleFactor: 1 });
      await page.goto('file://' + file, { waitUntil: 'networkidle' });
      const el = await page.$('.wrap');
      const name = kind === 'contact' ? `contact-sheet-${width}${SUFFIX}` : `row-sheet-${width}${SUFFIX}`;
      await el.screenshot({ path: `${DIR}/${name}.png` });
      console.log(`${name}.png`);
      await page.close();
    }
  }

  await browser.close();
})();
