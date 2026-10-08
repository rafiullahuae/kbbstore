/*
 * Lane GX: contact sheet of what the gallery frame showed after a tap, from
 * the frames tools/gx-gallery.cjs saved (SHOTS_DIR/<label>-<profile>-<at>/).
 *
 *   node tools/gx-sheet.cjs SHOTS_DIR OUT.png PROFILE "labelA=Before,labelB=After" [at,at]
 *
 * One row per label x tap moment, one column per 50/150/300/600/1000 ms after
 * the finger went down; each cell is the gallery region of the real frame,
 * and under it the edge of the photographed bottle at ONE DEVICE PIXEL PER
 * PIXEL (phone) or 2x (laptop), which is where a stretched stand-in shows.
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const [DIR, OUT, PROF, LABELS, ATS] = process.argv.slice(2);
const labels = LABELS.split(',').map((x) => x.split('='));
const ats = (ATS || 'early,late').split(',');
const MS = ['0050', '0150', '0300', '0600', '1000'];
const phone = PROF.startsWith('phone');
// the gallery region of a frame, as fractions of the frame (top, height)
const crop = phone ? [0.12, 0.62] : [0.08, 0.92];

(async () => {
  const rows = [];
  for (const at of ats) for (const [label, name] of labels) {
    const d = path.join(DIR, label + '-' + PROF + '-' + at);
    const cells = MS.map((ms) => { const f = path.join(d, ms + 'ms.jpg'); return fs.existsSync(f) ? 'data:image/jpeg;base64,' + fs.readFileSync(f).toString('base64') : ''; });
    rows.push({ title: name + ' -- tap ' + (at === 'early' ? 'soon after the page loads' : '7 s after load'), cells });
  }
  const W = phone ? 230 : 300;
  // the crop: device-pixel frame coordinates of the bottle's left edge, 40%
  // down the gallery box (phone frames 1170x2532, laptop 1280x800 at 2x)
  const Z = phone ? 'background-size:1170px 2532px;background-position:-' + (420 - W / 2) + 'px -' + (850 - W * 0.3) + 'px'
    : 'background-size:2560px 1600px;background-position:-' + (500 - W / 2) + 'px -' + (600 - W * 0.3) + 'px';
  const html = `<!doctype html><meta charset="utf-8"><style>
    body{margin:0;padding:16px;font:14px/1.3 system-ui,sans-serif;background:#fff;color:#222}
    h1{font-size:16px;margin:0 0 10px} .r{margin:0 0 14px} .t{font-weight:600;margin:0 0 6px}
    .g{display:grid;grid-template-columns:repeat(${MS.length},${W}px);gap:8px}
    .c{width:${W}px;height:${Math.round(W * (phone ? 1.05 : 0.62))}px;overflow:hidden;position:relative;border:1px solid #ddd;background:#f6f6f6}
    .c img{position:absolute;left:0;width:100%;top:${-crop[0] * 100 / (crop[1] - crop[0])}%;height:auto;transform-origin:top left}
    .m{font-size:12px;color:#666;margin:2px 0 0}
    .z{width:${W}px;height:${Math.round(W * 0.6)}px;border:1px solid #ddd;margin-top:4px;background-repeat:no-repeat;image-rendering:pixelated}
  </style><h1>Gallery tap, ${PROF} -- frames at 50 / 150 / 300 / 600 / 1000 ms after the finger</h1>
  ${rows.map((r) => `<div class="r"><div class="t">${r.title}</div><div class="g">${r.cells.map((c, i) => `<div><div class="c">${c ? `<img src="${c}">` : ''}</div>${c ? `<div class="z" style="background-image:url(${c});${Z}"></div>` : ''}<div class="m">${Number(MS[i])} ms</div></div>`).join('')}</div></div>`).join('')}`;
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 16 * 2 + MS.length * (W + 8), height: 400 } });
  await p.setContent(html);
  await p.screenshot({ path: OUT, fullPage: true });
  await b.close();
})();
