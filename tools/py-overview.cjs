/*
 * Lane PY: compose docs/py-options/overview.png (laptop, 1280 shots) and
 * docs/py-options/overview-phone.png (390 shots) from the screenshots
 * tools/py-shots.cjs took -- one labelled sheet the owner can answer with a
 * letter and a number ("B + 3").
 *
 *   node tools/py-overview.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const DIR = path.resolve(__dirname, '..', 'docs/py-options');
const M = JSON.parse(fs.readFileSync(path.join(DIR, 'measurements.json'), 'utf8'));

const img = (f) => 'data:image/png;base64,' + fs.readFileSync(path.join(DIR, f + '.png')).toString('base64');
const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const BOXES = [['a', 'A · Blush icons', true], ['b', 'B · Cream icons'], ['c', 'C · Mint icons'], ['d', 'D · Lilac icons'], ['e', 'E · Plain soft colour (no icons)'], ['f', 'F · My own colours']];
const TREATMENTS = [['1', '1 · Soft shadow'], ['2', '2 · Dark fade on the text side'], ['3', '3 · Frosted panel'], ['4', '4 · Solid label'], ['5', '5 · None']];
const GROUNDS = [['dark', 'Busy, dark picture'], ['light', 'Light picture'], ['box', 'No picture: the light box']];

function cr(key) {
    const m = M[key];
    return m && m.contrast ? `title contrast ${m.contrast.worst}:1 worst, ${m.contrast.median}:1 typical` : '';
}

function sheet(w) {
    const phone = w === 390;
    const cell = (file, label, note, isDefault) => `<figure class="c">
        <figcaption><b>${esc(label)}</b>${isDefault ? ' <span class="d">shipped</span>' : ''}</figcaption>
        <img src="${img(file)}" alt="">
        ${note ? `<small>${esc(note)}</small>` : ''}
      </figure>`;

    const boxes = BOXES.map(([l, label, d]) => cell(`box-${l}-${w}`, label, cr(`box-${l}-${w}`), d)).join('');

    const rows = TREATMENTS.map(([n, label]) => `<div class="row"><div class="rh">${esc(label)}</div>`
        + GROUNDS.map(([g]) => cell(`t${n}-${g}-${w}`, '', cr(`t${n}-${g}-${w}`),
            (n === '1' && g !== 'box') || (n === '5' && g === 'box'))).join('')
        + '</div>').join('');

    const aligns = ['start', 'center', 'end'].map((a) => `<div class="row"><div class="rh">${a === 'start' ? 'Start (left in English, right in Arabic)' : a === 'center' ? 'Centred' : 'End'}</div>`
        + cell(`align-${a}-dark-${w}`, '', '', a === 'start')
        + cell(`align-${a}-box-${w}`, '', '', a === 'start')
        + cell(`align-${a}-box-ar-${w}`, '', 'Arabic, right to left', a === 'start')
        + '</div>').join('');

    const shipped = `<div class="row s4">`
        + cell(`after-picture-en-${w}`, 'English · picture', '')
        + cell(`after-box-en-${w}`, 'English · no picture', '')
        + cell(`after-picture-ar-${w}`, 'Arabic · picture', '')
        + cell(`after-box-ar-${w}`, 'Arabic · no picture', '')
        + '</div>';

    return `<!doctype html><html><head><meta charset="utf-8"><style>
      *{box-sizing:border-box} body{margin:0;padding:28px;font:14px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif;color:#2a2228;background:#fff;width:${phone ? 1500 : 2200}px}
      h1{font-size:30px;margin:0 0 6px} h2{font-size:22px;margin:30px 0 4px;padding-top:18px;border-top:2px solid #f0e4e8}
      p.lead{font-size:16px;margin:0 0 4px;max-width:1300px} p.sub{color:#6b5d64;margin:0 0 14px}
      .g6{display:grid;grid-template-columns:repeat(${phone ? 3 : 2},1fr);gap:18px}
      .row{display:grid;grid-template-columns:${phone ? '190px repeat(3,1fr)' : '210px repeat(3,1fr)'};gap:16px;align-items:start;margin-bottom:16px}
      .row.s4{grid-template-columns:repeat(4,1fr)}
      .rh{font-weight:700;font-size:17px;padding-top:30px}
      .cols{display:grid;grid-template-columns:${phone ? '190px repeat(3,1fr)' : '210px repeat(3,1fr)'};gap:16px;font-weight:700;color:#6b5d64;margin-bottom:6px}
      figure{margin:0} figure img{display:block;width:100%;height:auto;border-radius:6px;box-shadow:0 0 0 1px #eadde2}
      figcaption{font-size:16px;margin:0 0 6px;min-height:1.2em} small{display:block;color:#6b5d64;margin-top:4px;font-size:12.5px}
      .d{display:inline-block;background:#C13E63;color:#fff;border-radius:99px;padding:1px 9px;font-size:12px;font-weight:700;vertical-align:2px}
    </style></head><body>
      <h1>Category header — the options (${phone ? 'phone, 390px' : 'laptop, 1280px'})</h1>
      <p class="lead">Pick a <b>light-box style</b> (A–F) and a <b>way to keep the words readable</b> (1–5), and answer with a letter and a number, e.g. <b>“B + 3”</b>.
      Every picture here is the real shop page, drawn by the shop's own code.</p>
      <p class="sub">Shipped now: <b>A</b> for categories with no picture (dark words, nothing behind them), <b>1 · Soft shadow</b> over pictures (with the existing 40% darkening), alignment <b>Start</b>.
      Change them on Appearance → Site layout → Category header, or per category on Catalog → Categories → Edit → Category header.</p>

      <h2>A–F · The light box, for a category with no picture</h2>
      <div class="g6">${boxes}</div>

      <h2>1–5 · Keeping the title off the background</h2>
      <p class="sub">The same five on a busy dark picture, a light picture and the light box. Contrast is the title's colour against the pixels behind it (shadows not counted); 4.5:1 is the usual bar for body text, 3:1 for large text.</p>
      <div class="cols"><div></div>${GROUNDS.map(([, l]) => `<div>${esc(l)}</div>`).join('')}</div>
      ${rows}

      <h2>Alignment</h2>
      <div class="cols"><div></div><div>Busy, dark picture</div><div>Light box</div><div>Light box, Arabic</div></div>
      ${aligns}

      <h2>As shipped, English and Arabic</h2>
      ${shipped}
    </body></html>`;
}

(async () => {
    const browser = await chromium.launch({ executablePath: CHROME });
    for (const [w, file] of [[1280, 'overview.png'], [390, 'overview-phone.png']]) {
        const page = await browser.newPage({ viewport: { width: w === 390 ? 1556 : 2256, height: 1000 } });
        await page.setContent(sheet(w), { waitUntil: 'load' });
        await page.screenshot({ path: path.join(DIR, file), fullPage: true });
        await page.close();
        console.log('wrote', file);
    }
    await browser.close();
})();
