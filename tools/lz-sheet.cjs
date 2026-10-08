/* Lane LZ: filmstrip contact sheet from lz-film.cjs screencasts.
   node tools/lz-sheet.cjs FILM_DIR PROFILE PAGE_SLUG STEP_MS MAX_MS OUT.png LABEL[,LABEL] */
const fs = require('fs');
const path = require('path');
const { chromium } = require(path.join(__dirname, '..', 'node_modules', 'playwright'));
const [dir, prof, slug, step, max, out, labels] = process.argv.slice(2);
const names = { v405: '2.60.405', lzb: '2.60.443 (now)', lza: 'Lane LZ fix' };
(async () => {
  const W = prof === 'phone' ? 120 : 250;
  let html = '<body style="margin:0;padding:10px;font:12px system-ui;background:#fff"><table style="border-collapse:collapse">';
  html += '<tr><td></td>' + Array.from({ length: max / step }, (_, i) => '<td style="text-align:center;padding:2px">' + ((i + 1) * step / 1000).toFixed(2) + ' s</td>').join('') + '</tr>';
  for (const l of labels.split(',')) {
    const d = path.join(dir, l + '-' + prof + '-' + slug);
    const frames = fs.readdirSync(d).filter((f) => f.endsWith('.jpg')).map((f) => ({ t: Number(f.slice(0, 6)), f: path.join(d, f) })).sort((a, b) => a.t - b.t);
    html += '<tr><td style="padding:4px;font-weight:600;white-space:nowrap">' + (names[l] || l) + '</td>';
    for (let t = Number(step); t <= Number(max); t += Number(step)) {
      const fr = frames.filter((x) => x.t <= t).pop();
      html += '<td style="padding:2px;vertical-align:top">' + (fr ? '<img style="width:' + W + 'px;border:1px solid #ccc;display:block" src="data:image/jpeg;base64,' + fs.readFileSync(fr.f).toString('base64') + '">' : '<div style="width:' + W + 'px;height:20px;background:#eee"></div>') + '</td>';
    }
    html += '</tr>';
  }
  html += '</table></body>';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 400, height: 300 } });
  await p.setContent(html);
  await p.screenshot({ path: out, fullPage: true });
  await b.close();
})();
