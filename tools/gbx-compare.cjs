/* Lane GBX: one compare sheet per family from the shots in docs/gbx-previews
   (run tools/gbx-shots.cjs first). node tools/gbx-compare.cjs */
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const DOCS = path.resolve(__dirname, '../docs/gbx-previews');
const sheets = {
  popup: { title: 'Gift photo popup — choose A, B, C or D', rows: [['A', 'A · compact card (sheet on phone)'], ['B', 'B · photo on top, pink FREE badge'], ['C', 'C · card inside the box, under the photo'], ['D', 'D · minimal luxe, value struck → FREE']],
    cols: [['panel-en-390', 'Cart panel · 390'], ['checkout-en-390', 'Checkout · 390'], ['panel-en-1280', 'Cart panel · 1280'], ['panel-ar-390', 'Arabic · 390']], file: (r, c) => `${r}-${c}.png` },
  criteria: { title: 'Gifts Criteria — choose 1, 2 or 3', rows: [['box', 'The box: View all left, Gifts Criteria right'], ['1', '1 · stacked tier cards'], ['2', '2 · compact ladder'], ['3', '3 · step tabs']],
    cols: [['panel-en-390', 'Cart panel · 390'], ['checkout-en-390', 'Checkout · 390'], ['panel-en-1280', 'Cart panel · 1280'], ['panel-ar-390', 'Arabic · 390']], file: (r, c) => r === 'box' ? `box-${c}.png` : `${r}-${c}.png` },
  glow: { title: 'Photo glow — choose G1, G2, G3 or G4 (G0 = today)', rows: [['G0', 'G0 · today, no glow'], ['G1', 'G1 · soft pink halo'], ['G2', 'G2 · thin pink ring + faint glow'], ['G3', 'G3 · white inner ring + blush glow'], ['G4', 'G4 · soft pink + champagne']],
    cols: [['panel-en-390', 'Cart panel · 390'], ['checkout-en-390', 'Checkout · 390'], ['home-en-390', 'Homepage rows · 390'], ['panel-en-1280', 'Cart panel · 1280']], file: (r, c) => `${r}-${c}.png` },
};
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  for (const [fam, s] of Object.entries(sheets)) {
    const dir = path.join(DOCS, fam);
    const cell = (r, c) => { const f = path.join(dir, s.file(r, c)); return fs.existsSync(f) ? `<img src="file://${f}">` : '<i>—</i>'; };
    const html = `<!doctype html><meta charset="utf-8"><style>body{margin:0;padding:24px;font:14px/1.4 system-ui,sans-serif;background:#f6f2f4;color:#221a20}h1{font-size:22px;margin:0 0 16px}
      table{border-collapse:separate;border-spacing:12px}th{text-align:start;font-size:13px;color:#5e545a}td{vertical-align:top;background:#fff;border-radius:10px;padding:8px}
      td img{display:block;width:320px;height:auto;max-height:640px;object-fit:contain;object-position:top}td.h{background:none;font-weight:700;width:150px;font-size:15px}</style>
      <h1>${s.title}</h1><table><tr><th></th>${s.cols.map((c) => `<th>${c[1]}</th>`).join('')}</tr>
      ${s.rows.map((r) => `<tr><td class="h">${r[1]}</td>${s.cols.map((c) => `<td>${cell(r[0], c[0])}</td>`).join('')}</tr>`).join('')}</table>`;
    const p = await browser.newPage({ viewport: { width: 1600, height: 900 } });
    const tmp = path.join(dir, '.compare.html');
    fs.writeFileSync(tmp, html);
    await p.goto('file://' + tmp, { waitUntil: 'load' });
    fs.unlinkSync(tmp);
    await p.screenshot({ path: path.join(dir, 'compare.png'), fullPage: true });
    await p.close();
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
