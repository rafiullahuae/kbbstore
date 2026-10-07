/*
 * Lane PG2: a filmstrip sheet from screencast frames.
 *
 *   node tools/pg2g-sheet.cjs OUT.png WIDTH 'Row title=DIR@ms,ms,ms' ['Row 2=DIR@ms,...'] ...
 *
 * DIR holds frames named by their time in ms (tools/pg2g-gallery.cjs writes
 * them). For each ms the last frame at or before it is shown, captioned with
 * the time. A ms written `+N` is N ms after the tap recorded in DIR/meta.json.
 */
const path = require('path');
const fs = require('fs');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));

const [out, width, ...rows] = process.argv.slice(2);
const W = Number(width);

function pick(dir, ms) {
  const frames = fs.readdirSync(dir).filter((f) => f.endsWith('.jpg')).map((f) => [Number(f.slice(0, -4)), f]).sort((a, b) => a[0] - b[0]);
  let best = frames[0];
  for (const fr of frames) if (fr[0] <= ms) best = fr;
  return path.join(dir, best[1]);
}

(async () => {
  let html = '<body style="margin:8px;font:13px system-ui,sans-serif;background:#fff">';
  for (const row of rows) {
    const [title, spec] = row.split('=');
    const [dir, list] = spec.split('@');
    const meta = fs.existsSync(path.join(dir, 'meta.json')) ? JSON.parse(fs.readFileSync(path.join(dir, 'meta.json'), 'utf8')) : {};
    html += `<div style="margin:6px 0 2px;font-weight:700">${title}</div><div style="white-space:nowrap">`;
    for (const raw of list.split(',')) {
      const rel = raw.startsWith('+');
      const ms = rel ? meta.tapAt + Number(raw.slice(1)) : Number(raw);
      const f = pick(dir, ms);
      html += `<figure style="display:inline-block;margin:0 6px 0 0;vertical-align:top;width:${W}px"><img style="width:${W}px;border:1px solid #bbb" src="data:image/jpeg;base64,${fs.readFileSync(f).toString('base64')}"><figcaption style="text-align:center">${rel ? 'tap +' + raw.slice(1) : ms} ms</figcaption></figure>`;
    }
    html += '</div>';
  }
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 200, height: 200 } });
  await p.setContent(html);
  const size = await p.evaluate(() => [document.body.scrollWidth + 16, document.body.scrollHeight + 16]);
  await p.setViewportSize({ width: size[0], height: size[1] });
  await p.screenshot({ path: out, fullPage: true });
  await b.close();
  console.log(out, size.join('x'));
})();
