/*
 * The contact sheet — today's box and the three proposed treatments, side by
 * side, one image per width.
 *
 *   node tools/spl-box-sheet.cjs <shotDir> [prefix]
 *
 * ── WHY THIS IS THE PICTURE TO SEND ────────────────────────────────────────
 *
 * Twice now the owner has been shown design options one file at a time and both
 * times his verdict was that they were the same as each other — which is the one
 * way of looking at a set of proposals that CANNOT show you how they differ.
 * Four boxes at the same scale, on one image, with today's first, answers it in
 * a second: if two columns are hard to tell apart at this size, one of them is
 * wrong and it is the designer's job to notice, not his.
 *
 * TODAY IS IN THE LINE-UP AND IS FIRST. Without it "different" and "better" are
 * the same word.
 */
const { chromium } = require('playwright');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const COLUMNS = [
  { key: 'now', name: 'Today', line: 'A bare list on the page. Hairline between every row, no container at all.' },
  { key: 't1', name: '1 · Filled tint', line: 'One cream panel. No border, no shadow, no row rules — the white photo chips make the beat.' },
  { key: 't2', name: '2 · White card', line: 'A white card with a hairline, a soft shadow, a blush header strip and a cream footer.' },
  { key: 't3', name: '3 · Hanging photos', line: 'A blush panel with the photographs standing 10px outside its edge. No rules at all.' },
];

// How wide each column's BOX IMAGE is drawn. The 1280 box is 582px of real
// estate and the 390 one is 346; both are drawn with 16px of air around them.
const COLUMN = { 390: 320, 1280: 470 };

function dataUri(file) {
  return 'data:image/png;base64,' + fs.readFileSync(file).toString('base64');
}

(async () => {
  const [shotDir, prefix = ''] = process.argv.slice(2);
  const m = JSON.parse(fs.readFileSync(path.join(shotDir, `${prefix}box-measurements.json`), 'utf8'));

  /* ── FOUR DIFFERENT PICTURES, CHECKED BEFORE THEY ARE ARRANGED ──────────
     The question this sheet exists to answer is "are these actually
     different?", and the one failure that would answer it falsely is two
     columns showing the same image — a treatment whose CSS did not apply, a
     shot overwritten by a later run, or a file copied over another. All three
     have happened in this project. A byte-identical pair is refused here
     rather than quietly arranged into a sheet that says the proposals are the
     same, which is the sentence the owner has already sent back twice. */
  for (const width of [390, 1280]) {
    const seen = new Map();

    for (const c of COLUMNS) {
      const file = path.join(shotDir, `${prefix}box-long-${c.key}-${width}.png`);
      const hash = crypto.createHash('md5').update(fs.readFileSync(file)).digest('hex');

      if (seen.has(hash)) {
        console.error(
          `REFUSED — ${prefix}${c.key} and ${prefix}${seen.get(hash)} at ${width}px are the SAME IMAGE.\n`
          + '  Either a treatment did not apply, or one shot overwrote another. '
          + 'A contact sheet built from these would say the proposals are identical.'
        );
        process.exit(1);
      }

      seen.set(hash, c.key);
    }
  }

  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [390, 1280]) {
    const col = COLUMN[width];

    const cells = COLUMNS.map((c) => {
      const long = m[`${prefix}long-${c.key}-${width}`] || {};
      const short = m[`${prefix}short-${c.key}-${width}`] || {};
      const src = dataUri(path.join(shotDir, `${prefix}box-long-${c.key}-${width}.png`));
      const srcShort = dataUri(path.join(shotDir, `${prefix}box-short-${c.key}-${width}.png`));

      return `
        <section class="col">
          <header>
            <b>${c.name}</b>
            <i>${c.line}</i>
          </header>
          <dl class="nums">
            <div><dt>photo</dt><dd>${long.photo ?? '—'}px</dd></div>
            <div><dt>row pad</dt><dd>${long.rowPadding ?? '—'}px</dd></div>
            <div><dt>row height</dt><dd>${long.rowHeightAvg ?? '—'}px</dd></div>
            <div><dt>block, 8 members</dt><dd>${long.blockHeight ?? '—'}px</dd></div>
            <div><dt>block, 3</dt><dd>${short.blockHeight ?? '—'}px</dd></div>
            <div><dt>scrollWidth</dt><dd>${long.scrollWidth ?? '—'}px</dd></div>
          </dl>
          <div class="shot"><img src="${src}" alt=""></div>
          <p class="cap">the same box with three members</p>
          <div class="shot"><img src="${srcShort}" alt=""></div>
        </section>`;
    }).join('');

    const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#EFE9EC;font-family:Poppins,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
       color:#2A2228;padding:32px}
  h1{font-size:24px;font-weight:700;letter-spacing:-.01em}
  h1 span{font-weight:400;color:#8C828A}
  .sheet{display:flex;gap:22px;align-items:flex-start;margin-top:20px}
  .col{width:${col}px;flex:none;background:#fff;border-radius:14px;overflow:hidden;
       box-shadow:0 10px 30px -16px rgba(42,34,40,.42)}
  header{padding:14px 14px 10px}
  header b{display:block;font-size:16px;font-weight:700}
  header i{display:block;font-size:11.5px;font-style:normal;color:#6E646A;line-height:1.45;margin-top:4px}
  .nums{display:flex;flex-wrap:wrap;gap:6px 12px;padding:0 14px 12px;
        border-bottom:1px solid rgba(42,34,40,.1)}
  .nums div{min-width:64px}
  .nums dt{font-size:8.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#9A9096}
  .nums dd{font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;margin-top:1px}
  .shot{padding:10px 10px 0}
  .shot img{display:block;width:100%;height:auto}
  .cap{font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#9A9096;
       font-weight:700;padding:14px 14px 0}
  .col > .shot:last-child{padding-bottom:12px}
</style></head>
<body>
  <h1>&ldquo;What is in this set&rdquo; &mdash; today and three treatments <span>&middot; ${width}px wide${prefix === 'ar-' ? ' &middot; Arabic' : ''}</span></h1>
  <div class="sheet">${cells}</div>
</body></html>`;

    const page = await browser.newPage({
      viewport: { width: 4 * col + 3 * 22 + 64, height: 1200 },
      deviceScaleFactor: 1,
    });

    await page.setContent(html, { waitUntil: 'networkidle' });
    await page.screenshot({
      path: path.join(shotDir, `${prefix}contact-sheet-${width}.png`),
      fullPage: true,
    });
    await page.close();

    console.log(`contact sheet ${prefix}${width} written`);
  }

  await browser.close();
})();
