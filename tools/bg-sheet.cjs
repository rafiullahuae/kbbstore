/*
 * The contact sheets — today and the four treatments, one image per width.
 *
 *   node tools/bg-sheet.cjs [shotDir]
 *
 * ── WHY THIS IS THE PICTURE TO SEND ────────────────────────────────────────
 *
 * The owner has twice sent design options back for being too alike, in those
 * words, and both times he had been shown them one file at a time — which is
 * the one way of looking at a set of proposals that CANNOT show you how they
 * differ. Five columns at the same scale on one image, with today's first,
 * answers it in a second: if two columns are hard to tell apart at this size,
 * one of them is wrong, and noticing that is the designer's job and not his.
 *
 * TODAY IS IN THE LINE-UP AND IS FIRST. Without it "different" and "better" are
 * the same word.
 *
 * THE CYCLE IS ON THE SHEET TOO. A still of a moving thing proves nothing about
 * the rest of it, so the second band is each treatment at 0%, 33% and 66% of
 * its own cycle — which is also what shows that (a) barely travels and (b)
 * travels a long way, a difference no single frame can carry.
 *
 * ── THE CHECK THAT RUNS BEFORE ANYTHING IS ARRANGED ────────────────────────
 *
 * Copied from tools/spl-box-sheet.cjs, which introduced it for the same reason:
 * the one failure that would answer "are these actually different?" falsely is
 * two columns showing the SAME image — a treatment whose CSS did not apply, a
 * shot overwritten by a later run, a file copied over another. All three have
 * happened on this project. A byte-identical pair is refused here rather than
 * quietly arranged into a sheet that says the proposals are the same.
 */
const { chromium } = require('playwright');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const APP = path.resolve(__dirname, '..');
const DIR = process.argv[2] || `${APP}/docs/bg-shots`;

const COLUMNS = [
  ['now', 'Today', 'What the shop sends now. Pink botanical on the home page and the cart; plain white on /shop and a product page — two backgrounds, not one.'],
  ['a', 'A · Cream drift', 'Ivory → blush → lilac. Seven minutes a cycle and the smallest travel of the four. The one nobody catches happening.'],
  ['b', 'B · Blossom', 'Peach → rose → pearl. Two minutes, full travel: each moment leads with a different colour.'],
  ['c', 'C · Mint morning', 'Mint → cream → blush. Four minutes, most of the travel. The only one with green in it.'],
  ['d', 'D · Cool header', 'Lilac → sky → pearl, behind the header only, faded out by mid-screen. The rest of the page stays near-white.'],
];

const ROWS = [
  ['home', 'Home'],
  ['shop', '/shop/'],
  ['product', 'A product'],
  ['cart', 'The cart'],
  ['journal', 'The journal'],
];

const THUMB = { 390: 150, 1280: 232 };

function uri(file) {
  return 'data:image/png;base64,' + fs.readFileSync(file).toString('base64');
}

function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [390, 1280]) {
    /* ── FIVE DIFFERENT PICTURES, CHECKED BEFORE THEY ARE ARRANGED ──────── */
    const clashes = [];

    for (const [rk] of ROWS) {
      const seen = new Map();

      for (const [ck, cname] of COLUMNS) {
        const file = path.join(DIR, `${ck === 'now' ? 'now-' + rk : ck + '-' + rk}-${width}.png`);
        const hash = crypto.createHash('md5').update(fs.readFileSync(file)).digest('hex');

        if (seen.has(hash)) {
          clashes.push(`${rk} @${width}: "${cname}" is byte-identical to "${seen.get(hash)}"`);
        }
        seen.set(hash, cname);
      }
    }

    if (clashes.length) {
      console.error('REFUSED — two columns are the same picture:\n  ' + clashes.join('\n  '));
      process.exitCode = 1;
      continue;
    }

    const tw = THUMB[width];

    let html = `<!doctype html><meta charset="utf-8"><style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{background:#fff;font:400 13px/1.45 system-ui,sans-serif;color:#2A2228;padding:26px 26px 34px}
      h1{font:700 22px/1.2 system-ui;margin-bottom:4px}
      .sub{color:#6b636a;margin-bottom:18px;max-width:1100px}
      h2{font:700 15px/1.2 system-ui;margin:26px 0 10px;padding-top:14px;border-top:1px solid #eadfe4}
      table{border-collapse:collapse}
      th,td{padding:5px;vertical-align:top}
      th.col{width:${tw + 10}px;text-align:left}
      th.col b{display:block;font:700 13px/1.3 system-ui}
      th.col span{display:block;font:400 11px/1.35 system-ui;color:#6b636a;margin-top:3px}
      th.row{text-align:right;font:600 12px/1.2 system-ui;color:#5E545A;width:82px;padding-top:10px}
      img{display:block;width:${tw}px;border:1px solid #d9ccd3;border-radius:3px}
      .cap{font:400 10px/1.3 system-ui;color:#8C828A;margin-top:3px}
    </style>
    <h1>Page background — ${width}px</h1>
    <div class="sub">Today first, then the four treatments, on five of your own pages with the real
    catalogue behind them. Every frame is Chromium at ${width}px. Nothing here is switched on:
    the wash renders only for somebody signed into the admin, on a URL carrying
    <code>?kbbwash=</code>.</div>
    <table><tr><th class="row"></th>`;

    for (const [, name, line] of COLUMNS) {
      html += `<th class="col"><b>${esc(name)}</b><span>${esc(line)}</span></th>`;
    }
    html += '</tr>';

    for (const [rk, rname] of ROWS) {
      html += `<tr><th class="row">${esc(rname)}</th>`;
      for (const [ck] of COLUMNS) {
        const f = path.join(DIR, `${ck === 'now' ? 'now-' + rk : ck + '-' + rk}-${width}.png`);
        html += `<td><img src="${uri(f)}"></td>`;
      }
      html += '</tr>';
    }
    html += '</table>';

    // ── the cycle ────────────────────────────────────────────────────────
    html += `<h2>How far each one travels — the same /shop/ page at 0%, 33% and 66% of its own cycle</h2>
      <table><tr><th class="row"></th>`;
    for (const pct of [0, 33, 66]) html += `<th class="col"><b>${pct}% through</b></th>`;
    html += '</tr>';

    for (const [ck, name] of COLUMNS.slice(1)) {
      const cycle = { a: 420, b: 120, c: 240, d: 180 }[ck];
      html += `<tr><th class="row">${esc(name.split(' · ')[0])}<br><span style="font-weight:400;color:#8C828A">${cycle}s</span></th>`;
      for (const pct of [0, 33, 66]) {
        html += `<td><img src="${uri(path.join(DIR, `cycle-${ck}-${pct}-${width}.png`))}"></td>`;
      }
      html += '</tr>';
    }
    html += '</table>';

    // ── the wash itself, with nothing on top of it ───────────────────────
    //
    // On a real page most of the pixels are cards, photographs and type, and
    // the wash is the margin. This strip is the wash ALONE at the same three
    // moments -- the exact gradient PageWash::css() paints, read out of the
    // service by tools/bg-css.php rather than restated -- so that "how much
    // does it actually change" has an answer that does not have to be squinted
    // at. The floor colour under each row is the flat colour body carries, and
    // the number beside it is the worst contrast of body text anywhere in that
    // treatment's whole cycle.
    const G = JSON.parse(fs.readFileSync(path.join(DIR, 'gradients.json'), 'utf8'));

    html += `<h2>The wash itself, with nothing on top of it</h2><table><tr><th class="row"></th>`;
    for (const pct of [0, 33, 66]) html += `<th class="col"><b>${pct}% through</b></th>`;
    html += `<th class="col"><b>Worst contrast</b><span>body text against the darkest moment of the whole cycle. Today's shop is 13.11.</span></th></tr>`;

    for (const [ck, name] of COLUMNS.slice(1)) {
      const g = G[ck];
      html += `<tr><th class="row">${esc(name.split(' · ')[0])}<br><span style="font-weight:400;color:#8C828A">${g.cycle}s</span></th>`;
      for (const pct of [0, 33, 66]) {
        html += `<td><div style="width:${tw}px;height:92px;border:1px solid #d9ccd3;border-radius:3px;background:${g.frames[pct]}"></div></td>`;
      }
      html += `<td class="cap" style="padding-top:8px">`
        + `<b style="font:700 15px/1.2 system-ui">${g.contrast['--ink'].toFixed(2)}</b> body text<br>`
        + `${g.contrast['--ink-2'].toFixed(2)} secondary<br>`
        + `${g.contrast['--muted'].toFixed(2)} muted<br>`
        + `${g.contrast['--pink'].toFixed(2)} pink accent<br>`
        + `<span style="color:#5E545A">floor ${esc(g.floor)}</span></td></tr>`;
    }
    html += '</table>';

    // ── reduced motion, the gate, and Arabic ─────────────────────────────
    html += `<h2>Reduced motion · the gate · Arabic</h2><table><tr>
      <th class="col"><b>A, reduced motion</b><span>One still gradient. <code>getAnimations()</code> is empty.</span></th>
      <th class="col"><b>B, reduced motion</b><span>Same.</span></th>
      <th class="col"><b>Signed out, <code>?kbbwash=b</code></b><span>/shop/ exactly as it is today — white. No style block is emitted at all.</span></th>
      <th class="col"><b>/ar/shop/ with the wash</b><span>Mirrored layout, same geometry. The gradients are direction-agnostic and the layers are inset logically.</span></th>
      <th class="col"><b>/ar/shop/ today</b><span>For comparison.</span></th></tr><tr>
      <td><img src="${uri(path.join(DIR, `reduced-a-${width}.png`))}"></td>
      <td><img src="${uri(path.join(DIR, `reduced-b-${width}.png`))}"></td>
      <td><img src="${uri(path.join(DIR, `signedout-b-home-${width}.png`))}"></td>
      <td><img src="${uri(path.join(DIR, `ar-shop-${width}.png`))}"></td>
      <td><img src="${uri(path.join(DIR, `now-ar-shop-${width}.png`))}"></td>
      </tr></table>`;

    const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
    await page.setContent(html, { waitUntil: 'load' });
    await page.screenshot({ path: `${DIR}/contact-sheet-${width}.png`, fullPage: true });
    await page.close();
    console.log(`contact-sheet-${width}.png`);
  }

  await browser.close();
})();
