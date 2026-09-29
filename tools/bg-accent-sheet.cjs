/*
 * The brand colour, before and after, on the pages the layout never reached.
 *                                                                  (Lane BG)
 *   node tools/bg-accent-sheet.cjs
 *
 * /shop/ is the CONTROL and is first: it follows the owner's colour in both
 * columns, which is what makes the other three rows mean something. A sheet
 * without a control cannot tell "this page changed" from "the screenshot run
 * changed".
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const DIR = process.argv[2] || path.resolve(__dirname, '..', 'docs', 'bg-shots');

const ROWS = [
  ['shop', '/shop/', 'the control — follows the brand colour today'],
  ['quiz', '/skin-quiz/', '12 uses of var(--pink), 14 of var(--pink-deep)'],
  ['reviews', '/reviews/', '8 and 5'],
  ['journal', '/blog/', '2 and 5'],
];

const uri = (f) => 'data:image/png;base64,' + fs.readFileSync(f).toString('base64');
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

/**
 * How many pixels of two PNGs differ VISIBLY.
 *
 * The threshold is 8 of 255 per channel and it is not a fudge. At >2 the
 * control came back 63 pixels "moved" at 390px: a 9x9 patch of the search icon
 * with a maximum channel delta of THREE, which is anti-aliasing shimmer between
 * two renders of the same page. The change this sheet is about is pink to
 * green, which moves whole buttons by more than a hundred.
 */
async function pixelsDiffering(browser, a, b) {
  const page = await browser.newPage();
  const n = await page.evaluate(async ([x, y]) => {
    const load = (src) => new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = src; });
    const [ia, ib] = await Promise.all([load(x), load(y)]);
    if (ia.width !== ib.width || ia.height !== ib.height) return Number.MAX_SAFE_INTEGER;
    const c1 = document.createElement('canvas'), c2 = document.createElement('canvas');
    c1.width = c2.width = ia.width; c1.height = c2.height = ia.height;
    c1.getContext('2d').drawImage(ia, 0, 0); c2.getContext('2d').drawImage(ib, 0, 0);
    const d1 = c1.getContext('2d').getImageData(0, 0, ia.width, ia.height).data;
    const d2 = c2.getContext('2d').getImageData(0, 0, ia.width, ia.height).data;
    let n = 0;
    for (let i = 0; i < d1.length; i += 4) {
      if (Math.abs(d1[i] - d2[i]) > 8 || Math.abs(d1[i + 1] - d2[i + 1]) > 8 || Math.abs(d1[i + 2] - d2[i + 2]) > 8) n++;
    }
    return n;
  }, [uri(a), uri(b)]);
  await page.close();
  return n;
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [390, 1280]) {
    /* The control must be UNCHANGED in both columns and the other three must
       have MOVED. Both halves are checked before anything is arranged: a sheet
       in which the control moved is a sheet measuring the screenshot run, and a
       sheet in which the other rows did not is a sheet saying the fix works
       when it does not.

       COUNTED IN PIXELS, NOT IN BYTES, and that distinction cost a run. The
       first draft md5'd the files, and the control "moved": the two /shop/
       PNGs differed by six bytes and by ZERO PIXELS — PNG encoding is not
       deterministic between runs. Byte-identity still proves two pictures are
       the SAME (it is what tools/bg-sheet.cjs uses, and it caught the journal),
       but byte-DIFFERENCE proves nothing at all about what is on the screen. */
    const complaints = [];

    for (const [key, , ] of ROWS) {
      const moved = await pixelsDiffering(
        browser,
        path.join(DIR, `accent-before-${key}-${width}.png`),
        path.join(DIR, `accent-after-${key}-${width}.png`)
      );

      if (key === 'shop' && moved > 0) {
        complaints.push(`the control (/shop/) moved at ${width}px by ${moved} pixels — this sheet would be measuring the run, not the change`);
      }
      if (key !== 'shop' && moved < 200) {
        complaints.push(`${key} at ${width}px differs by only ${moved} pixels before and after, so the brand colour still does not reach it`);
      }
    }

    if (complaints.length) {
      console.error('REFUSED:\n  ' + complaints.join('\n  '));
      process.exitCode = 1;
      continue;
    }

    const tw = width === 390 ? 190 : 430;
    let html = `<!doctype html><meta charset="utf-8"><style>
      *{box-sizing:border-box;margin:0;padding:0}
      body{background:#fff;font:400 13px/1.45 system-ui,sans-serif;color:#2A2228;padding:26px}
      h1{font:700 21px/1.2 system-ui;margin-bottom:4px}
      .sub{color:#6b636a;margin-bottom:18px;max-width:1000px}
      table{border-collapse:collapse}th,td{padding:6px;vertical-align:top}
      th.col{text-align:left;font:700 13px/1.3 system-ui;width:${tw + 12}px}
      th.row{text-align:right;font:600 12px/1.3 system-ui;width:120px;padding-top:10px;color:#5E545A}
      th.row span{display:block;font:400 11px/1.35 system-ui;color:#8C828A}
      img{display:block;width:${tw}px;border:1px solid #d9ccd3;border-radius:3px}
    </style>
    <h1>The brand colour — ${width}px</h1>
    <div class="sub">The shop's brand colour saved as <b>#2E7D6B</b>. <b>Before</b>, only the pages that
    extend the site layout followed it; the journal, the review wall and the skin quiz kept the shipped
    pink, because <code>StoreComposer</code> is registered for <code>layouts.store</code> and nothing else.
    <b>After</b>, all four follow it. <code>/shop/</code> is the control and is unchanged in both columns.</div>
    <table><tr><th class="row"></th><th class="col">Before</th><th class="col">After</th></tr>`;

    for (const [key, url, note] of ROWS) {
      html += `<tr><th class="row">${esc(url)}<span>${esc(note)}</span></th>`
        + `<td><img src="${uri(path.join(DIR, `accent-before-${key}-${width}.png`))}"></td>`
        + `<td><img src="${uri(path.join(DIR, `accent-after-${key}-${width}.png`))}"></td></tr>`;
    }
    html += '</table>';

    const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
    await page.setContent(html, { waitUntil: 'load' });
    await page.screenshot({ path: `${DIR}/accent-sheet-${width}.png`, fullPage: true });
    await page.close();
    console.log(`accent-sheet-${width}.png`);
  }

  await browser.close();
})();
